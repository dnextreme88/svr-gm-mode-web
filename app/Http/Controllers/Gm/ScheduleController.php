<?php

namespace App\Http\Controllers\Gm;

use App\Http\Controllers\Controller;
use App\Models\Save;
use App\Models\Show;
use App\Services\Calendar\CalendarService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Monthly calendar of shows and PPVs (§3 Schedule). `?season=N` shows an
 * earlier season, with links to each aired week's results.
 */
class ScheduleController extends Controller
{
    public function __invoke(Request $request, Save $save, CalendarService $calendar): Response
    {
        $currentSeason = $calendar->seasonOf($save->current_week);
        $season = $request->integer('season', $currentSeason);

        abort_if($season < 1 || $season > $currentSeason, 404);

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
            'season' => $season,
            'seasons' => range(1, $currentSeason),
            'months' => $calendar->seasonGrid($season, $save->player_brand),
            'shows' => $shows,
        ]);
    }
}
