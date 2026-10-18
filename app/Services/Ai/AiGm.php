<?php

namespace App\Services\Ai;

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Models\Save;
use App\Models\SaveWrestler;
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
    ) {}

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
