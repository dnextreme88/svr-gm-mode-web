<?php

namespace App\Http\Controllers\Gm;

use App\Enums\Brand;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CardPresenter;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\Show;
use App\Services\Attendance\AttendanceNights;
use App\Services\Calendar\CalendarService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Match-by-match results (§3 Reports), under a fan attendance chart (house
 * rule, 2026-11-01) of every aired night, both brands. Star ratings and fan
 * support come from the deferred Match Rating and Popularity systems, so
 * they show as empty.
 */
class ReportsController extends Controller
{
    public function __invoke(Save $save, AttendanceNights $nights, CalendarService $calendar): Response
    {
        $shows = Show::with('ppvEvent')
            ->where('save_id', $save->id)
            ->where('status', 'aired')
            ->where(fn ($q) => $q->where('brand', $save->player_brand)->orWhereNull('brand'))
            ->orderByDesc('week_number')
            ->orderByDesc('show_type')
            ->limit(30)
            ->get()
            ->map(fn (Show $show): array => [
                ...CardPresenter::show($show),
                'outcomes' => MatchSlot::with(['matchType', 'participants.saveWrestler.wrestler', 'result', 'titleChampionship', 'gauntletBouts'])
                    ->where('show_id', $show->id)
                    ->whereHas('result')
                    ->orderBy('position')
                    ->get()
                    ->map(fn (MatchSlot $slot): array => CardPresenter::outcome($slot))
                    ->values(),
            ])
            ->values();

        $attendance = array_map(fn (array $n): array => [
            'season' => $calendar->seasonOf($n['week']),
            'week' => $calendar->seasonWeek($n['week']),
            'name' => $n['name'],
            'ppv' => $n['ppv'],
            'brands' => array_map(fn (Brand $b): string => $b->value, $n['brands']),
            'crowd' => $n['crowd'],
            'gate' => $n['gate'],
        ], $nights->group(Show::with('ppvEvent')
            ->where('save_id', $save->id)
            ->whereNotNull('attendance')
            ->orderBy('week_number')
            ->orderBy('id')
            ->get()
            ->toBase()));

        return Inertia::render('gm/reports', ['shows' => $shows, 'attendance' => $attendance]);
    }
}
