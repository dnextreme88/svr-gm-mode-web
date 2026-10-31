<?php

namespace App\Services\Card;

use App\Enums\Brand;
use App\Enums\ParticipantRole;
use App\Enums\ShowStatus;
use App\Enums\Stipulation;
use App\Exceptions\GameRuleException;
use App\Models\MatchParticipant;
use App\Models\MatchSlot;
use App\Models\PromoSlot;
use App\Models\Show;
use App\Services\Titles\TitleContention;

/**
 * Saves and clears bookings on a card (§4).
 */
class CardService
{
    public function __construct(
        private CardValidator $validator,
        private TitleContention $contention,
    ) {}

    /**
     * Create the empty match and promo slots for a card.
     */
    public function ensureSlots(Show $show): void
    {
        for ($position = 1; $position <= $show->matchSlotCount(); $position++) {
            MatchSlot::firstOrCreate(['show_id' => $show->id, 'position' => $position], ['owner_brand' => $show->brand]);
        }

        for ($position = 1; $position <= $show->promoSlotCount(); $position++) {
            PromoSlot::firstOrCreate(['show_id' => $show->id, 'position' => $position]);
        }
    }

    public function book(Show $show, int $position, Booking $booking, ?Brand $bookingBrand = null): MatchSlot
    {
        $this->ensureSlots($show);
        $slot = $this->slot($show, $position);

        if ($slot->isFixed()) {
            throw new GameRuleException('That slot is fixed and can’t be rebooked.');
        }

        if ($bookingBrand !== null && $slot->owner_brand !== null && $slot->owner_brand !== $bookingBrand) {
            throw new GameRuleException("That slot belongs to {$slot->owner_brand->label()}.");
        }

        $this->validator->validate($show, $position, $booking, $bookingBrand ?? $slot->owner_brand);

        $slot->participants()->delete();
        $slot->update([
            'match_type_id' => $booking->matchType->id,
            'title_championship_id' => $booking->titleChampionshipId,
            'stipulation' => $booking->stipulation,
            'stipulation_championship_id' => $booking->stipulationChampionshipId,
            // House rule: a Winner Takes All match keeps the titles it contests.
            'contested_title_ids' => $booking->stipulation === Stipulation::WinnerTakesAll
                ? $this->contention->contested($show->game, $booking)
                : null,
            'cost' => $booking->matchType->cost,
        ]);

        $this->writeParticipants($slot, $booking);

        return $slot->load(['participants', 'matchType']);
    }

    public function clear(Show $show, int $position, ?Brand $bookingBrand = null): MatchSlot
    {
        if ($show->status !== ShowStatus::Booking) {
            throw new GameRuleException("{$show->name} has already aired.");
        }

        $slot = $this->slot($show, $position);

        if ($slot->isFixed()) {
            throw new GameRuleException('That slot is fixed and can’t be cleared.');
        }

        if ($bookingBrand !== null && $slot->owner_brand !== null && $slot->owner_brand !== $bookingBrand) {
            throw new GameRuleException("That slot belongs to {$slot->owner_brand->label()}.");
        }

        $slot->participants()->delete();
        $slot->update(['match_type_id' => null, 'title_championship_id' => null, 'stipulation' => null, 'stipulation_championship_id' => null, 'contested_title_ids' => null, 'cost' => 0]);

        return $slot;
    }

    /**
     * Write participants without validation (fixed slots set up by the PPV rules).
     */
    public function writeParticipants(MatchSlot $slot, Booking $booking): void
    {
        foreach ($booking->sides as $side => $ids) {
            foreach ($ids as $id) {
                MatchParticipant::create([
                    'match_slot_id' => $slot->id,
                    'save_wrestler_id' => $id,
                    'side' => $side,
                    'role' => ParticipantRole::Competitor,
                ]);
            }
        }

        foreach ($booking->managers as $side => $id) {
            MatchParticipant::create([
                'match_slot_id' => $slot->id,
                'save_wrestler_id' => $id,
                'side' => $side,
                'role' => ParticipantRole::Manager,
            ]);
        }

        if ($booking->refereeId !== null) {
            MatchParticipant::create([
                'match_slot_id' => $slot->id,
                'save_wrestler_id' => $booking->refereeId,
                'side' => 0,
                'role' => ParticipantRole::Referee,
            ]);
        }
    }

    public function slot(Show $show, int $position): MatchSlot
    {
        return MatchSlot::where('show_id', $show->id)->where('position', $position)->firstOrFail();
    }
}
