<?php

namespace App\Http\Controllers\Gm;

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
    public function advance(Save $save, WeekResolver $resolver, BookingOrder $order): RedirectResponse
    {
        $order->assertReadyToAir($save, $save->current_week);

        $resolution = $resolver->resolve($save);

        return to_route('gm.results', [$save, $resolution->week_number]);
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
