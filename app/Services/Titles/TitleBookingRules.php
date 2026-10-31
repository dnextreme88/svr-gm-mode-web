<?php

namespace App\Services\Titles;

use App\Enums\Brand;
use App\Enums\RosterType;
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
 * Booking rules for title matches (§4, §14) and special stipulations (§22),
 * plus the house-rule title stipulations (2026-10-31): Winner Takes All and
 * #1 Contender's Match, and a pending #1 contendership reserving its title.
 */
class TitleBookingRules
{
    public function __construct(
        private TitleService $titles,
        private LastChanceService $lastChance,
        private TitleContention $contention,
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
            $this->validateStipulation($show, $position, $booking, $wrestlers, $brand);
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

        if (in_array($championship->id, $this->titlesOnCard($show, $position), true)) {
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

        // House rule: a #1 contender's title match is theirs alone.
        $contendership = $this->contention->contendership($show->game, $championship->id);

        if ($contendership !== null && ! $this->sameIds($challengers, $contendership->contender_ids)) {
            throw new GameRuleException("The {$championship->name} is reserved for its #1 contender(s): {$this->names($contendership->contender_ids, $wrestlers)} must be the only challengers.");
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
    private function validateStipulation(Show $show, int $position, Booking $booking, Collection $wrestlers, ?Brand $brand): void
    {
        $type = $booking->matchType;
        $stipulation = $booking->stipulation;

        if ($stipulation === null) {
            return;
        }

        if (! $type->allowsStipulation($stipulation)) {
            throw new GameRuleException("A {$type->family->label()} can't have a {$stipulation->label()} stipulation.");
        }

        $oneOnOne = $type->isOneOnOne();
        $twoOnTwo = $type->isTwoOnTwo();

        match ($stipulation) {
            Stipulation::LoserLeavesShow => ($oneOnOne || $twoOnTwo) ?: throw new GameRuleException('Loser Leaves Show needs a 1v1 or 2v2 match.'),
            Stipulation::ChampionVsContender => $this->validateChampionVsContender($show, $booking, $wrestlers),
            Stipulation::LastChance => $this->validateLastChance($show, $booking),
            Stipulation::WinnerTakesAll => $this->validateWinnerTakesAll($show, $position, $booking, $wrestlers, $brand),
            Stipulation::NumberOneContender => $this->validateNumberOneContender($show, $position, $booking, $wrestlers, $brand),
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

        $contenders = array_values(array_diff($booking->competitorIds(), $reign->holderIds()));

        foreach ($contenders as $id) {
            if (! $championship->allowsWrestler($wrestlers->get($id)->wrestler)) {
                throw new GameRuleException("{$wrestlers->get($id)?->name()} isn't eligible for the {$championship->name}.");
            }

            // House rule: the contender side holds no title of its own.
            if ($this->contention->holdsAnyTitle($show->game, $id)) {
                throw new GameRuleException("{$wrestlers->get($id)?->name()} is a champion: Champion vs. Contender needs a contender without a title.");
            }
        }

        if ($this->lastChance->isLockedOut($reign, $contenders)) {
            throw new GameRuleException('A Last Chance loser can’t chase this champion again until the title changes hands.');
        }

        if ($this->contention->contendership($show->game, $championship->id) !== null) {
            throw new GameRuleException("The {$championship->name} already has a #1 contender.");
        }
    }

    /**
     * House rule: every competitor is a champion; the winner takes every
     * contestable title they hold. 1v1 or 1v1v1, no title on the line.
     *
     * @param  Collection<int, SaveWrestler>  $wrestlers
     */
    private function validateWinnerTakesAll(Show $show, int $position, Booking $booking, Collection $wrestlers, ?Brand $brand): void
    {
        if ($booking->titleChampionshipId !== null) {
            throw new GameRuleException('Winner Takes All puts every champion’s titles on the line: don’t pick one title.');
        }

        $sides = $booking->matchType->sides;

        if (array_unique($sides) !== [1] || count($sides) < 2 || count($sides) > 3) {
            throw new GameRuleException('Winner Takes All is 1 vs 1 or a triple threat.');
        }

        $save = $show->game;

        foreach ($booking->competitorIds() as $id) {
            $held = $this->contention->contestableHeld($save, $id)
                ->filter(fn (Championship $c): bool => $brand === null || $c->brand === $brand);

            if ($held->isEmpty()) {
                $wrestler = $wrestlers->get($id);

                throw new GameRuleException($wrestler?->wrestler->roster_type === RosterType::Diva
                    ? 'The Women’s Championship can’t be contested in Winner Takes All.'
                    : "{$wrestler?->name()} doesn't hold a title: everyone in Winner Takes All must be a champion.");
            }
        }

        $onCard = $this->titlesOnCard($show, $position);

        foreach ($this->contention->contested($save, $booking) as $championshipId) {
            $championship = Championship::findOrFail($championshipId);
            $reign = $this->titles->currentReign($save, $championship);

            if (in_array($championshipId, $onCard, true)) {
                throw new GameRuleException("The {$championship->name} is already on this card.");
            }

            if ($reign !== null && $this->lastChance->isLockedOut($reign, array_values(array_diff($booking->competitorIds(), $reign->holderIds())))) {
                throw new GameRuleException("A Last Chance loser can't challenge {$reign->holderName()} again until the title changes hands.");
            }

            if ($this->contention->contendership($save, $championshipId) !== null || $this->contention->earnedShot($save, $championshipId) !== null) {
                throw new GameRuleException("The {$championship->name} has a title shot waiting, so it can’t be put up in Winner Takes All.");
            }
        }
    }

    /**
     * House rule: non-champions fight to become a title's #1 contender(s).
     * Any non-title match whose sides fit the title; nobody in it holds it.
     *
     * @param  Collection<int, SaveWrestler>  $wrestlers
     */
    private function validateNumberOneContender(Show $show, int $position, Booking $booking, Collection $wrestlers, ?Brand $brand): void
    {
        if ($booking->titleChampionshipId !== null) {
            throw new GameRuleException('A #1 Contender’s Match has no title on the line.');
        }

        $championship = $booking->stipulationChampionshipId === null ? null : Championship::find($booking->stipulationChampionshipId);
        $save = $show->game;
        $reign = $championship === null ? null : $this->titles->currentReign($save, $championship);

        if ($championship === null || $reign === null) {
            throw new GameRuleException('Pick the title the winner will contend for.');
        }

        if ($brand !== null && $championship->brand !== $brand) {
            throw new GameRuleException("The {$championship->name} belongs to {$championship->brand->label()}.");
        }

        $sides = $booking->matchType->sides;
        $size = $championship->is_tag ? 2 : 1;

        if (array_unique($sides) !== [$size] || count($sides) < 2 || ($championship->is_tag && count($sides) > 3)) {
            throw new GameRuleException($championship->is_tag
                ? "A #1 Contender’s Match for the {$championship->name} is 2 vs 2 or 2 vs 2 vs 2."
                : "A #1 Contender’s Match for the {$championship->name} has one superstar per side.");
        }

        $competitors = $booking->competitorIds();

        if (array_intersect($competitors, $reign->holderIds()) !== []) {
            throw new GameRuleException("{$reign->holderName()} can’t be in a #1 Contender’s Match for their own title.");
        }

        foreach ($competitors as $id) {
            if (! $championship->allowsWrestler($wrestlers->get($id)->wrestler)) {
                throw new GameRuleException("{$wrestlers->get($id)?->name()} isn't eligible for the {$championship->name}.");
            }
        }

        if ($this->lastChance->isLockedOut($reign, $competitors)) {
            throw new GameRuleException("A Last Chance loser can't contend for {$reign->holderName()}'s title again until it changes hands.");
        }

        if ($this->contention->contendership($save, $championship->id) !== null) {
            throw new GameRuleException("The {$championship->name} already has a #1 contender.");
        }

        if ($this->contention->earnedShot($save, $championship->id) !== null) {
            throw new GameRuleException("The {$championship->name} has an earned title shot to book first.");
        }

        $another = MatchSlot::where('show_id', $show->id)
            ->where('position', '!=', $position)
            ->where('stipulation', Stipulation::NumberOneContender)
            ->where('stipulation_championship_id', $championship->id)
            ->exists();

        if ($another) {
            throw new GameRuleException("There’s already a #1 Contender’s Match for the {$championship->name} on this card.");
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
     * Titles on the line in the card's other slots: title matches and
     * Winner Takes All matches.
     *
     * @return list<int>
     */
    private function titlesOnCard(Show $show, int $position): array
    {
        return array_values(MatchSlot::where('show_id', $show->id)
            ->where('position', '!=', $position)
            ->get()
            ->flatMap(fn (MatchSlot $slot): array => array_filter([$slot->title_championship_id, ...($slot->contested_title_ids ?? [])]))
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    private function sameIds(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * @param  list<int>  $ids
     * @param  Collection<int, SaveWrestler>  $wrestlers
     */
    private function names(array $ids, Collection $wrestlers): string
    {
        return implode(' & ', array_map(fn (int $id): string => $wrestlers->get($id)?->name() ?? SaveWrestler::find($id)?->name() ?? '?', $ids));
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
