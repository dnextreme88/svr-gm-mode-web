<?php

namespace App\Http\Controllers\Gm;

use App\Enums\Brand;
use App\Exceptions\GameRuleException;
use App\Http\Controllers\Controller;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Models\PromoType;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Card\CardRandomizer;
use App\Services\Card\Proposal;
use App\Services\Promos\PromoService;
use App\Services\Week\BookingOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Randomize / Preset proposals (§24) and promo slots (§5). Randomize and
 * Preset only propose; confirming goes through the normal booking endpoint.
 */
class CardToolsController extends Controller
{
    public function __construct(
        private CardRandomizer $randomizer,
        private PromoService $promos,
        private BookingOrder $order,
    ) {}

    public function randomize(Request $request, Save $save, Show $show, int $position): JsonResponse
    {
        $brand = $this->brandFor($save, $show, $position);
        $data = $request->validate(['match_type_id' => ['nullable', 'integer', 'exists:match_types,id'], 'roll' => ['nullable', 'integer', 'min:0']]);
        $type = isset($data['match_type_id']) ? MatchType::whereKey($data['match_type_id'])->first() : null;
        $roll = (int) ($data['roll'] ?? 0);
        $titles = array_values(array_map('intval', MatchSlot::where('show_id', $show->id)->where('position', '!=', $position)->whereNotNull('title_championship_id')->pluck('title_championship_id')->all()));

        $proposal = $this->randomizer->proposeSlot(
            $show,
            $position,
            $type,
            $save->rng("randomize.{$show->streamKey()}.{$position}.{$roll}", $show->week_number),
            [],
            $titles,
            $brand,
        );

        return response()->json(['proposal' => $proposal->toArray($this->names($proposal))]);
    }

    public function preset(Request $request, Save $save, Show $show): JsonResponse
    {
        $this->assertOwnShow($save, $show);
        $roll = (int) $request->integer('roll');
        $result = $this->randomizer->preset($show, $save->rng("preset.{$show->streamKey()}.{$roll}", $show->week_number), $save->player_brand);

        return response()->json([
            'proposals' => array_map(fn (Proposal $p): array => $p->toArray($this->names($p)), $result['proposals']),
            'unfilled_slots' => $result['unfilled_slots'],
            'total_cost' => $result['total_cost'],
            'deficit_warning' => $result['deficit_warning'],
        ]);
    }

    public function bookPromo(Request $request, Save $save, Show $show, int $position): RedirectResponse
    {
        $this->assertOwnShow($save, $show);
        $this->order->assertEditable($save, $show);
        $data = $request->validate([
            'promo_type_id' => ['required', 'integer', 'exists:promo_types,id'],
            'target_wrestler_id' => ['nullable', 'integer'],
            'target_wrestler_b_id' => ['nullable', 'integer'],
            'target_championship_id' => ['nullable', 'integer'],
            'before_position' => ['nullable', 'integer'],
        ]);

        DB::transaction(fn () => $this->promos->book(
            $show,
            $position,
            PromoType::whereKey($data['promo_type_id'])->firstOrFail(),
            isset($data['target_wrestler_id']) ? (int) $data['target_wrestler_id'] : null,
            isset($data['target_wrestler_b_id']) ? (int) $data['target_wrestler_b_id'] : null,
            isset($data['target_championship_id']) ? (int) $data['target_championship_id'] : null,
            isset($data['before_position']) ? (int) $data['before_position'] : null,
        ));

        return back();
    }

    public function clearPromo(Save $save, Show $show, int $position): RedirectResponse
    {
        $this->assertOwnShow($save, $show);
        $this->order->assertEditable($save, $show);
        $this->promos->clear($show, $position);

        return back();
    }

    /**
     * @return array<int, string>
     */
    private function names(Proposal $proposal): array
    {
        return SaveWrestler::with('wrestler')
            ->whereIn('id', $proposal->booking->allIds())
            ->get()
            ->mapWithKeys(fn (SaveWrestler $w): array => [$w->id => $w->name()])
            ->all();
    }

    private function brandFor(Save $save, Show $show, int $position): Brand
    {
        $this->assertOwnShow($save, $show);
        $slot = MatchSlot::where('show_id', $show->id)->where('position', $position)->first();

        if ($slot !== null && ($slot->isFixed() || ($slot->owner_brand !== null && $slot->owner_brand !== $save->player_brand))) {
            throw new GameRuleException('That slot can’t be randomized.');
        }

        return $save->player_brand;
    }

    private function assertOwnShow(Save $save, Show $show): void
    {
        if ($show->brand !== null && $show->brand !== $save->player_brand) {
            throw new GameRuleException("That's the rival brand's card.");
        }
    }
}
