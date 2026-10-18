<?php

namespace App\Http\Controllers\Gm;

use App\Enums\Brand;
use App\Enums\ContractStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\WrestlerPresenter;
use App\Models\Contract;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\TitleReign;
use App\Services\Contracts\ContractPricing;
use App\Services\Injuries\InjuryStatus;
use App\Services\Roster\RosterService;
use App\Services\Titles\TitleService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Roster view and wrestler detail with contract negotiation (§3 Rosters).
 */
class RosterController extends Controller
{
    public function __construct(
        private RosterService $roster,
        private InjuryStatus $injuries,
        private TitleService $titles,
        private ContractPricing $pricing,
    ) {}

    public function index(Request $request, Save $save): Response
    {
        $request->validate(['brand' => ['nullable', Rule::enum(Brand::class)]]);
        $brand = $request->filled('brand') ? Brand::from((string) $request->string('brand')) : $save->player_brand;
        $week = $save->current_week;
        $injuries = $this->injuries->activeByWrestler($save->id, $week);
        $reigns = $this->titles->currentReigns($save);

        return Inertia::render('gm/rosters/index', [
            'brand' => $brand->value,
            'wrestlers' => $this->roster->roster($save, $brand)
                ->map(fn (SaveWrestler $w): array => WrestlerPresenter::row($w, $week, $injuries, $reigns))
                ->values(),
            'pending' => $this->roster->pendingCount($save, $brand),
        ]);
    }

    public function show(Save $save, SaveWrestler $saveWrestler): Response
    {
        $week = $save->current_week;
        $saveWrestler->load(['wrestler.specialties', 'activeContract']);

        $history = TitleReign::with('championship')
            ->where('save_id', $save->id)
            ->where('holder_type', '!=', 'vacant')
            ->orderBy('won_week')
            ->get()
            ->filter(fn (TitleReign $r): bool => in_array($saveWrestler->id, $r->holderIds(), true))
            ->map(fn (TitleReign $r): array => [
                'championship' => $r->championship->name,
                'brand' => $r->brand->value,
                'reign_number' => $r->reign_number,
                'won_week' => $r->won_week,
                'lost_week' => $r->lost_week,
                'weeks_held' => $r->weeksHeld($week),
                'defenses' => $r->defenses,
                'end_reason' => $r->end_reason->value,
                'holders' => $r->holderName(),
            ])
            ->values();

        $contracts = Contract::where('save_wrestler_id', $saveWrestler->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Contract $c): array => [
                'brand' => $c->brand->value,
                'length_weeks' => $c->length_weeks,
                'weekly_cost' => $c->weekly_cost,
                'upfront_fee' => $c->upfront_fee,
                'signed_week' => $c->signed_week,
                'weeks_remaining' => $c->weeks_remaining,
                'status' => $c->status->value,
                'active' => $c->status === ContractStatus::Active,
            ]);

        return Inertia::render('gm/rosters/show', [
            'wrestler' => WrestlerPresenter::row($saveWrestler, $week, $this->injuries->activeByWrestler($save->id, $week), $this->titles->currentReigns($save)),
            'complaints' => $saveWrestler->activeComplaints()->get()->map(fn ($c): array => [
                'type' => $c->type->value,
                'level' => $c->level->value,
                'opened_week' => $c->opened_week,
            ])->values(),
            'title_history' => $history,
            'contracts' => $contracts,
            'quotes' => $this->pricing->allQuotes($saveWrestler->wrestler),
            'is_own' => $saveWrestler->brand === $save->player_brand,
            'is_free_agent' => $saveWrestler->brand === null,
            'is_pending' => $this->roster->isPending($saveWrestler),
        ]);
    }
}
