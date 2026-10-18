<?php

namespace App\Services\Card;

use App\Enums\Brand;
use App\Enums\RosterType;
use App\Enums\ShowStatus;
use App\Exceptions\GameRuleException;
use App\Models\SaveBrand;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Injuries\InjuryStatus;
use App\Services\Titles\TitleBookingRules;
use App\Services\Week\Steps\TrackDeficit;

/**
 * Booking rules for one match slot (§4). Manual booking has no per-card
 * limit; wrestlers in several matches are only flagged in the UI.
 */
class CardValidator
{
    public function __construct(
        private InjuryStatus $injuries,
        private TitleBookingRules $titleRules,
    ) {}

    public function validate(Show $show, int $position, Booking $booking, ?Brand $bookingBrand = null): void
    {
        $type = $booking->matchType;
        $week = $show->week_number;

        if ($show->status !== ShowStatus::Booking) {
            throw new GameRuleException("{$show->name} has already aired.");
        }

        if ($position < 1 || $position > $show->matchSlotCount()) {
            throw new GameRuleException("{$show->name} has {$show->matchSlotCount()} match slots.");
        }

        if (! $type->gm_bookable) {
            throw new GameRuleException("{$type->name} can't be booked in GM Mode.");
        }

        if ($type->ppv_only && ! $show->isPpv()) {
            throw new GameRuleException("{$type->name} can only be booked on a PPV.");
        }

        if ($type->ppv_exclusive_id !== null && $type->ppv_exclusive_id !== $show->ppv_event_id) {
            throw new GameRuleException("{$type->name} is exclusive to {$type->ppvExclusive?->name}.");
        }

        $brand = $bookingBrand ?? $show->brand;

        if ($type->brand_restriction !== null && $brand !== null && $type->brand_restriction !== $brand) {
            throw new GameRuleException("{$type->name} is a {$type->brand_restriction->label()} match.");
        }

        if ($brand !== null && $type->cost > 0) {
            $choice = SaveBrand::where('save_id', $show->save_id)->where('brand', $brand)->value('deficit_choice');

            if ($choice === TrackDeficit::CHOICE_CHEAP_MATCHES) {
                throw new GameRuleException('You chose to downgrade to free match types until the budget recovers.');
            }
        }

        if (count($booking->sides) !== $type->sideCount()) {
            throw new GameRuleException("{$type->name} needs {$type->sideCount()} sides.");
        }

        foreach ($booking->sides as $index => $side) {
            if (count($side) !== $type->sides[$index]) {
                throw new GameRuleException("{$type->name} needs {$type->competitorCount()} competitors.");
            }
        }

        $competitorIds = $booking->competitorIds();

        if (count($competitorIds) !== count(array_unique($competitorIds))) {
            throw new GameRuleException('A wrestler can only appear once in the same match.');
        }

        foreach ($booking->managers as $side => $managerId) {
            if (! in_array($side, $type->manager_sides, true)) {
                throw new GameRuleException("{$type->name} has no manager on that side.");
            }

            if (in_array($managerId, $competitorIds, true)) {
                throw new GameRuleException('A manager can’t compete in the same match they manage.');
            }
        }

        $wrestlers = SaveWrestler::with('wrestler')
            ->where('save_id', $show->save_id)
            ->whereIn('id', $booking->allIds())
            ->get()
            ->keyBy('id');

        foreach ($booking->allIds() as $id) {
            $wrestler = $wrestlers->get($id);

            if ($wrestler === null || ($brand !== null && $wrestler->brand !== $brand)) {
                throw new GameRuleException('Only wrestlers on the brand roster can be booked.');
            }

            if ($this->injuries->isSeriouslyInjured($wrestler, $week)) {
                throw new GameRuleException("{$wrestler->name()} is seriously injured and can’t be booked.");
            }

            if ($wrestler->isSittingOut($week)) {
                throw new GameRuleException("{$wrestler->name()} is sitting out after a blocked defection.");
            }
        }

        foreach ($competitorIds as $id) {
            /** @var SaveWrestler $wrestler */
            $wrestler = $wrestlers->get($id);
            $isDiva = $wrestler->wrestler->roster_type === RosterType::Diva;

            if ($type->divas_only && ! $isDiva) {
                throw new GameRuleException("{$type->name} is Divas only.");
            }

            if (! $type->divas_allowed && $isDiva) {
                throw new GameRuleException("Divas aren't eligible for {$type->name}.");
            }
        }

        $this->titleRules->validate($show, $position, $booking, $wrestlers, $brand);
    }
}
