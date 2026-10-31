<?php

namespace App\Services\Ai;

use App\Enums\Brand;
use App\Enums\ChampionshipEligibility;
use App\Enums\Stipulation;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Models\MoneyInTheBank;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Card\Booking;
use App\Services\Card\CardRandomizer;
use App\Services\Card\CardService;
use App\Services\Card\PlannedFatigue;
use App\Services\Card\TitleMatchPlanner;
use App\Services\Rivalries\RivalryService;
use App\Services\Titles\LastChanceService;
use App\Services\Titles\MitbService;
use App\Services\Titles\TitleService;
use App\Support\Rng\Rng;
use Illuminate\Database\Eloquent\Collection;

/**
 * The rival GM's match booking (house rules, `gm.ai.booking` and
 * `gm.ai.titles`). Each match rolls careful (nobody ends it above 70%) or
 * aggressive (heavier gimmicks, up to 110%), and 95/5 for one of the
 * intergender shapes in `IntergenderRules`. Earned title shots go first,
 * then the card's all-Diva match, then the main event's title roll, then
 * rivalry matches, then everything else. A slot it can't fill within the
 * rules stays empty. Everything is booked through `CardService::book`.
 */
class AiBooker
{
    private Show $show;

    private Brand $brand;

    private Rng $rng;

    /** @var list<int> ids already on the card */
    private array $taken = [];

    /** @var list<int> championship ids already on the card */
    private array $titlesOnCard = [];

    /** @var array<int, int> save-wrestler id => fatigue going into this card's matches */
    private array $fatigue = [];

    public function __construct(
        private CardRandomizer $randomizer,
        private TitleMatchPlanner $titlePlanner,
        private CardService $cards,
        private TitleService $titles,
        private LastChanceService $lastChance,
        private RivalryService $rivalries,
        private MitbService $mitb,
        private IntergenderRules $intergender,
        private PlannedFatigue $plannedFatigue,
    ) {}

    /**
     * Book the brand's empty slots on a card.
     *
     * @param  list<array{0: int, 1: int}>  $rivalryMatches  rivalry pairs to book 1v1
     * @param  list<array{0: int, 1: int}>  $freshRivalries  pairs to book 1v1 and start a rivalry between
     */
    public function book(Show $show, Brand $brand, array $rivalryMatches = [], array $freshRivalries = []): void
    {
        $this->show = $show;
        $this->brand = $brand;
        $this->rng = $show->game->rng("ai.card.{$show->streamKey()}", $show->week_number);
        $this->taken = $this->randomizer->bookedIds($show);
        $this->titlesOnCard = array_values(array_map('intval', MatchSlot::where('show_id', $show->id)->whereNotNull('title_championship_id')->pluck('title_championship_id')->all()));
        $this->fatigue = $this->plannedFatigue($show, $brand);

        $open = $this->openPositions();

        foreach ($this->titlePlanner->obligations($show, $brand) as $obligation) {
            $position = array_shift($open);

            if ($position === null) {
                break;
            }

            if (! $this->tryBook($position, $obligation)) {
                array_unshift($open, $position);
            }
        }

        $pairs = [...array_map(fn (array $p): array => [$p, true], $freshRivalries), ...array_map(fn (array $p): array => [$p, false], $rivalryMatches)];

        [$open, $pairs] = $this->bookDivaMatch($open, $pairs);
        $open = $this->bookMainEventTitle($open);

        foreach ($pairs as [$pair, $fresh]) {
            if ($open === []) {
                break;
            }

            $position = $this->rng->pick($open);

            if ($this->bookPair($position, $pair, $fresh)) {
                $open = array_values(array_diff($open, [$position]));
            }
        }

        foreach ($open as $position) {
            $this->fillSlot($position);
        }

        $this->cashIn();
    }

    /**
     * Each wrestler's fatigue plus the matches and tiring promos already
     * booked for them on this week's unaired cards.
     *
     * @return array<int, int>
     */
    public function plannedFatigue(Show $show, Brand $brand): array
    {
        return $this->plannedFatigue->forWeek($show, $brand);
    }

    /**
     * @return list<int>
     */
    private function openPositions(): array
    {
        return array_values(MatchSlot::with('participants')
            ->where('show_id', $this->show->id)
            ->orderBy('position')
            ->get()
            ->filter(fn (MatchSlot $s): bool => ! $s->isFixed() && ! $s->isBooked()
                && ($s->owner_brand === null || $s->owner_brand === $this->brand))
            ->map(fn (MatchSlot $s): int => (int) $s->position)
            ->all());
    }

    /**
     * The card's all-Diva match, planned before anything else uses the
     * Divas. A random slot, or match 5 / the main event for the Women's
     * title. A Diva rivalry pair the plan wants booked is the Diva match.
     *
     * @param  list<int>  $open
     * @param  list<array{0: array{0: int, 1: int}, 1: bool}>  $pairs  rivalry pairs, and whether each is fresh
     * @return array{0: list<int>, 1: list<array{0: array{0: int, 1: int}, 1: bool}>}
     */
    private function bookDivaMatch(array $open, array $pairs): array
    {
        $divas = $this->divas($this->pool());

        if ($open === [] || $divas->count() < 2 || (int) config('gm.ai.booking.min_diva_matches') < 1 || $this->hasDivaMatch()) {
            return [$open, $pairs];
        }

        $womens = Championship::where('brand', $this->brand)->where('eligibility', ChampionshipEligibility::DivasOnly)->first();
        $reign = $womens === null ? null : $this->titles->currentReign($this->show->game, $womens);
        $titleShot = $womens !== null && $reign !== null && ! in_array($womens->id, $this->titlesOnCard, true)
            && array_diff($reign->holderIds(), $this->ids($divas)) === []
            && $this->rng->chance((float) config('gm.ai.titles.other_slot_chance'));

        $slotRng = $this->show->game->rng("ai.divaslot.{$this->show->streamKey()}", $this->show->week_number);
        $position = $titleShot ? $slotRng->pick(array_slice($open, -2)) : $slotRng->pick($open);

        if (! $titleShot) {
            foreach ($pairs as $i => [$pair, $fresh]) {
                if (array_diff($pair, $this->ids($divas)) === [] && $this->bookPair($position, $pair, $fresh)) {
                    unset($pairs[$i]);

                    return [array_values(array_diff($open, [$position])), array_values($pairs)];
                }
            }
        }

        [$limit, $aggressive] = $this->rollMode();
        $booking = ($titleShot ? $this->singleGender($divas, $limit, $aggressive, true, [(int) $womens->id]) : null)
            ?? $this->singleGender($divas, $limit, $aggressive);

        if ($booking !== null && $this->tryBook($position, $booking)) {
            return [array_values(array_diff($open, [$position])), $pairs];
        }

        return [$open, $pairs];
    }

    /**
     * Book a rivalry pair 1v1, starting their rivalry if it's a fresh one.
     *
     * @param  array{0: int, 1: int}  $pair
     */
    private function bookPair(int $position, array $pair, bool $fresh): bool
    {
        if (array_intersect($pair, $this->taken) !== []) {
            return false;
        }

        $booking = $this->pairBooking($pair);

        if ($booking === null || ! $this->tryBook($position, $booking)) {
            return false;
        }

        if ($fresh) {
            $this->rivalries->start($this->show->game, $pair[0], $pair[1], $this->show->week_number);
        }

        return true;
    }

    /**
     * The main event rolls for a title match first, trying titles in tier
     * order (world, second tier, tag, special).
     *
     * @param  list<int>  $open
     * @return list<int>
     */
    private function bookMainEventTitle(array $open): array
    {
        $mainEvent = $this->mainEventPosition();

        if ($mainEvent === null || ! in_array($mainEvent, $open, true)) {
            return $open;
        }

        $chance = (float) config('gm.ai.titles.main_event_chance.'.($this->show->isPpv() ? 'ppv' : 'weekly'));

        if (! $this->rng->chance($chance)) {
            return $open;
        }

        $order = array_values(array_map('intval', Championship::where('brand', $this->brand)->orderBy('sort_order')->pluck('id')->all()));
        [$limit, $aggressive] = $this->rollMode();
        $booking = $this->singleGender($this->pool(), $limit, $aggressive, true, $order);

        if ($booking !== null && $this->tryBook($mainEvent, $booking)) {
            return array_values(array_diff($open, [$mainEvent]));
        }

        return $open;
    }

    /**
     * The brand's last slot on the card (the PPV's last open one).
     */
    private function mainEventPosition(): ?int
    {
        $positions = MatchSlot::where('show_id', $this->show->id)
            ->whereNull('fixed_kind')
            ->where(fn ($q) => $q->whereNull('owner_brand')->orWhere('owner_brand', $this->brand))
            ->pluck('position');

        return $positions->isEmpty() ? null : (int) $positions->max();
    }

    private function fillSlot(int $position): void
    {
        [$limit, $aggressive] = $this->rollMode();
        $pool = $this->pool();
        $men = $this->men($pool);
        $divas = $this->divas($pool);
        $title = $this->rng->chance((float) config('gm.ai.titles.other_slot_chance'));

        if ($divas->isNotEmpty() && $this->rng->chance((float) config('gm.ai.booking.intergender_chance'))) {
            $booking = $this->intergenderBooking($men, $divas, $limit, $aggressive);

            if ($booking !== null && $this->tryBook($position, $booking)) {
                return;
            }
        }

        foreach ([$men, $divas] as $people) {
            $booking = ($title ? $this->singleGender($people, $limit, $aggressive, true) : null)
                ?? $this->singleGender($people, $limit, $aggressive);

            if ($booking === null) {
                continue;
            }

            $contender = $this->championVsContender($booking);

            if (($contender !== null && $this->tryBook($position, $contender)) || $this->tryBook($position, $booking)) {
                return;
            }
        }
    }

    /**
     * A match with everyone, managers included, of one gender.
     *
     * @param  Collection<int, SaveWrestler>  $people
     * @param  list<int>|null  $order  championship ids to try, in order
     */
    private function singleGender(Collection $people, int $limit, bool $aggressive, bool $title = false, ?array $order = null): ?Booking
    {
        $types = $this->randomizer->eligibleTypes($this->show, $this->brand)
            ->filter(fn (MatchType $t): bool => (! $title || $t->titles_allowed) && ! $t->isMoneyInTheBank())
            ->filter(function (MatchType $t) use ($people, $limit): bool {
                $eligible = $this->randomizer->poolFor($t, $people);

                return $this->fit($eligible, $t, $limit)->count() >= $t->competitorCount()
                    && $eligible->count() >= $t->competitorCount() + count($t->manager_sides) + ($t->hasSpecialReferee() ? 1 : 0);
            })
            ->values();

        foreach ($this->typeOrder($types, $aggressive) as $type) {
            $eligible = $this->randomizer->poolFor($type, $people);
            $candidates = $this->fit($eligible, $type, $limit);
            $championshipId = null;

            if ($title) {
                $plan = $this->titlePlanner->plan($this->show, $this->brand, $type, $candidates, $this->titlesOnCard, $this->rng, true, $order);

                if ($plan === null) {
                    continue;
                }

                $sides = $plan['sides'];
                $championshipId = $plan['championship_id'];
            } else {
                $sides = $this->randomizer->fillSides($this->show, $type, $candidates, $this->rng);
            }

            $managers = $this->pickManagers($type, $eligible, $sides);

            try {
                $referee = $this->randomizer->pickReferee($type, $eligible->reject(fn (SaveWrestler $w): bool => in_array($w->id, $managers, true))->values(), $sides, $this->rng);
            } catch (GameRuleException) {
                continue;
            }

            return new Booking($type, $sides, $managers, $championshipId, refereeId: $referee);
        }

        return null;
    }

    /**
     * @param  Collection<int, SaveWrestler>  $men
     * @param  Collection<int, SaveWrestler>  $divas
     */
    private function intergenderBooking(Collection $men, Collection $divas, int $limit, bool $aggressive): ?Booking
    {
        $types = $this->randomizer->eligibleTypes($this->show, $this->brand)
            ->filter(function (MatchType $t) use ($men, $divas, $limit): bool {
                $shape = $this->intergender->shape($t);

                if ($shape === null) {
                    return false;
                }

                $needs = $this->intergender->needs($shape);

                return $this->fit($men, $t, $limit)->count() >= $needs['men']
                    && $this->fit($divas, $t, $limit)->count() >= $needs['divas_competing']
                    && $divas->count() >= $needs['divas_competing'] + $needs['divas_managing']
                    && $men->count() >= $needs['men'] + ($t->hasSpecialReferee() ? 1 : 0);
            })
            ->values();

        foreach ($this->typeOrder($types, $aggressive) as $type) {
            $built = $this->intergender->build($type, $this->ids($this->fit($men, $type, $limit)), $this->ids($this->fit($divas, $type, $limit)), $this->ids($divas), $this->rng);

            if ($built === null) {
                continue;
            }

            // A competing Diva can't also manage.
            $competing = array_merge(...$built['sides']);

            if (array_intersect($competing, array_values($built['managers'])) !== []) {
                $spare = array_values(array_diff($this->ids($divas), $competing));

                if (count($spare) < count($built['managers'])) {
                    continue;
                }

                $built['managers'] = array_combine(array_keys($built['managers']), array_slice($spare, 0, count($built['managers'])));
            }

            try {
                $referee = $this->randomizer->pickReferee($type, $men, $built['sides'], $this->rng);
            } catch (GameRuleException) {
                continue;
            }

            return new Booking($type, $built['sides'], $built['managers'], refereeId: $referee);
        }

        return null;
    }

    /**
     * A 1v1 between two given wrestlers of the same gender.
     *
     * @param  array{0: int, 1: int}  $pair
     */
    private function pairBooking(array $pair): ?Booking
    {
        $pool = $this->pool();
        $both = $pool->filter(fn (SaveWrestler $w): bool => in_array($w->id, $pair, true))->values();

        if ($both->count() !== 2 || $both[0]->wrestler->isDiva() !== $both[1]->wrestler->isDiva()) {
            return null;
        }

        $people = $both[0]->wrestler->isDiva() ? $this->divas($pool) : $this->men($pool);
        [$limit, $aggressive] = $this->rollMode();

        $types = $this->randomizer->eligibleTypes($this->show, $this->brand)
            ->filter(fn (MatchType $t): bool => $t->isOneOnOne()
                && $this->randomizer->poolFor($t, $both)->count() === 2
                && $this->fit($both, $t, $limit)->count() === 2
                && $people->count() >= 2 + count($t->manager_sides) + ($t->hasSpecialReferee() ? 1 : 0))
            ->values();

        foreach ($this->typeOrder($types, $aggressive) as $type) {
            $sides = $this->rng->shuffle([[$pair[0]], [$pair[1]]]);
            $managers = $this->pickManagers($type, $people, $sides);

            try {
                $referee = $this->randomizer->pickReferee($type, $people->reject(fn (SaveWrestler $w): bool => in_array($w->id, $managers, true))->values(), $sides, $this->rng);
            } catch (GameRuleException) {
                continue;
            }

            return new Booking($type, $sides, $managers, refereeId: $referee);
        }

        return null;
    }

    /**
     * A non-title 1v1 or 2v2 with a champion alone on one side may become
     * Champion vs Contender for that title.
     */
    private function championVsContender(Booking $booking): ?Booking
    {
        $type = $booking->matchType;

        if ($booking->titleChampionshipId !== null || ! ($type->isOneOnOne() || $type->isTwoOnTwo())
            || ! $this->rng->chance((float) config('gm.ai.titles.champion_vs_contender_chance'))) {
            return null;
        }

        foreach (Championship::where('brand', $this->brand)->orderBy('sort_order')->get() as $championship) {
            $reign = $this->titles->currentReign($this->show->game, $championship);

            if ($reign === null || $championship->is_tag !== $type->isTwoOnTwo()) {
                continue;
            }

            $holders = $reign->holderIds();
            sort($holders);

            foreach ($booking->sides as $i => $side) {
                $sorted = $side;
                sort($sorted);

                if ($sorted !== $holders) {
                    continue;
                }

                $contenders = $booking->sides[1 - $i];
                $wrestlers = SaveWrestler::with('wrestler')->whereIn('id', $contenders)->get();

                if ($wrestlers->contains(fn (SaveWrestler $w): bool => ! $championship->allowsWrestler($w->wrestler))
                    || $this->lastChance->isLockedOut($reign, $contenders)) {
                    continue;
                }

                return new Booking($type, $booking->sides, $booking->managers, null, Stipulation::ChampionVsContender, $championship->id, $booking->refereeId);
            }
        }

        return null;
    }

    /**
     * Cash in the brand's briefcase when the world champion is worn down or
     * the briefcase is about to expire. Once per card.
     */
    private function cashIn(): void
    {
        if ($this->show->brand === null) {
            return;
        }

        $briefcase = MoneyInTheBank::with('holder')
            ->where('save_id', $this->show->save_id)
            ->where('brand', $this->brand)
            ->whereNull('cash_in_match_id')
            ->get()
            ->first(fn (MoneyInTheBank $b): bool => $b->isHeld() && $b->holder->brand === $this->brand);

        $world = Championship::where('brand', $this->brand)->where('is_world', true)->first();
        $reign = $world === null ? null : $this->titles->currentReign($this->show->game, $world);

        if ($briefcase === null || $reign === null) {
            return;
        }

        $tired = collect($reign->holderIds())->contains(fn (int $id): bool => ($this->fatigue[$id] ?? 0) >= (int) config('gm.ai.mitb.cash_in_champion_fatigue'));
        $expiring = $briefcase->expires_week - $this->show->week_number <= (int) config('gm.ai.mitb.cash_in_weeks_left');

        if (! $tired && ! $expiring) {
            return;
        }

        try {
            $this->mitb->cashIn($this->show, $briefcase);
        } catch (GameRuleException) {
            // Injured, vacant or already cashed in: wait.
        }
    }

    private function tryBook(int $position, Booking $booking): bool
    {
        try {
            $this->cards->book($this->show, $position, $booking, $this->brand);
        } catch (GameRuleException) {
            return false;
        }

        $this->taken = array_values(array_unique([...$this->taken, ...$booking->allIds()]));

        if ($booking->titleChampionshipId !== null) {
            $this->titlesOnCard[] = $booking->titleChampionshipId;
        }

        foreach ($booking->competitorIds() as $id) {
            $this->fatigue[$id] = ($this->fatigue[$id] ?? 0) + $booking->matchType->fatigueCost();
        }

        return true;
    }

    /**
     * @return array{0: int, 1: bool} the fatigue limit, and whether the match is aggressive
     */
    private function rollMode(): array
    {
        return $this->rng->chance((float) config('gm.ai.booking.careful_chance'))
            ? [(int) config('gm.ai.booking.careful_max_fatigue'), false]
            : [(int) config('gm.ai.booking.aggressive_max_fatigue'), true];
    }

    /**
     * Types to try, in order: random, with an aggressive match trying the
     * heavy gimmicks first.
     *
     * @param  \Illuminate\Support\Collection<int, MatchType>  $types
     * @return list<MatchType>
     */
    private function typeOrder(\Illuminate\Support\Collection $types, bool $aggressive): array
    {
        $shuffled = $this->rng->shuffle($types->all());

        if (! $aggressive) {
            return $shuffled;
        }

        $min = (int) config('gm.ai.booking.aggressive_min_bars');

        return [
            ...array_values(array_filter($shuffled, fn (MatchType $t): bool => $t->fatigue_cost_bars >= $min)),
            ...array_values(array_filter($shuffled, fn (MatchType $t): bool => $t->fatigue_cost_bars < $min)),
        ];
    }

    /**
     * Managers for every managed side, from the competitors' own gender.
     *
     * @param  Collection<int, SaveWrestler>  $people
     * @param  list<list<int>>  $sides
     * @return array<int, int>
     */
    private function pickManagers(MatchType $type, Collection $people, array $sides): array
    {
        if (! $type->hasManagers()) {
            return [];
        }

        $left = $this->rng->shuffle(array_values(array_diff(array_map('intval', $people->modelKeys()), array_merge(...$sides))));
        $managers = [];

        foreach ($type->manager_sides as $i => $side) {
            if (isset($left[$i])) {
                $managers[(int) $side] = $left[$i];
            }
        }

        return $managers;
    }

    /**
     * @param  Collection<int, SaveWrestler>  $people
     * @return Collection<int, SaveWrestler>
     */
    private function fit(Collection $people, MatchType $type, int $limit): Collection
    {
        return $people->filter(fn (SaveWrestler $w): bool => ($this->fatigue[$w->id] ?? $w->fatigue) + $type->fatigueCost() <= $limit)->values();
    }

    /**
     * @param  Collection<int, SaveWrestler>  $people
     * @return list<int>
     */
    private function ids(Collection $people): array
    {
        return array_values(array_map('intval', $people->modelKeys()));
    }

    /**
     * @return Collection<int, SaveWrestler>
     */
    private function pool(): Collection
    {
        return $this->randomizer->pool($this->show, $this->brand, $this->taken);
    }

    /**
     * @param  Collection<int, SaveWrestler>  $pool
     * @return Collection<int, SaveWrestler>
     */
    private function men(Collection $pool): Collection
    {
        return $pool->reject(fn (SaveWrestler $w): bool => $w->wrestler->isDiva())->values();
    }

    /**
     * @param  Collection<int, SaveWrestler>  $pool
     * @return Collection<int, SaveWrestler>
     */
    private function divas(Collection $pool): Collection
    {
        return $pool->filter(fn (SaveWrestler $w): bool => $w->wrestler->isDiva())->values();
    }

    private function hasDivaMatch(): bool
    {
        return MatchSlot::with('participants.saveWrestler.wrestler')
            ->where('show_id', $this->show->id)
            ->get()
            ->filter(fn (MatchSlot $s): bool => $s->isBooked() && ($s->owner_brand === null || $s->owner_brand === $this->brand))
            ->contains(fn (MatchSlot $s): bool => $s->competitors()->every(fn ($p): bool => $p->saveWrestler->wrestler->isDiva()));
    }
}
