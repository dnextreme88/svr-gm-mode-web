<?php

namespace App\Http\Controllers\Gm;

use App\Enums\TradeStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\WrestlerPresenter;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Trade;
use App\Models\TradeItem;
use App\Services\Ai\AiTradeJudge;
use App\Services\Injuries\InjuryStatus;
use App\Services\Roster\RosterService;
use App\Services\Roster\TradeService;
use App\Services\Titles\TitleService;
use App\Services\Week\WeekResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Propose Trade: up to 5 of yours for up to 5 of theirs (§8).
 */
class TradeController extends Controller
{
    public function __construct(
        private TradeService $trades,
        private RosterService $roster,
        private InjuryStatus $injuries,
        private TitleService $titles,
    ) {}

    public function show(Save $save): Response
    {
        $week = $save->current_week;
        $injuries = $this->injuries->activeByWrestler($save->id, $week);
        $reigns = $this->titles->currentReigns($save);

        $present = fn ($brand) => $this->roster->roster($save, $brand)->map(fn (SaveWrestler $w): array => [
            ...WrestlerPresenter::row($w, $week, $injuries, $reigns),
            'untradeable' => $this->trades->untradeableReason($w, $week),
        ])->values();

        return Inertia::render('gm/trade', [
            'yours' => $present($save->player_brand),
            'theirs' => $present($save->rivalBrand()),
            'roster_counts' => [
                $save->player_brand->value => $this->roster->rosterCount($save, $save->player_brand),
                $save->rivalBrand()->value => $this->roster->rosterCount($save, $save->rivalBrand()),
            ],
            'max_per_side' => (int) config('gm.trades.max_per_side'),
            // How the rival GM judges an offer (AiTradeJudge), shown as the page's rules.
            'judge' => (array) config('gm.ai.trades'),
            'history' => $this->trades->history($save)->map(fn (Trade $t): array => [
                'id' => $t->id,
                'week_number' => $t->week_number,
                'status' => $t->status->value,
                'out' => $t->items->where('direction', 'out')->map(fn (TradeItem $i): string => $i->saveWrestler->name())->values(),
                'in' => $t->items->where('direction', 'in')->map(fn (TradeItem $i): string => $i->saveWrestler->name())->values(),
            ])->values(),
        ]);
    }

    /**
     * The rival GM's judgement of the offer being built: its chance of
     * accepting and what each side is worth to it (the offer bar).
     */
    public function odds(Request $request, Save $save, AiTradeJudge $judge): JsonResponse
    {
        $data = $request->validate([
            'out' => ['array'],
            'out.*' => ['integer'],
            'in' => ['array'],
            'in.*' => ['integer'],
        ]);

        return response()->json($judge->assess(
            $save,
            $save->player_brand,
            array_values(array_map('intval', $data['out'] ?? [])),
            array_values(array_map('intval', $data['in'] ?? [])),
        ));
    }

    public function store(Request $request, Save $save): RedirectResponse
    {
        app(WeekResolver::class)->assertNotAiring($save);

        $data = $request->validate([
            'out' => ['array'],
            'out.*' => ['integer'],
            'in' => ['array'],
            'in.*' => ['integer'],
        ]);

        $trade = DB::transaction(fn () => $this->trades->propose(
            $save,
            $save->player_brand,
            array_values(array_map('intval', $data['out'] ?? [])),
            array_values(array_map('intval', $data['in'] ?? [])),
        ));

        Inertia::flash('toast', $trade->status === TradeStatus::Accepted
            ? ['type' => 'success', 'message' => "{$save->rivalBrand()->label()} accepted the trade!"]
            : ['type' => 'error', 'message' => "{$save->rivalBrand()->label()} turned the offer down."]);

        return back();
    }
}
