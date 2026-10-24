<?php

namespace App\Http\Controllers\Gm;

use App\Enums\SaveStage;
use App\Enums\ShowStatus;
use App\Enums\ShowType;
use App\Http\Controllers\Controller;
use App\Models\Save;
use App\Models\Show;
use App\Services\Calendar\CalendarService;
use App\Services\Week\BookingOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The season as a month-by-month calendar (§3 Schedule), weeks starting on
 * Sunday. Every show night of both brands is on it: an aired show opens its
 * results (the rival's too), this week's cards open for booking in date
 * order, and a future date can be simulated to. `?season=N` shows an
 * earlier season.
 */
class ScheduleController extends Controller
{
    public function __invoke(Request $request, Save $save, CalendarService $calendar, BookingOrder $order): Response
    {
        $currentSeason = $calendar->seasonOf($save->current_week);
        $season = $request->integer('season', $currentSeason);

        abort_if($season < 1 || $season > $currentSeason, 404);

        $shows = Show::with('ppvEvent')->where('save_id', $save->id)->where('season', $season)->get();
        $steps = $order->steps($save, $save->current_week);
        $canSimulate = $season === $currentSeason && $save->stage === SaveStage::InSeason;

        $months = array_map(fn (array $month): array => [
            ...$month,
            'rows' => array_map(fn (array $row): array => array_map(fn (array $day): array => [
                ...$day,
                'airs' => array_map(fn (array $air): array => $this->decorate($air, $save, $shows, $steps, $canSimulate), $day['airs']),
            ], $row), $month['rows']),
        ], $calendar->seasonMonths($season));

        $currentDate = $calendar->date($save->current_week)->format('Y-m');

        return Inertia::render('gm/schedule', [
            'season' => $season,
            'seasons' => range(1, $currentSeason),
            'months' => $months,
            'initial_month' => $season === $currentSeason ? $currentDate : $months[0]['key'],
        ]);
    }

    /**
     * What a show night shows and does for the player.
     *
     * @param  array{week: int, season_week: int, kind: string, brand: string|null, ppv: array{key: string, name: string, brand_scope: string}|null}  $air
     * @param  Collection<int, Show>  $shows
     * @param  Collection<int, Show>  $steps  the player's cards this week, in booking order
     * @return array<string, mixed>
     */
    private function decorate(array $air, Save $save, Collection $shows, Collection $steps, bool $canSimulate): array
    {
        $player = $save->player_brand->value;
        $mine = $air['brand'] === null || $air['brand'] === $player;
        // A shared PPV opens the player's own card for it (WrestleMania is one card).
        $brand = $air['brand'] ?? $player;
        $show = $shows->first(fn (Show $s): bool => $s->week_number === $air['week']
            && $s->show_type === ($air['kind'] === 'ppv' ? ShowType::Ppv : ShowType::Weekly)
            && ($s->brand?->value === $brand || ($s->brand === null && $air['ppv']['key'] === 'wrestlemania')));

        $state = match (true) {
            // A show airing live opens its results, where its matches are decided.
            in_array($show?->status, [ShowStatus::Aired, ShowStatus::Airing], true) => 'aired',
            $air['week'] < $save->current_week => 'past',
            $air['week'] > $save->current_week => 'upcoming',
            ! $mine => 'rival',
            default => $this->stepState($show, $steps),
        };

        return [
            ...$air,
            'name' => $air['ppv']['name'] ?? ($brand === 'raw' ? 'Monday Night Raw' : 'Friday Night Smackdown'),
            'mine' => $mine,
            'show_id' => $show?->id,
            'state' => $state,
            'results_url' => $state === 'aired' && $show !== null
                ? route('gm.results', ['save' => $save, 'week' => $air['week'], 'brand' => $brand, 'show' => $show->id])
                : null,
            'card_url' => in_array($state, ['open', 'confirmed', 'waiting'], true) && $show !== null
                ? route('gm.card.show', [$save, $show])
                : null,
            // Only the player's own show nights (their weekly show, their
            // brand's PPVs and shared PPVs) are simulation targets.
            'can_simulate' => $canSimulate && $mine && $state === 'upcoming',
        ];
    }

    /**
     * Where one of the player's cards this week stands in the booking order.
     *
     * @param  Collection<int, Show>  $steps
     */
    private function stepState(?Show $show, Collection $steps): string
    {
        if ($show === null) {
            return 'past';
        }

        if ($show->confirmed_at !== null) {
            return 'confirmed';
        }

        foreach ($steps as $step) {
            if ($step->is($show)) {
                return 'open';
            }

            if ($step->confirmed_at === null) {
                return 'waiting';
            }
        }

        return 'open';
    }
}
