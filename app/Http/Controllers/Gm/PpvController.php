<?php

namespace App\Http\Controllers\Gm;

use App\Enums\FixedSlotKind;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BookingOrderPresenter;
use App\Http\Presenters\WrestlerPresenter;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Card\CardRandomizer;
use App\Services\Ppv\PpvCardService;
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
            // Divas are listed but greyed out: neither PPV takes them (house rule).
            'pool' => $this->randomizer->pool($show, $brand)
                ->sortBy(fn (SaveWrestler $w): int => $w->wrestler->isDiva() ? 1 : 0)
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
}
