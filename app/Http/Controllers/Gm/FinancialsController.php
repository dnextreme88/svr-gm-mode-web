<?php

namespace App\Http\Controllers\Gm;

use App\Enums\Brand;
use App\Http\Controllers\Controller;
use App\Http\Support\WrapUp;
use App\Models\Save;
use App\Services\Finance\FinancialReport;
use App\Services\Week\Steps\TrackDeficit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Weekly income statement (§3 Financials, §7). `?brand=` shows either brand's
 * books (house rule, 2026-11-13): the rival's are read-only. `?week=` (an
 * absolute week) and `?season=` open any past week of either brand.
 */
class FinancialsController extends Controller
{
    public function index(Request $request, Save $save, FinancialReport $report): Response
    {
        $brand = Brand::tryFrom((string) $request->query('brand')) ?? $save->player_brand;
        $history = $report->history($save, $brand);
        $latest = $history === [] ? $save->current_week : end($history)['week_number'];
        $season = $request->integer('season');

        // A season on its own opens on that season's last recorded week.
        if ($season > 0 && $request->integer('week') === 0) {
            $inSeason = array_values(array_filter($history, fn (array $h): bool => $h['season'] === $season));
            $latest = $inSeason === [] ? $latest : end($inSeason)['week_number'];
        }

        // House rule (2026-11-17): from the results' Continue, the week that
        // just aired (a signing can already put a line on the next week).
        // Either brand: the rival's books for the same week keep the header
        // hidden too (fix, 2026-11-21).
        $wrapUp = WrapUp::active($request, $save);
        $week = $request->integer('week') ?: ($wrapUp ? $save->current_week - 1 : $latest);

        return Inertia::render('gm/financials', [
            // House rule (2026-11-17): the week just aired, opened from the
            // results' Continue: the header stays hidden and Continue opens
            // the News. Any other week offers Back to Schedule.
            'wrap_up' => $wrapUp && $week === $save->current_week - 1,
            'brand' => $brand->value,
            'balance' => $save->brand($brand)->budget,
            'statement' => $report->forWeek($save, $brand, $week),
            'history' => $history,
            'deficit_choices' => TrackDeficit::choices(),
        ]);
    }

    public function choose(Request $request, Save $save): RedirectResponse
    {
        $data = $request->validate(['choice' => ['required', Rule::in(TrackDeficit::choices())]]);

        $save->brand($save->player_brand)->update(['deficit_choice' => $data['choice']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Recovery plan noted. The next show can air.']);

        return back();
    }
}
