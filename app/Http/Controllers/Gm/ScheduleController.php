<?php

namespace App\Http\Controllers\Gm;

use App\Http\Controllers\Controller;
use App\Models\Save;
use App\Models\Show;
use App\Services\Calendar\CalendarService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Monthly calendar of shows and PPVs (§3 Schedule).
 */
class ScheduleController extends Controller
{
    public function __invoke(Save $save, CalendarService $calendar): Response
    {
        $season = $calendar->seasonOf($save->current_week);

        $shows = Show::with('ppvEvent')
            ->where('save_id', $save->id)
            ->where('season', $season)
            ->where(fn ($q) => $q->where('brand', $save->player_brand)->orWhereNull('brand'))
            ->get()
            ->groupBy('week_number')
            ->map(fn ($group) => $group->map(fn (Show $show): array => [
                'id' => $show->id,
                'name' => $show->name,
                'show_type' => $show->show_type->value,
                'status' => $show->status->value,
            ])->values());

        return Inertia::render('gm/schedule', [
            'months' => $calendar->seasonGrid($season),
            'shows' => $shows,
        ]);
    }
}
