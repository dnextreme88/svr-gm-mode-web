<?php

namespace App\Http\Controllers\Gm;

use App\Exceptions\GameRuleException;
use App\Http\Controllers\Controller;
use App\Models\Save;
use App\Services\Calendar\CalendarService;
use App\Services\Week\BookingOrder;
use App\Services\Week\WeekResolver;
use App\Services\Week\WeekSimulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Airs the current week's shows (§2 week resolution), or simulates ahead.
 */
class WeekController extends Controller
{
    /**
     * Start airing the week live, then decide the player's matches on the
     * results screen. A week already airing just reopens its results.
     */
    public function advance(Save $save, WeekResolver $resolver, BookingOrder $order): RedirectResponse
    {
        $week = $save->current_week;

        if ($resolver->airing($save) === null) {
            $order->assertReadyToAir($save, $week);
            $resolver->begin($save);
        }

        return to_route('gm.results', [$save, $week]);
    }

    /**
     * Air the next match: simulated, or won by the booked side (Book to Win).
     */
    public function decide(Request $request, Save $save, int $week, WeekResolver $resolver): RedirectResponse
    {
        $data = $request->validate([
            'slot_id' => ['required', 'integer'],
            'winner_side' => ['nullable', 'integer', 'min:0'],
            // Gauntlet: the side booked to win each bout, null to simulate.
            'bouts' => ['nullable', 'array', 'max:5'],
            'bouts.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->assertAiring($save, $week, $resolver);
        $resolver->decide(
            $save,
            (int) $data['slot_id'],
            isset($data['winner_side']) ? (int) $data['winner_side'] : null,
            array_values(array_map(fn (mixed $side): ?int => $side === null ? null : (int) $side, (array) ($data['bouts'] ?? []))),
        );

        return to_route('gm.results', [$save, $week]);
    }

    /**
     * House rule: cash in the briefcase during the match that is up next.
     */
    public function cashInDuring(Request $request, Save $save, int $week, WeekResolver $resolver): RedirectResponse
    {
        $data = $request->validate(['slot_id' => ['required', 'integer']]);
        $resolver->cashInDuring($save, (int) $data['slot_id']);

        return back();
    }

    /**
     * House rule: answer the cash-in offered after a world title match.
     */
    public function answerCashIn(Request $request, Save $save, int $week, WeekResolver $resolver): RedirectResponse
    {
        $data = $request->validate(['cash_in' => ['required', 'boolean']]);
        $resolver->answerCashIn($save, (bool) $data['cash_in']);

        return back();
    }

    /**
     * Simulate every match left this week.
     */
    public function simulateAll(Save $save, int $week, WeekResolver $resolver): RedirectResponse
    {
        $this->assertAiring($save, $week, $resolver);
        $resolver->simulateAll($save);

        return to_route('gm.results', [$save, $week]);
    }

    private function assertAiring(Save $save, int $week, WeekResolver $resolver): void
    {
        if ($week !== $save->current_week || $resolver->airing($save) === null) {
            throw new GameRuleException('This week is not airing.');
        }
    }

    /**
     * Simulate every week before the target, leaving the target week to book.
     */
    public function simulate(Request $request, Save $save, WeekSimulator $simulator, CalendarService $calendar): RedirectResponse
    {
        $target = (int) $request->validate(['week' => ['required', 'integer']])['week'];
        $result = $simulator->simulateTo($save, $target);

        $this->flashSimulation($calendar, $result['weeks'], $target, $result['stopped']);

        return to_route('gm.schedule', $save);
    }

    /**
     * One week of Simulate to here (JSON), so the schedule can show
     * progress: the week it aired, whether it's done, and why it stopped.
     */
    public function simulateStep(Request $request, Save $save, WeekSimulator $simulator, CalendarService $calendar): JsonResponse
    {
        $target = (int) $request->validate(['week' => ['required', 'integer']])['week'];

        if ($save->current_week < $target) {
            $simulator->assertCanSimulateTo($save, $target);
        }

        $step = $simulator->simulateNext($save, $target);

        return response()->json([
            ...$step,
            'season_week' => $step['week'] === null ? null : $calendar->seasonWeek($step['week']),
        ]);
    }

    /**
     * After a stepped simulation: the same summary toast as `simulate`, back
     * on the schedule.
     */
    public function simulateFinish(Request $request, Save $save, CalendarService $calendar): RedirectResponse
    {
        $data = $request->validate([
            'week' => ['required', 'integer'],
            'weeks' => ['array'],
            'weeks.*' => ['integer'],
            'stopped' => ['nullable', 'string', 'max:500'],
        ]);

        $this->flashSimulation($calendar, array_values(array_map('intval', $data['weeks'] ?? [])), (int) $data['week'], $data['stopped'] ?? null);

        return to_route('gm.schedule', $save);
    }

    /**
     * @param  list<int>  $weeks  the absolute weeks that aired
     */
    private function flashSimulation(CalendarService $calendar, array $weeks, int $target, ?string $stopped): void
    {
        $summary = match (true) {
            $weeks === [] => 'No weeks were simulated.',
            count($weeks) === 1 => 'Simulated week '.$calendar->seasonWeek($weeks[0]).'.',
            default => 'Simulated weeks '.$calendar->seasonWeek($weeks[0]).'–'.$calendar->seasonWeek(end($weeks)).'.',
        };

        Inertia::flash('toast', $stopped === null
            ? ['type' => 'success', 'message' => "{$summary} Week {$calendar->seasonWeek($target)} is ready to book."]
            : ['type' => 'error', 'message' => "{$summary} Stopped: {$stopped}"]);
    }
}
