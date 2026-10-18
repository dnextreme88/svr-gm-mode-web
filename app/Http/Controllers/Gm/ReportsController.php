<?php

namespace App\Http\Controllers\Gm;

use App\Http\Controllers\Controller;
use App\Http\Presenters\CardPresenter;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\Show;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Match-by-match results (§3 Reports). Star ratings and fan support come
 * from the deferred Match Rating and Popularity systems, so they show as empty.
 */
class ReportsController extends Controller
{
    public function __invoke(Save $save): Response
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
                'outcomes' => MatchSlot::with(['matchType', 'participants.saveWrestler.wrestler', 'result', 'titleChampionship'])
                    ->where('show_id', $show->id)
                    ->whereHas('result')
                    ->orderBy('position')
                    ->get()
                    ->map(fn (MatchSlot $slot): array => CardPresenter::outcome($slot))
                    ->values(),
            ])
            ->values();

        return Inertia::render('gm/reports', ['shows' => $shows]);
    }
}
