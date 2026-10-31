<?php

namespace App\Services\Attendance;

use App\Enums\Brand;
use App\Enums\PpvKey;
use App\Enums\ShowType;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\Show;

/**
 * House rule (2026-11-01): SummerSlam is one card both brands book. The
 * brand whose weekly shows drew more fans this season, up to SummerSlam
 * week, gets the main event: it books Match 1, Match 3 and the main event,
 * the other brand Matches 2 and 4 (`PpvCardService::setUp`). A tie goes to
 * the higher total draw score, then a seeded coin flip.
 */
class SummerSlamMainEvent
{
    /**
     * The brand with the main event of the SummerSlam held in `$week`.
     */
    public function headliner(Save $save, int $season, int $week): Brand
    {
        $totals = $this->totals($save, $season, $week);
        [$raw, $smackdown] = [$totals[Brand::Raw->value], $totals[Brand::Smackdown->value]];

        if ($raw['crowd'] !== $smackdown['crowd']) {
            return $raw['crowd'] > $smackdown['crowd'] ? Brand::Raw : Brand::Smackdown;
        }

        if ($raw['score'] !== $smackdown['score']) {
            return $raw['score'] > $smackdown['score'] ? Brand::Raw : Brand::Smackdown;
        }

        return $save->rng('summerslam.headliner', $week)->chance(0.5) ? Brand::Raw : Brand::Smackdown;
    }

    /**
     * The brand that owns a SummerSlam card's main event slot, fixed when
     * the card was set up; null for any other card.
     */
    public function cardHeadliner(Show $show): ?Brand
    {
        if ($show->ppvKey() !== PpvKey::Summerslam) {
            return null;
        }

        return MatchSlot::where('show_id', $show->id)->orderByDesc('position')->first()?->owner_brand;
    }

    /**
     * Each brand's weekly-show crowds and draw scores this season, before `$week`.
     *
     * @return array<string, array{crowd: int, score: int}>
     */
    public function totals(Save $save, int $season, int $week): array
    {
        $totals = [Brand::Raw->value => ['crowd' => 0, 'score' => 0], Brand::Smackdown->value => ['crowd' => 0, 'score' => 0]];

        $shows = Show::where('save_id', $save->id)
            ->where('season', $season)
            ->where('show_type', ShowType::Weekly)
            ->where('week_number', '<', $week)
            ->whereNotNull('attendance')
            ->get();

        foreach ($shows as $show) {
            if ($show->brand === null) {
                continue;
            }

            $totals[$show->brand->value]['crowd'] += (int) $show->attendance;
            $totals[$show->brand->value]['score'] += (int) ($show->attendance_breakdown['score'] ?? 0);
        }

        return $totals;
    }
}
