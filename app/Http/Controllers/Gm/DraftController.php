<?php

namespace App\Http\Controllers\Gm;

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Enums\DraftPhase;
use App\Enums\SaveStage;
use App\Exceptions\GameRuleException;
use App\Http\Controllers\Controller;
use App\Http\Presenters\WrestlerPresenter;
use App\Models\DraftPick;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Contracts\ContractPricing;
use App\Services\Draft\DraftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The initial draft room (§1).
 */
class DraftController extends Controller
{
    public function __construct(
        private DraftService $draft,
        private ContractPricing $pricing,
    ) {}

    public function show(Save $save): Response|RedirectResponse
    {
        if ($save->stage !== SaveStage::Drafting) {
            return to_route('gm.saves.show', $save);
        }

        $week = $save->current_week;
        $length = ContractLength::from((int) config('gm.contracts.draft_default_length'));

        $pool = SaveWrestler::with('wrestler.specialties')
            ->where('save_id', $save->id)
            ->whereNull('brand')
            ->get()
            ->sortBy(fn (SaveWrestler $w): string => $w->wrestler->name)
            ->map(fn (SaveWrestler $w): array => [
                ...WrestlerPresenter::row($w, $week),
                'quote' => $this->pricing->quote($w->wrestler, $length),
            ])
            ->values();

        $picks = DraftPick::with('saveWrestler.wrestler')
            ->where('save_id', $save->id)
            ->where('season', $save->current_season)
            ->where('phase', DraftPhase::Initial)
            ->orderBy('pick_number')
            ->get()
            ->map(fn (DraftPick $p): array => [
                'pick_number' => $p->pick_number,
                'brand' => $p->brand->value,
                'name' => $p->saveWrestler?->wrestler->name,
                'roster_type' => $p->saveWrestler?->wrestler->roster_type->value,
                'pick_type' => $p->pick_type->value,
            ]);

        return Inertia::render('gm/draft', [
            'pool' => $pool,
            'picks' => $picks,
            'next_brand' => $this->draft->nextBrand($save)?->value,
            'max_picks' => $this->draft->maxPicks(),
            'picks_made' => [
                'raw' => $this->draft->picksMade($save, Brand::Raw),
                'smackdown' => $this->draft->picksMade($save, Brand::Smackdown),
            ],
            'draft_order' => $save->draft_order,
            'contract_length' => $length->value,
        ]);
    }

    public function pick(Request $request, Save $save): RedirectResponse
    {
        $data = $request->validate(['save_wrestler_id' => ['required', 'integer']]);
        $wrestler = SaveWrestler::where('save_id', $save->id)->whereKey($data['save_wrestler_id'])->firstOrFail();

        DB::transaction(function () use ($save, $wrestler): void {
            $this->draft->pick($save, $save->player_brand, $wrestler);
            $this->draft->runAi($save);
        });

        return to_route('gm.saves.show', $save);
    }

    public function finish(Save $save): RedirectResponse
    {
        if ($save->stage !== SaveStage::Drafting) {
            throw new GameRuleException('The draft is already over.');
        }

        DB::transaction(function () use ($save): void {
            $this->draft->finish($save, $save->player_brand);
            $this->draft->runAi($save);
        });

        return to_route('gm.saves.show', $save);
    }
}
