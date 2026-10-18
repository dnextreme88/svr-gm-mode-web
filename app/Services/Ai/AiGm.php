<?php

namespace App\Services\Ai;

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Exceptions\GameRuleException;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Card\CardRandomizer;
use App\Services\Card\CardService;
use App\Services\Contracts\ContractPricing;
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
     * Pick an affordable free agent for the initial draft, or null to stop.
     */
    public function draftPick(Save $save, Brand $brand, int $pickNumber): ?SaveWrestler
    {
        $budget = $save->brand($brand)->budget;
        $length = ContractLength::from((int) config('gm.contracts.draft_default_length'));

        $pool = $this->roster->freeAgentsQuery($save, $save->current_week)
            ->with('wrestler')
            ->orderBy('id')
            ->get()
            ->filter(fn (SaveWrestler $w): bool => $this->pricing->quote($w->wrestler, $length)['upfront'] <= $budget)
            ->values();

        if ($pool->isEmpty()) {
            return null;
        }

        return $save->rng("ai.draft.{$brand->value}.{$pickNumber}", 0)->pick($pool->all());
    }
}
