<?php

namespace Database\Seeders;

use App\Enums\MatchFamily;
use App\Models\MatchType;
use App\Models\PpvEvent;
use Illuminate\Database\Seeder;

/**
 * Seeds the full match type pool (§10).
 */
class MatchTypeSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array{0: string, 1: string, 2: list<int>, 3: list<int>, 4?: array<string, mixed>}> $rows */
        $rows = require __DIR__.'/data/match_types.php';

        foreach ($rows as $index => $row) {
            [$family, $variant, $sides, $managerSides] = $row;
            $extras = $row[4] ?? [];

            /** @var array{bars: int, cost: int, dq: bool, countout: bool} $defaults */
            $defaults = config("gm.match_type_defaults.{$family}");

            if ($extras['mitb'] ?? false) {
                $defaults = [...$defaults, ...config('gm.mitb_match')];
            }

            $ppvKey = $extras['ppv_exclusive'] ?? null;
            $count = (int) array_sum($sides);
            $rules = (array) config('gm.match_rules');
            $noTitles = in_array($family, $rules['no_titles_families'], true)
                || in_array($sides, $rules['no_titles_sides'], true);

            MatchType::updateOrCreate(
                ['family' => $family, 'variant' => $variant],
                [
                    'name' => MatchFamily::from($family)->label().' — '.$variant,
                    'sides' => $sides,
                    'manager_sides' => $managerSides,
                    'min_competitors' => $count,
                    'max_competitors' => $count,
                    'fatigue_cost_bars' => $extras['bars'] ?? ($count === 6 ? $rules['six_competitor_bars'][$family] ?? null : null) ?? $defaults['bars'],
                    'cost' => $defaults['cost'],
                    'dq_enabled' => $extras['dq'] ?? $defaults['dq'],
                    'countout_enabled' => $extras['countout'] ?? ($defaults['countout'] && ! in_array($family, $rules['no_rope_break_families'], true)),
                    'rope_breaks' => $extras['rope_breaks'] ?? ! in_array($family, $rules['no_rope_break_families'], true),
                    'ring_out' => $extras['ring_out'] ?? ! in_array($family, $rules['no_ring_out_families'], true),
                    'captain_fall' => $extras['captain_fall'] ?? false,
                    'timed' => $extras['timed'] ?? false,
                    'divas_only' => $extras['divas_only'] ?? false,
                    'divas_allowed' => $extras['divas_allowed'] ?? true,
                    'titles_allowed' => $extras['titles_allowed'] ?? ! $noTitles,
                    'is_elimination' => $extras['is_elimination'] ?? false,
                    'ppv_only' => ($extras['ppv_only'] ?? false) || $ppvKey !== null,
                    'ppv_exclusive_id' => $ppvKey === null ? null : PpvEvent::where('key', $ppvKey)->value('id'),
                    'brand_restriction' => $extras['brand_restriction'] ?? null,
                    'gm_bookable' => $extras['gm_bookable'] ?? true,
                    'win_condition' => $extras['win_condition'] ?? null,
                    'sort_order' => $index + 1,
                ],
            );
        }
    }
}
