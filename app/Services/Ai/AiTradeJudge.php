<?php

namespace App\Services\Ai;

use App\Enums\Brand;
use App\Enums\FactionType;
use App\Models\Championship;
use App\Models\Save;
use App\Models\SaveFaction;
use App\Models\SaveWrestler;
use App\Models\TitleReign;
use App\Services\Injuries\InjuryStatus;
use App\Support\Rng\Rng;
use Illuminate\Support\Collection;

/**
 * How the rival GM answers a trade offer (house rule, 2026-10-31,
 * `gm.ai.trades`). Each wrestler is worth points from the rival's point of
 * view (see `breakdown`). An offer is fair when the rival gets at least what
 * it gives and can still book every title it could; a fair offer is usually
 * accepted, more so the further ahead the rival comes out, anything else
 * rarely. Only that last roll is luck, so the Trade
 * screen shows the chance and the points before the offer is sent.
 */
class AiTradeJudge
{
    /** Breakdown categories, in display order. */
    public const CATEGORIES = [
        'base', 'legend', 'focus', 'specialty', 'minor_injury', 'win_streak',
        'loss_streak', 'tag_partner', 'full_tag_team', 'full_stable',
        'world_champion', 'repeat_champion',
    ];

    public function __construct(private InjuryStatus $injuries) {}

    /**
     * @param  list<int>  $outIds  what the proposer gives (the rival gets)
     * @param  list<int>  $inIds  what the proposer asks for (the rival gives)
     */
    public function accepts(Save $save, Brand $proposer, array $outIds, array $inIds, Rng $rng): bool
    {
        return $rng->chance($this->chance($save, $proposer, $outIds, $inIds));
    }

    /**
     * The chance the rival accepts this offer.
     *
     * @param  list<int>  $outIds
     * @param  list<int>  $inIds
     */
    public function chance(Save $save, Brand $proposer, array $outIds, array $inIds): float
    {
        return $this->assess($save, $proposer, $outIds, $inIds)['chance'];
    }

    /**
     * The whole judgement: the points each side is worth to the rival, by
     * category, whether that's fair, and the chance it accepts.
     *
     * @param  list<int>  $outIds  what the proposer gives (the rival gets)
     * @param  list<int>  $inIds  what the proposer asks for (the rival gives)
     * @return array{chance: float, fair: bool, gets: array{total: int, lines: array<string, int>}, gives: array{total: int, lines: array<string, int>}}
     */
    public function assess(Save $save, Brand $proposer, array $outIds, array $inIds): array
    {
        $rival = $proposer === Brand::Raw ? Brand::Smackdown : Brand::Raw;
        $gets = $this->breakdown($save, $rival, $outIds);
        $gives = $this->breakdown($save, $rival, $inIds);
        $fair = array_sum($gets) >= (float) config('gm.ai.trades.fair_ratio') * array_sum($gives)
            && ! $this->breaksATitle($save, $rival, $outIds, $inIds);

        return [
            'chance' => $fair ? $this->fairChance(array_sum($gets) - array_sum($gives)) : (float) config('gm.ai.trades.unfair_accept'),
            'fair' => $fair,
            'gets' => ['total' => array_sum($gets), 'lines' => $gets],
            'gives' => ['total' => array_sum($gives), 'lines' => $gives],
        ];
    }

    /**
     * A fair offer's chance: the even-points rate, raised by how many points
     * the rival comes out ahead (the highest band reached counts).
     */
    private function fairChance(int $ahead): float
    {
        $chance = (float) config('gm.ai.trades.fair_accept');

        foreach ((array) config('gm.ai.trades.fair_ahead_accept') as $from => $value) {
            if ($ahead >= (int) $from) {
                $chance = (float) $value;
            }
        }

        return $chance;
    }

    /**
     * @param  list<int>  $ids
     */
    public function value(Save $save, Brand $rival, array $ids): int
    {
        return array_sum($this->breakdown($save, $rival, $ids));
    }

    /**
     * What these wrestlers are worth to the rival, summed by category (only
     * the categories that apply), in CATEGORIES order.
     *
     * @param  list<int>  $ids
     * @return array<string, int>
     */
    public function breakdown(Save $save, Brand $rival, array $ids): array
    {
        $c = (array) config('gm.ai.trades');
        $week = $save->current_week;
        $wrestlers = SaveWrestler::with('wrestler.specialties')->where('save_id', $save->id)->whereIn('id', $ids)->get();
        $reigns = TitleReign::with('championship')->where('save_id', $save->id)->get()->reject(fn (TitleReign $r): bool => $r->isVacant());
        $partners = $this->tagPartnerIds($save, $rival, $week);
        $totals = array_fill_keys(self::CATEGORIES, 0);

        foreach ($wrestlers as $w) {
            $held = $reigns->filter(fn (TitleReign $r): bool => in_array($w->id, array_map('intval', $r->holderIds()), true));
            $worldReigns = $held->filter(fn (TitleReign $r): bool => $r->championship->is_world && $r->championship->brand === $w->brand)->count();
            $repeat = $held->reject(fn (TitleReign $r): bool => $r->championship->is_world)
                ->countBy('championship_id')
                ->contains(fn (int $n): bool => $n >= (int) $c['repeat_title_reigns']);

            $totals['base'] += (int) $c['base_value'];
            $totals['legend'] += $w->wrestler->isLegend() ? (int) $c['legend_value'] : 0;
            $totals['focus'] += AiGm::isDraftFocus($rival, $w) ? (int) $c['focus_value'] : 0;
            $totals['specialty'] += $w->wrestler->specialties->isNotEmpty() ? (int) $c['specialty_value'] : 0;
            $totals['minor_injury'] += $this->injuries->isMinorInjured($w, $week) ? (int) $c['minor_injury_value'] : 0;
            $totals['win_streak'] += intdiv($w->win_streak, max(1, (int) $c['win_streak_step'])) * (int) $c['win_streak_value'];
            $totals['loss_streak'] += $w->loss_streak >= (int) $c['loss_streak_from'] ? (int) $c['loss_streak_value'] : 0;
            $totals['tag_partner'] += in_array($w->id, $partners, true) ? (int) $c['tag_partner_value'] : 0;
            $totals['world_champion'] += $this->worldReignPoints($worldReigns);
            $totals['repeat_champion'] += $repeat ? (int) $c['repeat_title_value'] : 0;
        }

        // Whole factions traded together: every current member on this side.
        foreach ($this->completeFactions($save, $ids, $week) as $faction) {
            $totals[$faction->faction_type === FactionType::TagTeam ? 'full_tag_team' : 'full_stable'] += (int) ($faction->faction_type === FactionType::TagTeam ? $c['full_tag_team_value'] : $c['full_stable_value']);
        }

        return array_filter($totals, fn (int $points): bool => $points !== 0);
    }

    /**
     * Points for reigns with the wrestler's own brand's world title: the
     * highest band reached (1–4 reigns, 5–7, 8+ by default).
     */
    private function worldReignPoints(int $reigns): int
    {
        $points = 0;

        foreach ((array) config('gm.ai.trades.world_reign_bands') as $from => $value) {
            if ($reigns >= (int) $from) {
                $points = (int) $value;
            }
        }

        return $points;
    }

    /**
     * Active factions whose every current member is in `$ids` (a tag team or
     * stable traded whole, like APA's Faarooq and JBL).
     *
     * @param  list<int>  $ids
     * @return Collection<int, SaveFaction>
     */
    private function completeFactions(Save $save, array $ids, int $week): Collection
    {
        if (count($ids) < 2) {
            return collect();
        }

        return SaveFaction::with('members')
            ->where('save_id', $save->id)
            ->get()
            ->filter(fn (SaveFaction $f): bool => $f->isActiveAt($week)
                && $f->members->count() >= 2
                && array_diff($f->memberIds(), $ids) === [])
            ->values();
    }

    /**
     * Wrestlers with a tag team partner on the rival's roster: the rival looks
     * over its own roster, notes who's in an active tag team (not a stable),
     * and values anyone who'd team with one of them (Jeff Hardy offered to the
     * rival that has Matt Hardy). It counts once however many teams match.
     *
     * @return list<int>
     */
    private function tagPartnerIds(Save $save, Brand $rival, int $week): array
    {
        $ids = [];

        $teams = SaveFaction::with('members')
            ->where('save_id', $save->id)
            ->where('faction_type', FactionType::TagTeam)
            ->get()
            ->filter(fn (SaveFaction $f): bool => $f->isActiveAt($week));

        foreach ($teams as $team) {
            foreach ($team->members as $member) {
                $hasPartner = $team->members->contains(
                    fn (SaveWrestler $other): bool => $other->id !== $member->id && $other->brand === $rival,
                );

                if ($hasPartner) {
                    $ids[] = $member->id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Whether the trade leaves the rival unable to book a title match it
     * could book before (a champion and a challenger, or two teams).
     *
     * @param  list<int>  $outIds
     * @param  list<int>  $inIds
     */
    private function breaksATitle(Save $save, Brand $rival, array $outIds, array $inIds): bool
    {
        $before = SaveWrestler::with('wrestler')->where('save_id', $save->id)->where('brand', $rival)->get();
        $arriving = SaveWrestler::with('wrestler')->whereIn('id', $outIds)->get();
        $after = $before->reject(fn (SaveWrestler $w): bool => in_array($w->id, $inIds, true))->concat($arriving);

        return Championship::where('brand', $rival)->get()
            ->contains(fn (Championship $c): bool => $this->fillable($c, $before->toBase()) && ! $this->fillable($c, $after->toBase()));
    }

    /**
     * @param  Collection<int, SaveWrestler>  $people
     */
    private function fillable(Championship $championship, Collection $people): bool
    {
        return $people->filter(fn (SaveWrestler $w): bool => $championship->allowsWrestler($w->wrestler))->count() >= ($championship->is_tag ? 4 : 2);
    }
}
