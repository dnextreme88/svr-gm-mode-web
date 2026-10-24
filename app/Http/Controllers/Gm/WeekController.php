<?php

namespace App\Http\Controllers\Gm;

use App\Exceptions\GameRuleException;
use App\Http\Controllers\Controller;
use App\Models\Save;
use App\Services\Calendar\CalendarService;
use App\Services\Week\BookingOrder;
use App\Services\Week\WeekResolver;
use App\Services\Week\WeekSimulator;
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
        ]);

        $this->assertAiring($save, $week, $resolver);
        $resolver->decide($save, (int) $data['slot_id'], isset($data['winner_side']) ? (int) $data['winner_side'] : null);

        return to_route('gm.results', [$save, $week]);
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
        $weeks = $result['weeks'];

        $summary = match (true) {
            $weeks === [] => 'No weeks were simulated.',
            count($weeks) === 1 => 'Simulated week '.$calendar->seasonWeek($weeks[0]).'.',
            default => 'Simulated weeks '.$calendar->seasonWeek($weeks[0]).'–'.$calendar->seasonWeek(end($weeks)).'.',
        };

        Inertia::flash('toast', $result['stopped'] === null
            ? ['type' => 'success', 'message' => "{$summary} Week {$calendar->seasonWeek($target)} is ready to book."]
            : ['type' => 'error', 'message' => "{$summary} Stopped: {$result['stopped']}"]);

        return to_route('gm.schedule', $save);
    }
}
