<?php

namespace App\Http\Controllers\Gm;

use App\Enums\DraftPhase;
use App\Enums\FixedSlotKind;
use App\Enums\SaveStage;
use App\Exceptions\GameRuleException;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BookingOrderPresenter;
use App\Http\Presenters\WrestlerPresenter;
use App\Models\DraftPick;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Card\CardRandomizer;
use App\Services\Ppv\PpvCardService;
use App\Services\Ppv\ReDraftService;
use App\Services\Week\BookingOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Royal Rumble entrants, the Survivor Series team, and the post-WrestleMania
 * re-draft (§10, §13, §23).
 */
class PpvController extends Controller
{
    public function __construct(
        private PpvCardService $ppvCards,
        private CardRandomizer $randomizer,
        private ReDraftService $redraft,
        private BookingOrder $order,
    ) {}

    public function entrants(Save $save, Show $show): Response
    {
        $slot = $this->ppvCards->mainEventSlot($show);
        $rumble = $slot->fixed_kind === FixedSlotKind::RoyalRumble;
        $brand = $save->player_brand;
        $week = $show->week_number;
        $chosen = $slot->participants()->with('saveWrestler.wrestler')->get();

        return Inertia::render('gm/ppv-entrants', [
            'show' => ['id' => $show->id, 'name' => $show->name, 'status' => $show->status->value],
            'booking_order' => BookingOrderPresenter::for($save, $show, $this->order),
            'mode' => $rumble ? 'rumble' : 'survivor_series',
            'needed' => $rumble ? $this->ppvCards->rumbleEntrantsFor($show, $brand) : (int) config('gm.ppv.survivor_series_team_size'),
            'pool' => $this->randomizer->pool($show, $brand)
                ->when(! $rumble, fn ($pool) => $pool->reject(fn (SaveWrestler $w): bool => $w->wrestler->isDiva()))
                ->map(fn (SaveWrestler $w): array => WrestlerPresenter::row($w->load('wrestler.specialties'), $week))
                ->values(),
            'selected' => $chosen->filter(fn ($p): bool => $p->saveWrestler->brand === $brand)->pluck('save_wrestler_id')->values(),
            'rival' => $chosen->filter(fn ($p): bool => $p->saveWrestler->brand !== $brand)->map(fn ($p): string => $p->saveWrestler->name())->values(),
        ]);
    }

    public function storeEntrants(Request $request, Save $save, Show $show): RedirectResponse
    {
        $this->order->assertEditable($save, $show);

        if ($request->boolean('random')) {
            DB::transaction(fn () => $this->ppvCards->randomEntrants($show, $save->player_brand, $save->rng("entrants.{$show->streamKey()}.{$save->player_brand->value}")));
        } else {
            $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
            DB::transaction(fn () => $this->ppvCards->setEntrants($show, $save->player_brand, array_values(array_map('intval', $data['ids']))));
        }

        return back();
    }

    public function redraft(Save $save): Response|RedirectResponse
    {
        if ($save->stage !== SaveStage::Redrafting) {
            $last = DraftPick::with('saveWrestler.wrestler')
                ->where('save_id', $save->id)
                ->where('phase', DraftPhase::WrestlemaniaReset)
                ->orderBy('pick_number')
                ->get();

            if ($last->isEmpty()) {
                return to_route('gm.schedule', $save);
            }
        }

        $series = $this->redraft->series($save);
        $winner = $series?->winner_brand;
        $rival = $save->rivalBrand();
        $week = $save->current_week;
        $present = fn ($rows) => $rows->map(fn (SaveWrestler $w): array => WrestlerPresenter::row($w, $week))->values();

        return Inertia::render('gm/redraft', [
            'in_progress' => $save->stage === SaveStage::Redrafting,
            'series' => $series === null ? null : [
                'raw_wins' => $series->raw_wins,
                'smackdown_wins' => $series->smackdown_wins,
                'winner_brand' => $winner?->value,
                'exemption_wrestler_id' => $series->exemption_wrestler_id,
                'handpick_wrestler_id' => $series->handpick_wrestler_id,
            ],
            'you_won' => $winner === $save->player_brand,
            'own_eligible' => $present($this->redraft->eligibleFrom($save, $save->player_brand)),
            'rival_eligible' => $present($this->redraft->eligibleFrom($save, $rival)),
            'max_picks' => (int) config('gm.ppv.redraft_max_picks'),
            'picks' => DraftPick::with('saveWrestler.wrestler')
                ->where('save_id', $save->id)
                ->where('phase', DraftPhase::WrestlemaniaReset)
                ->where('season', $save->current_season)
                ->orderBy('pick_number')
                ->get()
                ->map(fn (DraftPick $p): array => [
                    'pick_number' => $p->pick_number,
                    'brand' => $p->brand->value,
                    'name' => $p->saveWrestler?->name(),
                    'pick_type' => $p->pick_type->value,
                    'skipped' => $p->skipped,
                ])->values(),
        ]);
    }

    public function setTags(Request $request, Save $save): RedirectResponse
    {
        $data = $request->validate([
            'exemption_wrestler_id' => ['nullable', 'integer'],
            'handpick_wrestler_id' => ['nullable', 'integer'],
        ]);

        if ($this->redraft->series($save)?->winner_brand !== $save->player_brand) {
            throw new GameRuleException('Only the brand battle winner sets the re-draft tags.');
        }

        $this->redraft->setTags($save, $data['exemption_wrestler_id'] ?? null, $data['handpick_wrestler_id'] ?? null);

        return back();
    }

    public function runRedraft(Save $save): RedirectResponse
    {
        DB::transaction(fn () => $this->redraft->run($save));

        return to_route('gm.redraft.show', $save);
    }
}
