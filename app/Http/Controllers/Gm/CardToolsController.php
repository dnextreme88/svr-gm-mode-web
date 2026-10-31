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
use App\Services\Card\Booking;
use App\Services\Card\CardRandomizer;
use App\Services\Card\CardService;
use App\Services\Card\Proposal;
use App\Services\Promos\PromoService;
use App\Services\Week\BookingOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
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
        private CardService $cards,
    ) {}

    public function randomize(Request $request, Save $save, Show $show, int $position): JsonResponse
    {
        $brand = $this->brandFor($save, $show, $position);
        $data = $request->validate(['match_type_id' => ['nullable', 'integer', 'exists:match_types,id'], 'roll' => ['nullable', 'integer', 'min:0']]);
        $type = isset($data['match_type_id']) ? MatchType::whereKey($data['match_type_id'])->first() : null;
        $roll = (int) ($data['roll'] ?? 0);
        $titles = array_values(MatchSlot::where('show_id', $show->id)->where('position', '!=', $position)->get()
            ->flatMap(fn (MatchSlot $slot): array => array_filter([$slot->title_championship_id, ...($slot->contested_title_ids ?? [])]))
            ->map(fn ($id): int => (int) $id)
            ->all());

        // An earned title shot this card still needs comes first, like Preset.
        $owed = $this->randomizer->owedTitleShot($show, $position, $brand);

        if ($owed !== null) {
            return response()->json(['proposal' => $owed->toArray($this->names($owed))]);
        }

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
        // The Preset card button rebooks every slot it may touch, not just the empty ones.
        $result = $this->randomizer->preset($show, $save->rng("preset.{$show->streamKey()}.{$roll}", $show->week_number), $save->player_brand, replace: true);

        return response()->json([
            'proposals' => array_map(fn (Proposal $p): array => $p->toArray($this->names($p)), $result['proposals']),
            'unfilled_slots' => $result['unfilled_slots'],
            'total_cost' => $result['total_cost'],
            'deficit_warning' => $result['deficit_warning'],
        ]);
    }

    /**
     * Confirm a Preset: every proposed slot at once, in one transaction.
     * The slots are cleared first, so a title or wrestler moving from one
     * slot to another can't trip over its old booking, and a refusal
     * leaves the card as it was.
     */
    public function applyPreset(Request $request, Save $save, Show $show): HttpResponse
    {
        $this->assertOwnShow($save, $show);
        $this->order->assertEditable($save, $show);
        $data = $request->validate([
            'proposals' => ['required', 'array', 'min:1'],
            'proposals.*.position' => ['required', 'integer', 'min:1'],
            'proposals.*.match_type_id' => ['required', 'integer', 'exists:match_types,id'],
            'proposals.*.sides' => ['required', 'array', 'min:2'],
            'proposals.*.sides.*' => ['array', 'min:1'],
            'proposals.*.sides.*.*' => ['integer'],
            'proposals.*.managers' => ['nullable', 'array'],
            'proposals.*.managers.*' => ['nullable', 'integer'],
            'proposals.*.title_championship_id' => ['nullable', 'integer', 'exists:championships,id'],
            'proposals.*.referee_id' => ['nullable', 'integer'],
        ]);

        DB::transaction(function () use ($data, $save, $show): void {
            foreach ($data['proposals'] as $proposal) {
                $this->brandFor($save, $show, (int) $proposal['position']);
                $this->cards->clear($show, (int) $proposal['position'], $save->player_brand);
            }

            foreach ($data['proposals'] as $proposal) {
                $this->cards->book($show, (int) $proposal['position'], Booking::fromArray($proposal), $save->player_brand);
            }
        });

        return response()->noContent();
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
