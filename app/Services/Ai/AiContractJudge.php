<?php

namespace App\Services\Ai;

use App\Enums\ContractLength;
use App\Enums\InjurySeverity;
use App\Enums\ParticipantRole;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\TitleReign;
use App\Services\Titles\TitleService;
use Illuminate\Support\Facades\DB;

/**
 * How the rival GM judges an expiring contract (house rule, 2026-11-17),
 * like a human GM would: on how the superstar did during this contract.
 * The first rule that applies decides (numbers in `gm.ai.roster.renewal`):
 *
 *  1. A champion is always re-signed.
 *  2. A losing record (more than half their decided matches lost) lets
 *     the contract expire.
 *  3. Enough title wins for the contract's length: likely re-signed.
 *  4. Complaining most of the time (an open morale complaint): likely let go.
 *  5. Booked most weeks: likely re-signed.
 *  6. Rarely booked: likely let go.
 *  7. Anyone else: likely re-signed.
 *
 * A re-sign is 6 or 3 months at even odds.
 */
class AiContractJudge
{
    public const RULE_CHAMPION = 'champion';

    public const RULE_LOSING = 'losing_record';

    public const RULE_TITLES = 'title_wins';

    public const RULE_COMPLAINS = 'complains';

    public const RULE_BUSY = 'busy';

    public const RULE_IDLE = 'idle';

    public const RULE_DEFAULT = 'default';

    public function __construct(private TitleService $titles) {}

    /**
     * The length to re-sign for, or null to let the contract expire.
     */
    public function decide(Save $save, SaveWrestler $wrestler, int $week): ?ContractLength
    {
        $standing = $this->standing($save, $wrestler, $week);
        $config = (array) config('gm.ai.roster.renewal');
        $rng = $save->rng("ai.renew.{$wrestler->brand?->value}.{$wrestler->wrestler->slug}", $week);

        $keep = match ($standing['rule']) {
            self::RULE_CHAMPION => true,
            self::RULE_LOSING => false,
            self::RULE_TITLES => $rng->chance((float) $config['title_wins_keep']),
            self::RULE_COMPLAINS => ! $rng->chance((float) $config['complaint_expire']),
            self::RULE_BUSY => $rng->chance((float) $config['busy_keep']),
            self::RULE_IDLE => ! $rng->chance((float) $config['idle_expire']),
            default => $rng->chance((float) $config['default_keep']),
        };

        if (! $keep) {
            return null;
        }

        /** @var list<int> $lengths */
        $lengths = array_map('intval', (array) $config['lengths']);

        return ContractLength::from((int) $rng->pick($lengths));
    }

    /**
     * How the superstar did during this contract, and which rule decides.
     *
     * @return array{rule: string, wins: int, losses: int, title_wins: int, weeks: int, booked_weeks: int, complaint_weeks: int}
     */
    public function standing(Save $save, SaveWrestler $wrestler, int $week): array
    {
        $config = (array) config('gm.ai.roster.renewal');
        $contract = $wrestler->activeContract;
        $from = $contract === null ? $week : min($contract->starts_week, $week);
        $length = $contract === null ? null : ContractLength::tryFrom($contract->length_weeks);

        [$wins, $losses] = $this->record($save, $wrestler, $from, $week);
        $titleWins = $this->titleWins($save, $wrestler, $from, $week);
        $away = $this->weeksSeriouslyInjured($save, $wrestler, $from, $week);
        $weeks = max(0, $week - $from + 1 - $away);
        $booked = $this->bookedWeeks($save, $wrestler, $from, $week);
        $complaining = $this->complaintWeeks($save, $wrestler, $from, $week);
        $all = $week - $from + 1;
        $neededTitles = $length === null ? null : ($config['title_wins'][$length->value] ?? null);

        $rule = match (true) {
            $this->titles->isChampion($wrestler) => self::RULE_CHAMPION,
            $wins + $losses > 0 && $losses / ($wins + $losses) > (float) $config['losing_share'] => self::RULE_LOSING,
            $neededTitles !== null && $titleWins >= (int) $neededTitles => self::RULE_TITLES,
            $all > 0 && $complaining / $all > (float) $config['complaint_share'] => self::RULE_COMPLAINS,
            $weeks > 0 && $booked / $weeks >= (float) $config['busy_share'] => self::RULE_BUSY,
            $weeks > 0 && $booked / $weeks <= (float) $config['idle_share'] => self::RULE_IDLE,
            default => self::RULE_DEFAULT,
        };

        return [
            'rule' => $rule,
            'wins' => $wins,
            'losses' => $losses,
            'title_wins' => $titleWins,
            'weeks' => $weeks,
            'booked_weeks' => $booked,
            'complaint_weeks' => $complaining,
        ];
    }

    /**
     * Decided matches they competed in during the window.
     *
     * @return array{0: int, 1: int}
     */
    private function record(Save $save, SaveWrestler $wrestler, int $from, int $to): array
    {
        $results = DB::table('match_participants')
            ->join('match_slots', 'match_slots.id', '=', 'match_participants.match_slot_id')
            ->join('shows', 'shows.id', '=', 'match_slots.show_id')
            ->where('shows.save_id', $save->id)
            ->whereBetween('shows.week_number', [$from, $to])
            ->where('match_participants.save_wrestler_id', $wrestler->id)
            ->where('match_participants.role', ParticipantRole::Competitor->value)
            ->whereNotNull('match_participants.result')
            ->pluck('match_participants.result');

        return [$results->filter(fn ($r): bool => $r === 'win')->count(), $results->filter(fn ($r): bool => $r === 'loss')->count()];
    }

    /**
     * Titles they won during the window, tag titles included.
     */
    private function titleWins(Save $save, SaveWrestler $wrestler, int $from, int $to): int
    {
        return TitleReign::where('save_id', $save->id)
            ->whereBetween('won_week', [$from, $to])
            ->get()
            ->filter(fn (TitleReign $reign): bool => ! $reign->isVacant() && in_array($wrestler->id, $reign->holderIds(), true))
            ->count();
    }

    /**
     * Weeks in the window they were booked in a match (competing or
     * managing) or a promo.
     */
    private function bookedWeeks(Save $save, SaveWrestler $wrestler, int $from, int $to): int
    {
        $matches = DB::table('match_participants')
            ->join('match_slots', 'match_slots.id', '=', 'match_participants.match_slot_id')
            ->join('shows', 'shows.id', '=', 'match_slots.show_id')
            ->where('shows.save_id', $save->id)
            ->whereBetween('shows.week_number', [$from, $to])
            ->where('match_participants.save_wrestler_id', $wrestler->id)
            ->whereIn('match_participants.role', [ParticipantRole::Competitor->value, ParticipantRole::Manager->value])
            ->pluck('shows.week_number');

        $promos = DB::table('promo_slots')
            ->join('shows', 'shows.id', '=', 'promo_slots.show_id')
            ->where('shows.save_id', $save->id)
            ->whereBetween('shows.week_number', [$from, $to])
            ->whereNotNull('promo_slots.promo_type_id')
            ->where(fn ($q) => $q->where('promo_slots.target_wrestler_id', $wrestler->id)->orWhere('promo_slots.target_wrestler_b_id', $wrestler->id))
            ->pluck('shows.week_number');

        return $matches->merge($promos)->map(fn ($w): int => (int) $w)->unique()->count();
    }

    /**
     * Weeks in the window a serious injury kept them out (they can't be
     * booked, so those weeks don't count against them).
     */
    private function weeksSeriouslyInjured(Save $save, SaveWrestler $wrestler, int $from, int $to): int
    {
        $weeks = [];

        $injuries = DB::table('injuries')
            ->where('save_id', $save->id)
            ->where('save_wrestler_id', $wrestler->id)
            ->where('severity', InjurySeverity::Serious->value)
            ->where('started_week', '<=', $to)
            ->get(['started_week', 'ends_week', 'healed_week']);

        foreach ($injuries as $injury) {
            $end = min((int) ($injury->healed_week ?? $injury->ends_week), $to);

            for ($w = max((int) $injury->started_week + 1, $from); $w <= $end; $w++) {
                $weeks[$w] = true;
            }
        }

        return count($weeks);
    }

    /**
     * Weeks in the window with a morale complaint open.
     */
    private function complaintWeeks(Save $save, SaveWrestler $wrestler, int $from, int $to): int
    {
        $weeks = [];

        $complaints = DB::table('morale_complaints')
            ->where('save_id', $save->id)
            ->where('save_wrestler_id', $wrestler->id)
            ->where('opened_week', '<=', $to)
            ->get(['opened_week', 'resolved_week']);

        foreach ($complaints as $complaint) {
            $end = $complaint->resolved_week === null ? $to : min((int) $complaint->resolved_week - 1, $to);

            for ($w = max((int) $complaint->opened_week, $from); $w <= $end; $w++) {
                $weeks[$w] = true;
            }
        }

        return count($weeks);
    }
}
