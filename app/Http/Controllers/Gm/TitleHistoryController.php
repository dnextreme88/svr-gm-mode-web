<?php

namespace App\Http\Controllers\Gm;

use App\Enums\TitleHolderType;
use App\Http\Controllers\Controller;
use App\Models\Championship;
use App\Models\MatchSlot;
use App\Models\MoneyInTheBank;
use App\Models\Save;
use App\Models\TitleReign;
use App\Services\Calendar\CalendarService;
use App\Services\Titles\MitbService;
use App\Services\Titles\TitleRecords;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lineage of all 8 championships, the Hall of Champions and records (§16).
 */
class TitleHistoryController extends Controller
{
    public function __invoke(Save $save, TitleRecords $records, CalendarService $calendar, MitbService $mitb): Response
    {
        $week = $save->current_week;
        $championships = Championship::orderBy('sort_order')->get();

        $reigns = TitleReign::with(['wonShow', 'lostShow'])
            ->where('save_id', $save->id)
            ->orderBy('id')
            ->get()
            ->groupBy('championship_id');

        $matchTypes = MatchSlot::with('matchType')
            ->whereIn('id', $reigns->flatten()->pluck('won_match_id')->filter()->all())
            ->get()
            ->mapWithKeys(fn ($slot): array => [$slot->id => $slot->matchType?->name]);

        $titles = $championships->map(function (Championship $c) use ($reigns, $week, $calendar, $matchTypes): array {
            $lineage = ($reigns->get($c->id) ?? collect())->map(fn (TitleReign $r): array => [
                'id' => $r->id,
                'reign_number' => $r->reign_number,
                'holder' => $r->holderName(),
                'holder_type' => $r->holder_type->value,
                'won_week' => $r->won_week,
                'won_season' => $r->season,
                'won_season_week' => $calendar->seasonWeek($r->won_week),
                'won_show' => $r->wonShow?->name,
                'won_match_type' => $r->won_match_id === null ? null : $matchTypes->get($r->won_match_id),
                'won_via' => $r->won_via?->value,
                'lost_week' => $r->lost_week,
                'lost_season_week' => $r->lost_week === null ? null : $calendar->seasonWeek($r->lost_week),
                'weeks_held' => $r->weeksHeld($week),
                'defenses' => $r->defenses,
                'end_reason' => $r->end_reason->value,
                'active' => $r->lost_week === null,
            ])->values();

            $current = $lineage->last();

            return [
                'id' => $c->id,
                'key' => $c->key->value,
                'name' => $c->name,
                'brand' => $c->brand->value,
                'is_tag' => $c->is_tag,
                'current' => $current !== null && $current['holder_type'] !== TitleHolderType::Vacant->value ? $current : null,
                'vacant' => $current === null || $current['holder_type'] === TitleHolderType::Vacant->value,
                'lineage' => $lineage->reverse()->values(),
            ];
        });

        return Inertia::render('gm/titles/history', [
            'titles' => $titles,
            'hall' => $titles->groupBy('brand')->map(fn ($group) => $group->map(fn (array $t): array => [
                'name' => $t['name'],
                'holder' => $t['current']['holder'] ?? null,
                'weeks_held' => $t['current']['weeks_held'] ?? null,
                'defenses' => $t['current']['defenses'] ?? null,
            ])->values()),
            'records' => [
                'overall' => $records->records($save),
                'per_title' => $championships->mapWithKeys(fn (Championship $c): array => [$c->id => $records->records($save, $c)]),
            ],
            'briefcases' => $mitb->held($save)->map(fn (MoneyInTheBank $b): array => [
                'holder' => $b->holder->name(),
                'brand' => $b->brand->value,
                'expires_week' => $b->expires_week,
            ])->values(),
        ]);
    }
}
