<?php

namespace App\Http\Controllers\Gm;

use App\Enums\SaveStage;
use App\Enums\ShowType;
use App\Http\Controllers\Controller;
use App\Http\Support\WrapUp;
use App\Models\Save;
use App\Models\Show;
use App\Services\Calendar\CalendarService;
use App\Services\News\NewsComposer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The News carousel: one composed page per week, newest first (§19).
 */
class NewsController extends Controller
{
    public function __invoke(Request $request, Save $save, NewsComposer $composer, CalendarService $calendar): Response|RedirectResponse
    {
        // House rule (2026-11-15): WrestleMania week's news is written when the
        // re-draft ends, so the re-draft comes first (the results' Continue
        // lands here).
        if ($save->stage === SaveStage::Redrafting) {
            return to_route('gm.redraft.show', $save);
        }

        $next = $this->continueTo($save, $composer, $calendar);
        // House rule (2026-11-17): the week just aired, reached from its
        // Financials: the header stays hidden and Continue opens the inbox.
        $wrapUp = WrapUp::active($request, $save) && $next['url'] === route('gm.email', $save);

        return Inertia::render('gm/news', [
            'pages' => $composer->pages($save),
            'continue_to' => $wrapUp ? [...$next, 'url' => route('gm.email', ['save' => $save, 'wrap' => 1])] : $next,
            'wrap_up' => $wrapUp,
        ]);
    }

    /**
     * Where Continue goes (house rule, 2026-11-16): after a PPV night the
     * player booked, to their weekly show to book; after the re-draft, to
     * the Schedule, which welcomes the new season; otherwise the inbox.
     *
     * @return array{name: string, url: string}
     */
    private function continueTo(Save $save, NewsComposer $composer, CalendarService $calendar): array
    {
        if ($composer->ppvNightNewsFrom($save) !== null) {
            $show = Show::where('save_id', $save->id)
                ->where('week_number', $save->current_week)
                ->where('brand', $save->player_brand)
                ->where('show_type', ShowType::Weekly)
                ->first();

            if ($show !== null) {
                return ['name' => $show->name, 'url' => route('gm.card.show', [$save, $show])];
            }
        }

        if ($save->current_week > 1 && $calendar->seasonWeek($save->current_week) === 1 && $calendar->isWrestleManiaWeek($save->current_week - 1)) {
            return ['name' => 'Season '.$calendar->seasonOf($save->current_week), 'url' => route('gm.schedule', ['save' => $save, 'welcome' => 1])];
        }

        return ['name' => 'Inbox', 'url' => route('gm.email', $save)];
    }
}
