<?php

namespace App\Services\Ai;

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Enums\PromoTypeKey;
use App\Enums\RosterType;
use App\Enums\WeightClass;
use App\Exceptions\GameRuleException;
use App\Models\PromoSlot;
use App\Models\PromoType;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Card\CardRandomizer;
use App\Services\Card\CardService;
use App\Services\Contracts\ContractPricing;
use App\Services\Promos\PromoService;
use App\Services\Roster\RosterService;

/**
 * Decisions for the brand no human runs. ASSUMPTION: the spec has no AI
 * rules and no overall/popularity data to rank on, so choices are random
 * through the save's seeded RNG.
 */
class AiGm
{
    public function __construct(
        private RosterService $roster,
        private ContractPricing $pricing,
        private CardRandomizer $randomizer,
        private CardService $cards,
        private PromoService $promos,
    ) {}

    /**
     * Fill the AI brand's empty slots using the Preset rules (§24).
     */
    public function bookShow(Show $show, ?Brand $brand = null): void
    {
        $brand ??= $show->brand;

        if ($brand === null) {
            return;
        }

        $this->cards->ensureSlots($show);
        $this->bookPromos($show);

        try {
            $preset = $this->randomizer->preset($show, $show->game->rng("ai.card.{$show->streamKey()}", $show->week_number), $brand);
        } catch (GameRuleException) {
            return;
        }

        foreach ($preset['proposals'] as $proposal) {
            try {
                $this->cards->book($show, $proposal->position, $proposal->booking, $brand);
            } catch (GameRuleException) {
                continue;
            }
        }
    }

    /**
     * ASSUMPTION: the AI keeps the money flowing with Advertising and Merchandise.
     */
    private function bookPromos(Show $show): void
    {
        foreach ([1 => PromoTypeKey::Advertising, 2 => PromoTypeKey::MerchandiseSponsorship] as $position => $key) {
            if ($position > $show->promoSlotCount() || PromoSlot::where('show_id', $show->id)->where('position', $position)->whereNotNull('promo_type_id')->exists()) {
                continue;
            }

            try {
                $this->promos->book($show, $position, PromoType::where('key', $key)->firstOrFail());
            } catch (GameRuleException) {
                continue;
            }
        }
    }

    /**
     * Pick an affordable, draftable free agent for the initial draft, or null
     * to stop. House rule: a roll of 1..`ai_roll_max` at or above
     * `ai_focus_from` makes the pick come from the brand's focus class when
     * any are left (Raw: Divas, SmackDown: Cruiserweights and Light
     * Heavyweights). Otherwise it's any wrestler at random.
     */
    public function draftPick(Save $save, Brand $brand, int $pickNumber): ?SaveWrestler
    {
        $budget = $save->brand($brand)->budget;
        $length = ContractLength::from((int) config('gm.contracts.draft_default_length'));

        $pool = $this->roster->freeAgentsQuery($save, $save->current_week)
            ->with('wrestler')
            ->orderBy('id')
            ->get()
            ->filter(fn (SaveWrestler $w): bool => $w->wrestler->isDraftable()
                && $this->pricing->quote($w->wrestler, $length)['upfront'] <= $budget)
            ->values();

        if ($pool->isEmpty()) {
            return null;
        }

        $rng = $save->rng("ai.draft.{$brand->value}.{$pickNumber}", 0);

        if ($rng->int(1, (int) config('gm.draft.ai_roll_max')) >= (int) config('gm.draft.ai_focus_from')) {
            $focus = $pool->filter(fn (SaveWrestler $w): bool => self::isDraftFocus($brand, $w))->values();

            if ($focus->isNotEmpty()) {
                return $rng->pick($focus->all());
            }
        }

        return $rng->pick($pool->all());
    }

    /**
     * The class each brand's AI chases in the draft: its colour-coded
     * division (Raw: Divas, SmackDown: Cruiserweights and Light Heavyweights).
     */
    public static function isDraftFocus(Brand $brand, SaveWrestler $wrestler): bool
    {
        $w = $wrestler->wrestler;

        return match ($brand) {
            Brand::Raw => $w->roster_type === RosterType::Diva,
            Brand::Smackdown => $w->roster_type !== RosterType::Diva
                && in_array($w->weight_class, [WeightClass::Cruiserweight, WeightClass::LightHeavyweight], true),
        };
    }
}
