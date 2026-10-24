<?php

namespace App\Services\Rivalries;

use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\ParticipantRole;
use App\Enums\ShowStatus;
use App\Models\MatchSlot;
use App\Models\Rivalry;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\News\NewsRecorder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Rivalries and their heat (§3 Rivalry, §19). ASSUMPTION: the spec leaves
 * heat undefined; the rules here are config-driven placeholders.
 */
class RivalryService
{
    public function __construct(private NewsRecorder $news) {}

    /**
     * @return Collection<int, Rivalry>
     */
    public function active(Save $save): Collection
    {
        return Rivalry::with(['wrestlerA.wrestler', 'wrestlerB.wrestler'])
            ->where('save_id', $save->id)
            ->where('active', true)
            ->orderByDesc('heat')
            ->get();
    }

    /**
     * The two wrestler ids, the alphabetically first name first.
     *
     * @return array{0: int, 1: int}
     */
    public function alphabetical(int $a, int $b): array
    {
        $names = SaveWrestler::with('wrestler')->whereIn('id', [$a, $b])->get()->mapWithKeys(fn (SaveWrestler $w): array => [$w->id => $w->name()]);

        return strcasecmp((string) $names->get($a), (string) $names->get($b)) <= 0 ? [$a, $b] : [$b, $a];
    }

    public function between(Save $save, int $a, int $b): ?Rivalry
    {
        return Rivalry::where('save_id', $save->id)
            ->where('active', true)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('wrestler_a_id', $a)->where('wrestler_b_id', $b))
                ->orWhere(fn ($q) => $q->where('wrestler_a_id', $b)->where('wrestler_b_id', $a)))
            ->first();
    }

    /**
     * Start a rivalry (Start a Rivalry promo, or repeated meetings).
     */
    public function start(Save $save, int $a, int $b, int $week): Rivalry
    {
        $existing = $this->between($save, $a, $b);

        if ($existing !== null) {
            return $existing;
        }

        // House rule: a rivalry is named alphabetically ("Jeff Hardy vs. Rob
        // Van Dam"), so the first wrestler is the one whose name sorts first.
        [$first, $second] = $this->alphabetical($a, $b);

        $rivalry = Rivalry::create([
            'save_id' => $save->id,
            'wrestler_a_id' => $first,
            'wrestler_b_id' => $second,
            'heat' => (int) config('gm.rivalries.start_heat'),
            'started_week' => $week,
            'last_activity_week' => $week,
        ])->load(['wrestlerA.wrestler', 'wrestlerB.wrestler']);

        $this->news->record($save, NewsEventType::RivalryStarted, NewsTier::Story, $this->brandOf($rivalry), [
            'wrestler_a' => $rivalry->wrestlerA->name(),
            'wrestler_b' => $rivalry->wrestlerB->name(),
        ], ['wrestler_ids' => [$a, $b]], priority: 25, week: $week);

        return $rivalry;
    }

    /**
     * Add heat (a meeting or a Continue Rivalry promo).
     */
    public function heatUp(Rivalry $rivalry, int $amount, int $week): void
    {
        $threshold = (int) config('gm.rivalries.heating_up_threshold');
        $before = $rivalry->heat;
        $after = min((int) config('gm.rivalries.max_heat'), $before + $amount);

        $rivalry->update(['heat' => $after, 'last_activity_week' => $week]);

        if ($before < $threshold && $after >= $threshold) {
            $rivalry->loadMissing(['wrestlerA.wrestler', 'wrestlerB.wrestler']);
            $this->news->record($rivalry->game, NewsEventType::RivalryHeatingUp, NewsTier::Story, $this->brandOf($rivalry), [
                'wrestler_a' => $rivalry->wrestlerA->name(),
                'wrestler_b' => $rivalry->wrestlerB->name(),
            ], ['wrestler_ids' => [$rivalry->wrestler_a_id, $rivalry->wrestler_b_id]], priority: 30, week: $week);
        }
    }

    /**
     * Record singles-style opponents meeting in a match. Creates a rivalry after
     * enough recent meetings and ends a hot one with a PPV blowoff.
     */
    public function recordMeeting(Save $save, MatchSlot $slot, int $winnerId, int $loserId, int $week, bool $atPpv): void
    {
        $rivalry = $this->between($save, $winnerId, $loserId);

        if ($rivalry === null) {
            $meetings = $this->recentMeetings($save, $winnerId, $loserId, $week) + 1;

            if ($meetings >= (int) config('gm.rivalries.auto_create_meetings')) {
                $this->start($save, $winnerId, $loserId, $week);
            }

            return;
        }

        if ($atPpv && $rivalry->heat >= (int) config('gm.rivalries.blowoff_min_heat')) {
            $rivalry->update(['active' => false, 'ended_week' => $week, 'last_activity_week' => $week]);
            $winner = SaveWrestler::with('wrestler')->findOrFail($winnerId);
            $loser = SaveWrestler::with('wrestler')->findOrFail($loserId);

            $this->news->record($save, NewsEventType::RivalryBlowoff, NewsTier::Headline, $this->brandOf($rivalry), [
                'winner' => $winner->name(),
                'loser' => $loser->name(),
                'ppv' => $slot->show->name,
            ], ['wrestler_ids' => [$winnerId, $loserId]], priority: 70, week: $week, dedupeKey: "slot:{$slot->id}");

            return;
        }

        $this->heatUp($rivalry, (int) config('gm.rivalries.heat_per_meeting'), $week);
    }

    /**
     * Idle rivalries cool off; at 0 heat they end.
     */
    public function weeklyDecay(Save $save, int $week): void
    {
        foreach (Rivalry::where('save_id', $save->id)->where('active', true)->get() as $rivalry) {
            $rivalry->weeks_active++;

            if ($rivalry->last_activity_week < $week) {
                $rivalry->heat = max(0, $rivalry->heat - (int) config('gm.rivalries.idle_decay'));
            }

            if ($rivalry->heat === 0) {
                $rivalry->active = false;
                $rivalry->ended_week = $week;
            }

            $rivalry->save();

            // ASSUMPTION: heat stands in for fan feedback until Popularity exists.
            if ($rivalry->active && $rivalry->weeks_active === (int) config('gm.news.rivalry_milestone_weeks') && $rivalry->heat >= (int) config('gm.rivalries.heating_up_threshold')) {
                $rivalry->loadMissing(['wrestlerA.wrestler', 'wrestlerB.wrestler']);
                $this->news->record($save, NewsEventType::RivalryMilestone, NewsTier::Headline, $this->brandOf($rivalry), [
                    'wrestler_a' => $rivalry->wrestlerA->name(),
                    'wrestler_b' => $rivalry->wrestlerB->name(),
                    'weeks' => $rivalry->weeks_active,
                ], ['wrestler_ids' => [$rivalry->wrestler_a_id, $rivalry->wrestler_b_id]], priority: 60, week: $week);
            }
        }
    }

    private function recentMeetings(Save $save, int $a, int $b, int $week): int
    {
        $window = (int) config('gm.rivalries.auto_create_window_weeks');

        return MatchSlot::query()
            ->whereHas('show', fn ($q) => $q->where('save_id', $save->id)->where('status', ShowStatus::Aired)->where('week_number', '>', $week - $window))
            ->whereHas('participants', fn ($q) => $q->where('save_wrestler_id', $a)->where('role', ParticipantRole::Competitor))
            ->whereHas('participants', fn ($q) => $q->where('save_wrestler_id', $b)->where('role', ParticipantRole::Competitor))
            ->count();
    }

    private function brandOf(Rivalry $rivalry): NewsBrand
    {
        $rivalry->loadMissing(['wrestlerA', 'wrestlerB']);
        $a = $rivalry->wrestlerA->brand;
        $b = $rivalry->wrestlerB->brand;

        return $a !== null && $a === $b ? NewsBrand::from($a->value) : NewsBrand::Both;
    }
}
