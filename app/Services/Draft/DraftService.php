<?php

namespace App\Services\Draft;

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Enums\DraftPhase;
use App\Enums\DraftPickSource;
use App\Enums\DraftPickType;
use App\Enums\SaveStage;
use App\Exceptions\GameRuleException;
use App\Models\DraftPick;
use App\Models\Save;
use App\Models\SaveBrand;
use App\Models\SaveWrestler;
use App\Services\Ai\AiGm;
use App\Services\Contracts\ContractPricing;
use App\Services\Contracts\ContractService;
use App\Services\Roster\RosterService;
use App\Services\Titles\ChampionshipAssignment;

/**
 * The initial draft (§1): up to 30 picks per brand from the Free Agency pool,
 * alternating in a randomized order. A brand may stop early.
 */
class DraftService
{
    public function __construct(
        private ContractService $contracts,
        private ContractPricing $pricing,
        private RosterService $roster,
        private AiGm $ai,
        private ChampionshipAssignment $assignment,
    ) {}

    public function maxPicks(): int
    {
        return (int) config('gm.initial_draft_max_picks');
    }

    public function picksMade(Save $save, Brand $brand): int
    {
        return DraftPick::where('save_id', $save->id)
            ->where('season', $save->current_season)
            ->where('phase', DraftPhase::Initial)
            ->where('brand', $brand)
            ->count();
    }

    /**
     * Whose pick it is, or null when the draft is over.
     */
    public function nextBrand(Save $save): ?Brand
    {
        if ($save->stage !== SaveStage::Drafting) {
            return null;
        }

        $candidates = [];

        foreach ($save->draft_order as $value) {
            $brand = Brand::from($value);

            if ($this->canStillPick($save, $brand)) {
                $candidates[] = $brand;
            }
        }

        if ($candidates === []) {
            return null;
        }

        // Alternate: whoever has made fewer picks goes next; ties follow the draft order.
        usort($candidates, fn (Brand $a, Brand $b): int => $this->picksMade($save, $a) <=> $this->picksMade($save, $b));

        return $candidates[0];
    }

    public function canStillPick(Save $save, Brand $brand): bool
    {
        $saveBrand = $save->brand($brand);

        return ! $saveBrand->draft_done
            && $this->picksMade($save, $brand) < $this->maxPicks()
            && $this->roster->hasRoom($save, $brand);
    }

    public function pick(Save $save, Brand $brand, SaveWrestler $wrestler, DraftPickType $type = DraftPickType::Manual): DraftPick
    {
        if ($this->nextBrand($save) !== $brand) {
            throw new GameRuleException("It isn't {$brand->label()}'s pick.");
        }

        if ($wrestler->save_id !== $save->id || $wrestler->isSigned()) {
            throw new GameRuleException("{$wrestler->name()} has already been drafted.");
        }

        $wrestler->loadMissing('wrestler');
        $quote = $this->pricing->quote($wrestler->wrestler, ContractLength::from((int) config('gm.contracts.draft_default_length')));

        if ($save->brand($brand)->budget < $quote['upfront']) {
            throw new GameRuleException("Not enough budget to sign {$wrestler->name()}.");
        }

        $number = DraftPick::where('save_id', $save->id)->where('season', $save->current_season)->where('phase', DraftPhase::Initial)->count() + 1;

        $pick = DraftPick::create([
            'save_id' => $save->id,
            'season' => $save->current_season,
            'phase' => DraftPhase::Initial,
            'pick_number' => $number,
            'brand' => $brand,
            'save_wrestler_id' => $wrestler->id,
            'source' => DraftPickSource::FreeAgency,
            'pick_type' => $type,
        ]);

        $this->contracts->signDrafted($save, $wrestler, $brand, $save->current_week);

        return $pick;
    }

    /**
     * A brand stops drafting; remaining spots can be filled via Free Agency.
     */
    public function finish(Save $save, Brand $brand): void
    {
        SaveBrand::where('save_id', $save->id)->where('brand', $brand)->update(['draft_done' => true]);
        $save->unsetRelation('brands');
    }

    /**
     * Let the AI make its picks until it's the human's turn or the draft ends.
     * When the draft ends, moves the save on to championship assignment.
     */
    public function runAi(Save $save): void
    {
        while ($this->aiStep($save)['ai_turn']) {
            // Keep picking until it's the human's turn or the draft ends.
        }
    }

    /**
     * Make a single AI pick if it's the AI's turn, so the draft room can
     * animate each pick as it happens. Ends the draft when nobody can pick.
     *
     * @return array{pick: DraftPick|null, ai_turn: bool}
     */
    public function aiStep(Save $save): array
    {
        $brand = $this->nextBrand($save);

        if ($brand === null) {
            $this->finalize($save);

            return ['pick' => null, 'ai_turn' => false];
        }

        if ($save->isHuman($brand)) {
            return ['pick' => null, 'ai_turn' => false];
        }

        $wrestler = $this->ai->draftPick($save, $brand, $this->picksMade($save, $brand) + 1);
        $pick = null;

        if ($wrestler === null) {
            $this->finish($save, $brand);
        } else {
            $pick = $this->pick($save, $brand, $wrestler, DraftPickType::Random);
        }

        $next = $this->nextBrand($save);

        if ($next === null) {
            $this->finalize($save);
        }

        return ['pick' => $pick, 'ai_turn' => $next !== null && ! $save->isHuman($next)];
    }

    /**
     * Once neither brand can pick, move on to championship assignment.
     */
    public function finalize(Save $save): void
    {
        if ($this->nextBrand($save) === null && $save->stage === SaveStage::Drafting) {
            $save->update(['stage' => SaveStage::AssigningTitles]);
            $this->assignment->autoAssign($save, $save->rivalBrand());
        }
    }
}
