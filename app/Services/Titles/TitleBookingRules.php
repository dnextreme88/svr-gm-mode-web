<?php

namespace App\Services\Titles;

use App\Enums\Brand;
use App\Enums\Stipulation;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\MatchSlot;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Models\TitleReign;
use App\Services\Card\Booking;
use Illuminate\Support\Collection;

/**
 * Booking rules for title matches (§4, §14) and special stipulations (§22).
 */
class TitleBookingRules
{
    public function __construct(
        private TitleService $titles,
        private LastChanceService $lastChance,
    ) {}

    /**
     * @param  Collection<int, SaveWrestler>  $wrestlers  booked wrestlers keyed by id
     */
    public function validate(Show $show, int $position, Booking $booking, Collection $wrestlers, ?Brand $brand): void
    {
        if ($booking->titleChampionshipId !== null) {
            $this->validateTitleMatch($show, $position, $booking, $wrestlers, $brand);
        }

        if ($booking->stipulation !== null) {
            $this->validateStipulation($show, $booking, $wrestlers);
        }
    }

    /**
     * @param  Collection<int, SaveWrestler>  $wrestlers
     */
    private function validateTitleMatch(Show $show, int $position, Booking $booking, Collection $wrestlers, ?Brand $brand): void
    {
        $championship = Championship::findOrFail($booking->titleChampionshipId);
        $type = $booking->matchType;

        if (! $type->titles_allowed) {
            throw new GameRuleException("No titles can be defended in {$type->name}.");
        }

        if ($brand !== null && $championship->brand !== $brand) {
            throw new GameRuleException("The {$championship->name} belongs to {$championship->brand->label()}.");
        }

        $sizes = array_unique($type->sides);

        if ($championship->is_tag ? $sizes !== [2] : $sizes !== [1]) {
            throw new GameRuleException($championship->is_tag
                ? "The {$championship->name} needs teams of two on every side."
                : "The {$championship->name} is defended in singles-style matches.");
        }

        $onCard = MatchSlot::where('show_id', $show->id)
            ->where('position', '!=', $position)
            ->where('title_championship_id', $championship->id)
            ->exists();

        if ($onCard) {
            throw new GameRuleException("The {$championship->name} is already on this card.");
        }

        $reign = $this->titles->currentReign($show->game, $championship);
        $challengers = $booking->competitorIds();

        if ($reign !== null) {
            $holders = $reign->holderIds();

            if (! $this->championsShareASide($booking, $holders)) {
                throw new GameRuleException("The {$championship->name} can only be on the line if the current champion(s) are in the match.");
            }

            $challengers = array_values(array_diff($challengers, $holders));

            if ($this->lastChance->isLockedOut($reign, $challengers)) {
                throw new GameRuleException("A Last Chance loser can't challenge {$reign->holderName()} again until the title changes hands.");
            }
        }

        foreach ($challengers as $id) {
            $wrestler = $wrestlers->get($id);

            if ($wrestler === null || ! $championship->allowsWrestler($wrestler->wrestler)) {
                throw new GameRuleException("{$wrestler?->name()} isn't eligible for the {$championship->name}.");
            }
        }
    }

    /**
     * @param  Collection<int, SaveWrestler>  $wrestlers
     */
    private function validateStipulation(Show $show, Booking $booking, Collection $wrestlers): void
    {
        $type = $booking->matchType;
        $oneOnOne = $type->isOneOnOne();
        $twoOnTwo = $type->isTwoOnTwo();

        match ($booking->stipulation) {
            Stipulation::LoserLeavesShow => ($oneOnOne || $twoOnTwo) ?: throw new GameRuleException('Loser Leaves Show needs a 1v1 or 2v2 match.'),
            Stipulation::ChampionVsContender => $this->validateChampionVsContender($show, $booking, $wrestlers),
            Stipulation::LastChance => $this->validateLastChance($show, $booking),
            null => null,
        };
    }

    /**
     * @param  Collection<int, SaveWrestler>  $wrestlers
     */
    private function validateChampionVsContender(Show $show, Booking $booking, Collection $wrestlers): void
    {
        if ($booking->titleChampionshipId !== null) {
            throw new GameRuleException('In Champion vs. Contender the title is not on the line.');
        }

        $championship = $booking->stipulationChampionshipId === null ? null : Championship::find($booking->stipulationChampionshipId);
        $reign = $championship === null ? null : $this->titles->currentReign($show->game, $championship);

        if ($championship === null || $reign === null) {
            throw new GameRuleException('Pick the title the contender is chasing.');
        }

        $expected = $championship->is_tag ? $booking->matchType->isTwoOnTwo() : $booking->matchType->isOneOnOne();

        if (! $expected) {
            throw new GameRuleException($championship->is_tag ? 'Champion vs. Contender for a tag title is 2 vs 2.' : 'Champion vs. Contender is 1 vs 1.');
        }

        if (! $this->championsShareASide($booking, $reign->holderIds())) {
            throw new GameRuleException("The {$championship->name} holder(s) must be one side of the match.");
        }

        foreach (array_diff($booking->competitorIds(), $reign->holderIds()) as $id) {
            if (! $championship->allowsWrestler($wrestlers->get($id)->wrestler)) {
                throw new GameRuleException("{$wrestlers->get($id)?->name()} isn't eligible for the {$championship->name}.");
            }
        }

        if ($this->lastChance->isLockedOut($reign, array_values(array_diff($booking->competitorIds(), $reign->holderIds())))) {
            throw new GameRuleException('A Last Chance loser can’t chase this champion again until the title changes hands.');
        }
    }

    private function validateLastChance(Show $show, Booking $booking): void
    {
        $championship = $booking->titleChampionshipId === null ? null : Championship::find($booking->titleChampionshipId);
        $reign = $championship === null ? null : $this->titles->currentReign($show->game, $championship);

        if ($reign === null) {
            throw new GameRuleException('A Last Chance match must have the title on the line.');
        }

        if (! ($booking->matchType->isOneOnOne() || $booking->matchType->isTwoOnTwo())) {
            throw new GameRuleException('A Last Chance match is 1v1 or 2v2.');
        }

        $contenders = array_values(array_diff($booking->competitorIds(), $reign->holderIds()));

        if (! $this->lastChance->isAvailable($reign, $contenders)) {
            throw new GameRuleException('Last Chance needs at least 2 earlier title matches in this reign with exactly these participants, all won by the champion(s).');
        }
    }

    /**
     * @param  list<int>  $holders
     */
    private function championsShareASide(Booking $booking, array $holders): bool
    {
        foreach ($booking->sides as $side) {
            if ($holders !== [] && array_diff($holders, $side) === []) {
                return true;
            }
        }

        return false;
    }

    public function reignFor(Show $show, int $championshipId): ?TitleReign
    {
        return $this->titles->currentReign($show->game, Championship::findOrFail($championshipId));
    }
}
