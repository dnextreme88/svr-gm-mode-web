<?php

namespace App\Http\Controllers\Gm;

use App\Http\Controllers\Controller;
use App\Http\Presenters\CardPresenter;
use App\Models\Email;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\Show;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The match outcome screen after a week airs (§20): each result flashes in
 * card order, weekly show first, then the PPV.
 */
class ResultsController extends Controller
{
    public function __invoke(Save $save, int $week): Response
    {
        $shows = Show::with('ppvEvent')
            ->where('save_id', $save->id)
            ->where('week_number', $week)
            ->where(fn ($q) => $q->where('brand', $save->player_brand)->orWhereNull('brand'))
            ->get()
            ->sortBy(fn (Show $s): int => $s->isPpv() ? 1 : 0)
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

        return Inertia::render('gm/results', [
            'week_number' => $week,
            'shows' => $shows,
            'new_emails' => Email::where('save_id', $save->id)->where('brand', $save->player_brand)->where('week_number', $week)->count(),
        ]);
    }
}
