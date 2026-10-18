<?php

namespace App\Http\Controllers\Gm;

use App\Http\Controllers\Controller;
use App\Models\Save;
use App\Services\Finance\FinancialReport;
use App\Services\Week\Steps\TrackDeficit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Weekly income statement (§3 Financials, §7).
 */
class FinancialsController extends Controller
{
    public function index(Request $request, Save $save, FinancialReport $report): Response
    {
        $history = $report->history($save, $save->player_brand);
        $latest = $history === [] ? $save->current_week : end($history)['week_number'];
        $week = $request->integer('week') ?: $latest;

        return Inertia::render('gm/financials', [
            'statement' => $report->forWeek($save, $save->player_brand, $week),
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
