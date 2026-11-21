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
use Carbon\CarbonImmutable;
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

        $currentDate = $this->nextNight($save, $shows, $calendar)->format('Y-m');

        return Inertia::render('gm/schedule', [
            'season' => $season,
            'seasons' => range(1, $currentSeason),
            'months' => $months,
            'initial_month' => $season === $currentSeason ? $currentDate : $months[0]['key'],
            // House rule (2026-11-16): after the re-draft, the News's Continue
            // opens the Schedule with a welcome to the new season.
            'welcome_season' => $request->boolean('welcome') && $currentSeason > 1 && $calendar->seasonWeek($save->current_week) === 1
                ? $currentSeason
                : null,
            // The guided tour (house rule, 2026-11-21): opens on its own once
            // per career, in its first week; `career` keys what this browser
            // has seen, so a rebuilt save reusing an id starts fresh.
            'tour' => [
                'career' => $save->created_at->timestamp,
                'auto' => $save->stage === SaveStage::InSeason && $save->current_week === $calendar->firstWeekOfSeason(1),
            ],
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
        $night = $shows->filter(fn (Show $s): bool => $s->week_number === $air['week']
            && $s->show_type === ($air['kind'] === 'ppv' ? ShowType::Ppv : ShowType::Weekly));
        // The brand's own card, else the one card both brands share
        // (WrestleMania, and SummerSlam since 2026-11-01).
        $show = $night->first(fn (Show $s): bool => $s->brand?->value === $brand)
            ?? ($air['kind'] === 'ppv' ? $night->first(fn (Show $s): bool => $s->brand === null) : null);

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

    /**
     * The night the calendar opens on (fix, 2026-11-17): the player's next
     * show this week, so a week that starts in one month and airs its
     * weekly show in the next (Sun Oct 30, Smackdown Nov 4) opens on the
     * month the player is about to book. A PPV night still to air comes
     * first; WrestleMania week has no weekly show.
     *
     * @param  Collection<int, Show>  $shows
     */
    private function nextNight(Save $save, Collection $shows, CalendarService $calendar): CarbonImmutable
    {
        $week = $save->current_week;
        $ppvToAir = $shows->contains(fn (Show $show): bool => $show->week_number === $week
            && $show->show_type === ShowType::Ppv
            && ($show->brand === null || $show->brand === $save->player_brand)
            && $show->status !== ShowStatus::Aired);
        $weekly = $shows->contains(fn (Show $show): bool => $show->week_number === $week
            && $show->show_type === ShowType::Weekly
            && $show->brand === $save->player_brand);

        return $ppvToAir || ! $weekly ? $calendar->date($week) : $calendar->showDate($week, $save->player_brand);
    }
}
