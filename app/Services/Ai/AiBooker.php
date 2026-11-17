<?php

namespace App\Services\Ai;

use App\Enums\Brand;
use App\Enums\ChampionshipEligibility;
use App\Enums\ComplaintLevel;
use App\Enums\FixedSlotKind;
use App\Enums\MatchFamily;
use App\Enums\MoraleComplaintType;
use App\Enums\Stipulation;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Models\MoneyInTheBank;
use App\Models\MoraleComplaint;
use App\Models\Rivalry;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Models\TitleShot;
use App\Services\Attendance\RepeatMatches;
use App\Services\Card\Booking;
use App\Services\Card\CardRandomizer;
use App\Services\Card\CardService;
use App\Services\Card\PlannedFatigue;
use App\Services\Card\TitleMatchPlanner;
use App\Services\Ppv\QualifierService;
use App\Services\Titles\LastChanceService;
use App\Services\Titles\MitbService;
use App\Services\Titles\RematchClauseService;
use App\Services\Titles\TitleContention;
use App\Services\Titles\TitleService;
use App\Support\Rng\Rng;
use Illuminate\Database\Eloquent\Collection;

/**
 * The rival GM's match booking (house rules, `gm.ai.booking` and
 * `gm.ai.titles`). Each match rolls careful (nobody ends it above 70%) or
 * aggressive (heavier gimmicks, up to 110%), and 95/5 for one of the
 * intergender shapes in `IntergenderRules`. Earned title shots go first,
 * then the card's all-Diva match, then the main event's title roll, then a
 * Winner Takes All roll, then rivalry matches, then everything else (each
 * may roll Champion vs. Contender or #1 Contender's Match). A slot it can't fill within the
 * rules stays empty. Everything is booked through `CardService::book`.
 */
class AiBooker
{
    private Show $show;

    private Brand $brand;

    private Rng $rng;

    /** @var list<int> ids already on the card */
    private array $taken = [];

    /** @var array<int, MatchFamily>|null last week's categories by slot, for repeats */
    private ?array $lastWeek = null;

    /** @var list<int> championship ids already on the card */
    private array $titlesOnCard = [];

    /** @var array<int, int> save-wrestler id => fatigue going into this card's matches */
    private array $fatigue = [];

    /**
     * Its qualifiers whose PPV hasn't happened yet (house rule, 2026-11-17).
     *
     * @var list<int>
     */
    private array $protected = [];

    public function __construct(
        private CardRandomizer $randomizer,
        private TitleMatchPlanner $titlePlanner,
        private CardService $cards,
        private TitleService $titles,
        private LastChanceService $lastChance,
        private MitbService $mitb,
        private IntergenderRules $intergender,
        private PlannedFatigue $plannedFatigue,
        private TitleContention $contention,
        private RematchClauseService $rematches,
        private RepeatMatches $repeatMatches,
        private AiStipulationPlanner $stipulations,
        private AiTradeJudge $judge,
        private QualifierService $qualifiers,
    ) {}

    /**
     * Book the brand's empty slots on a card.
     *
     * @param  list<array{0: int, 1: int}>  $rivalryMatches  rivalry pairs to book 1v1
     */
    public function book(Show $show, Brand $brand, array $rivalryMatches = []): void
    {
        $this->show = $show;
        $this->brand = $brand;
        $this->rng = $show->game->rng("ai.card.{$show->streamKey()}", $show->week_number);
        $this->lastWeek = null;
        $this->taken = $this->randomizer->bookedIds($show);
        $this->titlesOnCard = array_values(array_map('intval', MatchSlot::where('show_id', $show->id)->whereNotNull('title_championship_id')->pluck('title_championship_id')->all()));
        $this->fatigue = $this->plannedFatigue($show, $brand);
        $this->protected = $this->qualifiers->protectedIds($show->game, $brand, $show->week_number);
        // House rule (2026-11-13): a #1 contendership earned in the run-up to
        // its own PPV is held for the PPV: that title stays off this card.
        $this->titlesOnCard = array_values(array_unique([...$this->titlesOnCard, ...$this->heldTitles()]));

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

        $open = $this->bookContenderPayoffs($open);
        $open = $this->bookRematches($open);
        $open = $this->bookMoraleFixes($open);
        $open = $this->bookPlannedStipulations($open);

        $pairs = $rivalryMatches;

        [$open, $pairs] = $this->bookDivaMatch($open, $pairs);
        $open = $this->bookMainEventTitle($open);
        $open = $this->bookInterbrandTitle($open);
        $open = $this->bookWinnerTakesAll($open);

        foreach ($pairs as $pair) {
            if ($open === []) {
                break;
            }

            $position = $this->rng->pick($open);

            if ($this->bookPair($position, $pair)) {
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
     * @param  list<array{0: int, 1: int}>  $pairs  rivalry pairs to book 1v1
     * @return array{0: list<int>, 1: list<array{0: int, 1: int}>}
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
            foreach ($pairs as $i => $pair) {
                if (array_diff($pair, $this->ids($divas)) === [] && $this->bookPair($position, $pair)) {
                    unset($pairs[$i]);

                    return [array_values(array_diff($open, [$position])), array_values($pairs)];
                }
            }
        }

        [$limit, $aggressive] = $this->rollMode();
        $booking = ($titleShot ? $this->singleGender($divas, $limit, $aggressive, true, [(int) $womens->id]) : null)
            ?? $this->singleGender($divas, $limit, $aggressive);

        $stipulated = $booking === null || $titleShot ? null : $this->numberOneContender($booking);

        if ($booking !== null && (($stipulated !== null && $this->tryBook($position, $stipulated)) || $this->tryBook($position, $booking))) {
            return [array_values(array_diff($open, [$position])), $pairs];
        }

        return [$open, $pairs];
    }

    /**
     * Book a rivalry pair 1v1.
     *
     * @param  array{0: int, 1: int}  $pair
     */
    private function bookPair(int $position, array $pair): bool
    {
        if (array_intersect($pair, $this->taken) !== []) {
            return false;
        }

        $booking = $this->pairBooking($pair);

        return $booking !== null && $this->tryBook($position, $booking);
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
     * House rule (2026-11-15): on an interbrand PPV (SummerSlam, the Royal
     * Rumble, Survivor Series, WrestleMania) the rival books at least one
     * title match in its own slots. When nothing it has booked so far puts
     * a title on the line, it books one in its last open slot (else any
     * other). Which title is a roll: its world title at
     * `gm.ai.titles.interbrand_world_chance` (80%), else one of its other
     * titles at random. A title it can't book falls back to the rest, so a
     * title match still goes on. Careful first (nobody past the fatigue
     * limit), then aggressive if it must.
     *
     * @param  list<int>  $open
     * @return list<int>
     */
    private function bookInterbrandTitle(array $open): array
    {
        if (! $this->show->isInterbrand() || $open === [] || $this->hasOwnTitleMatch()) {
            return $open;
        }

        $titles = Championship::where('brand', $this->brand)->orderBy('sort_order')->get();
        $world = array_values(array_map('intval', $titles->where('is_world', true)->pluck('id')->all()));
        $others = $this->rng->shuffle(array_values(array_map('intval', $titles->where('is_world', false)->pluck('id')->all())));
        $order = $this->rng->chance((float) config('gm.ai.titles.interbrand_world_chance'))
            ? [...$world, ...$others]
            : [...$others, ...$world];
        $positions = $open;
        rsort($positions);

        foreach ([[(int) config('gm.ai.booking.careful_max_fatigue'), false], [(int) config('gm.ai.booking.aggressive_max_fatigue'), true]] as [$limit, $aggressive]) {
            foreach ($order as $championshipId) {
                $booking = $this->singleGender($this->pool(), $limit, $aggressive, true, [$championshipId]);

                if ($booking === null) {
                    continue;
                }

                foreach ($positions as $position) {
                    if ($this->tryBook($position, $booking)) {
                        return array_values(array_diff($open, [$position]));
                    }
                }
            }
        }

        return $open;
    }

    /**
     * Whether one of this brand's matches on the card has its title on the
     * line (or is a Winner Takes All for its titles).
     */
    private function hasOwnTitleMatch(): bool
    {
        $titles = array_values(array_map('intval', Championship::where('brand', $this->brand)->pluck('id')->all()));

        return MatchSlot::where('show_id', $this->show->id)->get()
            ->contains(fn (MatchSlot $s): bool => in_array((int) $s->title_championship_id, $titles, true)
                || array_intersect(array_map('intval', $s->contested_title_ids ?? []), $titles) !== []);
    }

    /**
     * The brand's last slot on the card (the PPV's last open one).
     */
    private function mainEventPosition(): ?int
    {
        // House rule (2026-11-17): a Qualifying Match can be a weekly show's
        // main event; then there's no other main event to book.
        $last = MatchSlot::where('show_id', $this->show->id)->orderByDesc('position')->first();

        if (! $this->show->isPpv() && $last?->fixed_kind === FixedSlotKind::QualifyingMatch) {
            return null;
        }

        $positions = MatchSlot::where('show_id', $this->show->id)
            ->whereNull('fixed_kind')
            ->where(fn ($q) => $q->whereNull('owner_brand')->orWhere('owner_brand', $this->brand))
            ->pluck('position');

        return $positions->isEmpty() ? null : (int) $positions->max();
    }

    private function fillSlot(int $position): void
    {
        // House rule (2026-11-08): sometimes the rival books the same kind of
        // match as last week in this slot, like a human GM would, attendance
        // deduction and all. Nothing in that category fits: it books as usual.
        $family = $this->repeatFamily($position);

        if ($family !== null && $this->tryFill($position, $family)) {
            return;
        }

        $this->tryFill($position);
    }

    /**
     * The category last week's match in this slot was in, when the rival
     * rolls to repeat it (weekly shows only).
     */
    private function repeatFamily(int $position): ?MatchFamily
    {
        $this->lastWeek ??= $this->repeatMatches->families($this->repeatMatches->lastWeek($this->show));
        $family = $this->lastWeek[$position] ?? null;

        if ($family === null) {
            return null;
        }

        $chance = (int) round((float) config('gm.ai.booking.repeat_category_chance') * 100);
        $roll = $this->show->game->rng("ai.repeat.{$this->show->streamKey()}.{$position}", $this->show->week_number)->int(1, 100);

        return $roll > 100 - $chance ? $family : null;
    }

    private function tryFill(int $position, ?MatchFamily $family = null): bool
    {
        [$limit, $aggressive] = $this->rollMode();
        $pool = $this->pool();
        $men = $this->men($pool);
        $divas = $this->divas($pool);
        $title = $this->rng->chance((float) config('gm.ai.titles.other_slot_chance'));

        if ($divas->isNotEmpty() && $this->rng->chance((float) config('gm.ai.booking.intergender_chance'))) {
            $booking = $this->intergenderBooking($men, $divas, $limit, $aggressive, $family);

            if ($booking !== null && $this->tryBook($position, $booking)) {
                return true;
            }
        }

        foreach ([$men, $divas] as $people) {
            $booking = ($title ? $this->singleGender($people, $limit, $aggressive, true, family: $family) : null)
                ?? $this->singleGender($people, $limit, $aggressive, family: $family);

            if ($booking === null) {
                continue;
            }

            $stipulated = $this->championVsContender($booking) ?? $this->numberOneContender($booking);

            if (($stipulated !== null && $this->tryBook($position, $stipulated)) || $this->tryBook($position, $booking)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A match with everyone, managers included, of one gender.
     *
     * @param  Collection<int, SaveWrestler>  $people
     * @param  list<int>|null  $order  championship ids to try, in order
     */
    private function singleGender(Collection $people, int $limit, bool $aggressive, bool $title = false, ?array $order = null, ?MatchFamily $family = null): ?Booking
    {
        $types = $this->randomizer->eligibleTypes($this->show, $this->brand)
            ->filter(fn (MatchType $t): bool => (! $title || $t->titles_allowed) && ! $t->isMoneyInTheBank() && ($family === null || $t->family === $family))
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
    private function intergenderBooking(Collection $men, Collection $divas, int $limit, bool $aggressive, ?MatchFamily $family = null): ?Booking
    {
        $types = $this->randomizer->eligibleTypes($this->show, $this->brand)
            ->filter(fn (MatchType $t): bool => $family === null || $t->family === $family)
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
     * Rematch Clauses (house rule, 2026-11-07): on each of the weeks after
     * its title changed hands, the rival rolls once (`gm.ai.titles.
     * rematch_clause_chances`, in order) to book the former champions
     * against the new ones in a title match, 1v1 or 2v2, if every one of
     * them can be booked within this card's fatigue limit.
     *
     * @param  list<int>  $open
     * @return list<int>
     */
    private function bookRematches(array $open): array
    {
        $save = $this->show->game;
        $week = $this->show->week_number;
        $chances = array_values((array) config('gm.ai.titles.rematch_clause_chances'));
        $weekShows = Show::where('save_id', $save->id)->where('week_number', $week)->pluck('id');

        foreach ($this->rematches->active($save, $week, $this->brand) as $clause) {
            $index = $week - $clause->created_week - 1;

            if ($open === [] || ! isset($chances[$index]) || in_array($clause->championship_id, $this->titlesOnCard, true)) {
                continue;
            }

            // Already booked on another of this week's cards.
            $booked = MatchSlot::with('participants')->whereIn('show_id', $weekShows)->where('title_championship_id', $clause->championship_id)->get()
                ->contains(fn (MatchSlot $s): bool => $this->rematches->fits($clause, $s));

            // One roll a week: the same stream on every card of the week.
            if ($booked || ! $save->rng("ai.rematch.{$clause->id}", $week)->chance((float) $chances[$index])) {
                continue;
            }

            $booking = $this->rematchBooking($clause->former_ids, $clause->champion_ids, $clause->championship_id);
            $position = $open[0];

            if ($booking !== null && $this->tryBook($position, $booking)) {
                $open = array_values(array_diff($open, [$position]));
            }
        }

        return $open;
    }

    /**
     * A title match, former champions vs new champions, in a 1v1 or 2v2
     * type where they're all fit to go.
     *
     * @param  list<int>  $former
     * @param  list<int>  $champions
     */
    private function rematchBooking(array $former, array $champions, int $championshipId): ?Booking
    {
        $pool = $this->pool();
        $ids = [...$former, ...$champions];
        $everyone = $pool->filter(fn (SaveWrestler $w): bool => in_array($w->id, $ids, true))->values();

        if ($everyone->count() !== count($ids)) {
            return null;
        }

        $people = $everyone->first()?->wrestler->isDiva() ? $this->divas($pool) : $this->men($pool);
        [$limit, $aggressive] = $this->rollMode();
        $shape = [count($former), count($champions)];

        $types = $this->randomizer->eligibleTypes($this->show, $this->brand)
            ->filter(fn (MatchType $t): bool => $t->titles_allowed && ! $t->isMoneyInTheBank()
                && $t->sides === $shape
                && $this->randomizer->poolFor($t, $everyone)->count() === count($ids)
                && $this->fit($everyone, $t, $limit)->count() === count($ids)
                && $people->count() >= count($ids) + count($t->manager_sides) + ($t->hasSpecialReferee() ? 1 : 0))
            ->values();

        foreach ($this->typeOrder($types, $aggressive) as $type) {
            $sides = [$former, $champions];
            $managers = $this->pickManagers($type, $people, $sides);

            try {
                $referee = $this->randomizer->pickReferee($type, $people->reject(fn (SaveWrestler $w): bool => in_array($w->id, $managers, true))->values(), $sides, $this->rng);
            } catch (GameRuleException) {
                continue;
            }

            return new Booking($type, $sides, $managers, $championshipId, refereeId: $referee);
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

        $save = $this->show->game;
        $options = [];

        foreach (Championship::where('brand', $this->brand)->orderBy('sort_order')->get() as $championship) {
            $reign = $this->titles->currentReign($save, $championship);

            if ($reign === null || $championship->is_tag !== $type->isTwoOnTwo()
                || $this->contention->contendership($save, $championship->id) !== null) {
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

                // House rule: the contender side holds no title of its own.
                if ($wrestlers->contains(fn (SaveWrestler $w): bool => ! $championship->allowsWrestler($w->wrestler) || $this->contention->holdsAnyTitle($save, $w->id))
                    || $this->lastChance->isLockedOut($reign, $contenders)) {
                    continue;
                }

                $options[] = $championship->id;
            }
        }

        // A champion with several titles puts any one of them up (house rule).
        return $options === []
            ? null
            : new Booking($type, $booking->sides, $booking->managers, null, Stipulation::ChampionVsContender, (int) $this->rng->pick($options), $booking->refereeId);
    }

    /**
     * A non-title match whose sides fit a title (one per side for a singles
     * title, 2 vs 2 or 2 vs 2 vs 2 for a tag title) may become that title's
     * #1 Contender's Match (house rule, 2026-11-01). Nobody in it may hold
     * the title, and the title can't already have a shot waiting.
     */
    private function numberOneContender(Booking $booking): ?Booking
    {
        $type = $booking->matchType;
        $sides = array_values(array_unique($type->sides));

        if ($booking->titleChampionshipId !== null || $booking->stipulation !== null || count($sides) !== 1) {
            return null;
        }

        $save = $this->show->game;
        $competitors = $booking->competitorIds();
        $wrestlers = SaveWrestler::with('wrestler')->whereIn('id', $competitors)->get();
        $options = [];

        foreach (Championship::where('brand', $this->brand)->orderBy('sort_order')->get() as $championship) {
            $fits = $championship->is_tag
                ? $sides[0] === 2 && count($type->sides) <= 3
                : $sides[0] === 1;
            $reign = $fits ? $this->titles->currentReign($save, $championship) : null;

            if ($reign === null || array_intersect($competitors, $reign->holderIds()) !== []
                || $wrestlers->contains(fn (SaveWrestler $w): bool => ! $championship->allowsWrestler($w->wrestler))
                || $this->lastChance->isLockedOut($reign, $competitors)
                || $this->contention->contendership($save, $championship->id) !== null
                || $this->contention->earnedShot($save, $championship->id) !== null) {
                continue;
            }

            $options[] = $championship->id;
        }

        // Rolled only when a title fits, so other cards draw as before.
        if ($options === [] || ! $this->rng->chance((float) config('gm.ai.titles.number_one_contender_chance'))) {
            return null;
        }

        return new Booking($type, $booking->sides, $booking->managers, null, Stipulation::NumberOneContender, (int) $this->rng->pick($options), $booking->refereeId);
    }

    /**
     * Once per card, a Winner Takes All between two (or three) of the
     * brand's singles champions, every singles title they hold on the line
     * (house rule, 2026-11-01). Never the main event; never a title already
     * on the card or one with a shot waiting.
     *
     * @param  list<int>  $open
     * @return list<int>
     */
    private function bookWinnerTakesAll(array $open): array
    {
        $positions = array_values(array_diff($open, [$this->mainEventPosition()]));

        if ($positions === []) {
            return $open;
        }

        $save = $this->show->game;
        $champions = $this->men($this->pool())->filter(function (SaveWrestler $w) use ($save): bool {
            $held = $this->contention->contestableHeld($save, $w->id)->filter(fn (Championship $c): bool => $c->brand === $this->brand);

            return $held->isNotEmpty() && $held->every(fn (Championship $c): bool => ! in_array($c->id, $this->titlesOnCard, true)
                && $this->contention->contendership($save, $c->id) === null
                && $this->contention->earnedShot($save, $c->id) === null);
        })->values();

        // Rolled only with two champions to put in it, so other cards draw as before.
        $chance = (float) config('gm.ai.titles.winner_takes_all_chance.'.($this->show->isPpv() ? 'ppv' : 'weekly'));

        if ($champions->count() < 2 || ! $this->rng->chance($chance)) {
            return $open;
        }

        $count = $champions->count() >= 3 && $this->rng->chance((float) config('gm.ai.titles.winner_takes_all_triple_chance')) ? 3 : 2;
        [$limit, $aggressive] = $this->rollMode();
        $people = $this->men($this->pool());
        $types = $this->randomizer->eligibleTypes($this->show, $this->brand)
            ->filter(fn (MatchType $t): bool => $t->titles_allowed && ! $t->isMoneyInTheBank() && $t->sides === array_fill(0, $count, 1)
                && $this->fit($champions, $t, $limit)->count() >= $count)
            ->values();

        foreach ($this->typeOrder($types, $aggressive) as $type) {
            $picked = array_slice($this->rng->shuffle($this->ids($this->fit($champions, $type, $limit))), 0, $count);
            $sides = array_map(fn (int $id): array => [$id], $picked);
            $managers = $this->pickManagers($type, $people, $sides);

            try {
                $referee = $this->randomizer->pickReferee($type, $people->reject(fn (SaveWrestler $w): bool => in_array($w->id, $managers, true))->values(), $sides, $this->rng);
            } catch (GameRuleException) {
                continue;
            }

            $booking = new Booking($type, $sides, $managers, null, Stipulation::WinnerTakesAll, refereeId: $referee);
            $position = $this->rng->pick($positions);

            if ($this->tryBook($position, $booking)) {
                $this->titlesOnCard = [...$this->titlesOnCard, ...$this->contention->contested($save, $booking)];

                return array_values(array_diff($open, [$position]));
            }
        }

        return $open;
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

    /**
     * Titles whose #1 contendership is held for the brand's PPV coming up
     * within `weeks_before` (house rule, 2026-11-13).
     *
     * @return list<int>
     */
    private function heldTitles(): array
    {
        if ($this->stipulations->upcomingPpv($this->show, $this->brand) === null) {
            return [];
        }

        return array_values(array_map('intval', $this->contention->contenderships($this->show->game)
            ->filter(fn (TitleShot $shot): bool => $shot->brand === $this->brand)
            ->pluck('championship_id')
            ->all()));
    }

    /**
     * On its own PPV, every waiting #1 contendership is paid off first: the
     * title match against exactly the contender(s) (house rule, 2026-11-13).
     * A world title one takes the main event.
     *
     * @param  list<int>  $open
     * @return list<int>
     */
    private function bookContenderPayoffs(array $open): array
    {
        if (! $this->show->isPpv() || $this->show->brand !== $this->brand) {
            return $open;
        }

        $save = $this->show->game;

        foreach (Championship::where('brand', $this->brand)->orderBy('sort_order')->get() as $championship) {
            $shot = $this->contention->contendership($save, $championship->id);
            $reign = $shot === null ? null : $this->titles->currentReign($save, $championship);

            if ($open === [] || $shot === null || $reign === null || in_array($championship->id, $this->titlesOnCard, true)) {
                continue;
            }

            $type = $this->plainType($championship->is_tag);
            $main = $this->mainEventPosition();
            $position = AiStipulationPlanner::tier($championship) === 'world' && $main !== null && in_array($main, $open, true) ? $main : $this->pickPosition($open);

            if ($this->tryBook($position, new Booking($type, [$reign->holderIds(), $shot->contender_ids], [], $championship->id))) {
                $open = array_values(array_diff($open, [$position]));
            }
        }

        return $open;
    }

    /**
     * Morale (house rule, 2026-11-13; varied 2026-11-17): for each of its
     * superstars with a complaint, most upset first, it rolls to fix one
     * (Content and Unsatisfied 75%, Upset 95%). No title match: the ones
     * waiting are grouped against a free champion, up to 3 in one title
     * match (a Triple Threat or Fatal 4-Way when there are 2 or 3), in any
     * type the title can be defended in. Unused: a match of any one-on-one
     * type. A losing streak: a tag match beside a partner on a win streak,
     * else managing that partner in a w/ Manager match, else a one-on-one,
     * always booked to win. Contract demands are renewed in roster upkeep.
     *
     * @param  list<int>  $open
     * @return list<int>
     */
    private function bookMoraleFixes(array $open): array
    {
        $rng = $this->show->game->rng("ai.morale.{$this->show->streamKey()}", $this->show->week_number);
        $pool = $this->pool();
        $pool->load('activeComplaints');
        $fixable = fn (SaveWrestler $w): Collection => $w->activeComplaints
            ->reject(fn (MoraleComplaint $c): bool => $c->type === MoraleComplaintType::ContractExtension)
            ->sortByDesc(fn (MoraleComplaint $c): int => self::levelRank($c->level) * 10 + self::fixOrder($c->type))
            ->values();
        $complaining = $pool
            ->filter(fn (SaveWrestler $w): bool => $fixable($w)->isNotEmpty())
            ->sortByDesc(fn (SaveWrestler $w): int => self::levelRank($fixable($w)->first()->level) * 100000 - $w->id)
            ->values();

        // Who it fixes this card: each rolls on its worst complaint.
        $fixes = [];

        foreach ($complaining as $wrestler) {
            $worst = $fixable($wrestler)->first();

            if ($rng->chance((float) config('gm.ai.morale.fix_chance.'.$worst->level->value))) {
                $fixes[] = [$wrestler, $worst->type];
            }
        }

        $waiting = new Collection(array_values(array_map(
            fn (array $fix): SaveWrestler => $fix[0],
            array_filter($fixes, fn (array $fix): bool => $fix[1] === MoraleComplaintType::TitleMatch),
        )));
        $open = $this->bookTitleContenders($open, $waiting);

        foreach ($fixes as [$wrestler, $type]) {
            if ($open === [] || $type === MoraleComplaintType::TitleMatch || in_array($wrestler->id, $this->taken, true)) {
                continue;
            }

            $position = $this->pickPosition($open);

            if ($this->fixComplaint($position, $wrestler, $type, $rng)) {
                $open = array_values(array_diff($open, [$position]));
            }
        }

        return $open;
    }

    private function fixComplaint(int $position, SaveWrestler $wrestler, MoraleComplaintType $type, Rng $rng): bool
    {
        // House rule (2026-11-17): a losing streak ends beside a hot partner.
        if ($type === MoraleComplaintType::LosingStreak && $this->hotPartnerFix($position, $wrestler)) {
            return true;
        }

        $limit = (int) config('gm.ai.booking.careful_max_fatigue');
        $people = $wrestler->wrestler->isDiva() ? $this->divas($this->pool()) : $this->men($this->pool());
        $opponents = $people
            ->filter(fn (SaveWrestler $w): bool => $w->id !== $wrestler->id && ($this->fatigue[$w->id] ?? 0) <= $limit)
            ->values();

        if ($opponents->isEmpty()) {
            return false;
        }

        $opponent = $rng->pick($opponents->all());
        $booking = $this->pairBooking([(int) $wrestler->id, (int) $opponent->id]);

        if ($booking === null || ! $this->tryBook($position, $booking)) {
            return false;
        }

        // A losing streak: the rival's Book to Win, so it ends tonight.
        if ($type === MoraleComplaintType::LosingStreak) {
            $this->bookToWin($position, $booking, (int) $wrestler->id);
        }

        return true;
    }

    /**
     * Title matches for superstars waiting on one (house rule, 2026-11-17):
     * for each of its singles titles whose champion is free, fresh enough,
     * not owed to a #1 contender or an earned shot, and not in a rivalry,
     * up to `gm.ai.morale.title_group_max` (3) of those waiting who can
     * challenge for it go in one match against the champion: a one-on-one
     * for one, a Triple Threat for two, a Fatal 4-Way for three, in any type
     * the title can be defended in (fewer when no type fits them all).
     *
     * @param  list<int>  $open
     * @param  Collection<int, SaveWrestler>  $waiting
     * @return list<int>
     */
    private function bookTitleContenders(array $open, Collection $waiting): array
    {
        $save = $this->show->game;
        $limit = (int) config('gm.ai.booking.careful_max_fatigue');
        $max = max(1, (int) config('gm.ai.morale.title_group_max'));
        $inRivalries = array_values(array_map('intval', Rivalry::where('save_id', $save->id)->where('active', true)->get()
            ->flatMap(fn (Rivalry $r): array => [$r->wrestler_a_id, $r->wrestler_b_id])->all()));

        foreach (Championship::where('brand', $this->brand)->where('is_tag', false)->orderBy('sort_order')->get() as $championship) {
            if ($open === [] || $waiting->isEmpty()) {
                break;
            }

            $reign = $this->titles->currentReign($save, $championship);
            $holders = $reign?->holderIds() ?? [];

            if ($reign === null || count($holders) !== 1
                || in_array($championship->id, $this->titlesOnCard, true)
                || in_array($holders[0], $this->taken, true)
                || in_array($holders[0], $inRivalries, true)
                || $this->contention->contendership($save, $championship->id) !== null
                || $this->contention->earnedShot($save, $championship->id) !== null) {
                continue;
            }

            $champion = $this->pool()->firstWhere('id', $holders[0]);
            $challengers = $waiting
                ->filter(fn (SaveWrestler $w): bool => ! in_array($w->id, $this->taken, true)
                    && $w->id !== $holders[0]
                    && $championship->allowsWrestler($w->wrestler)
                    && ! $this->lastChance->isLockedOut($reign, [(int) $w->id]))
                ->values();

            if ($champion === null || $challengers->isEmpty()) {
                continue;
            }

            $challengers = new Collection(array_slice($this->rng->shuffle($challengers->all()), 0, $max));
            $booking = null;

            // As many of them as one type of match can take, down to one.
            for ($n = $challengers->count(); $n >= 1 && $booking === null; $n--) {
                $booking = $this->titleGroupBooking($championship, $champion, $challengers->take($n)->values(), $limit);
            }

            if ($booking === null) {
                continue;
            }

            $position = $this->pickPosition($open);

            if ($this->tryBook($position, $booking)) {
                $open = array_values(array_diff($open, [$position]));
                $booked = $booking->competitorIds();
                $waiting = $waiting->reject(fn (SaveWrestler $w): bool => in_array((int) $w->id, $booked, true))->values();
            }
        }

        return $open;
    }

    /**
     * The champion against the challengers, one a side, in a type drawn at
     * random among those the title can be on the line in that fit them all.
     *
     * @param  Collection<int, SaveWrestler>  $challengers
     */
    private function titleGroupBooking(Championship $championship, SaveWrestler $champion, Collection $challengers, int $limit): ?Booking
    {
        $everyone = new Collection([$champion, ...$challengers->all()]);
        $people = $champion->wrestler->isDiva() ? $this->divas($this->pool()) : $this->men($this->pool());
        $count = $everyone->count();

        $types = $this->randomizer->eligibleTypes($this->show, $this->brand)
            ->filter(fn (MatchType $t): bool => $t->titles_allowed
                && ! $t->isMoneyInTheBank()
                && count($t->sides) === $count
                && $t->sides !== [] && max($t->sides) === 1
                && $this->randomizer->poolFor($t, $everyone)->count() === $count
                && $this->fit($everyone, $t, $limit)->count() === $count
                && $people->count() >= $count + count($t->manager_sides) + ($t->hasSpecialReferee() ? 1 : 0))
            ->values();

        foreach ($this->typeOrder($types, false) as $type) {
            $sides = [[(int) $champion->id], ...array_map(fn (SaveWrestler $w): array => [(int) $w->id], $challengers->all())];
            $managers = $this->pickManagers($type, $people, $sides);

            try {
                $referee = $this->randomizer->pickReferee($type, $people->reject(fn (SaveWrestler $w): bool => in_array($w->id, $managers, true))->values(), $sides, $this->rng);
            } catch (GameRuleException) {
                continue;
            }

            return new Booking($type, $sides, $managers, $championship->id, refereeId: $referee);
        }

        return null;
    }

    /**
     * A losing streak (house rule, 2026-11-17): team the superstar with a
     * partner on a win streak of `gm.ai.morale.hot_partner_streak` (3) or
     * more in a two-on-two of any type, else have them manage that partner
     * in a one-on-one w/ Manager match. Either way it's booked to win.
     */
    private function hotPartnerFix(int $position, SaveWrestler $wrestler): bool
    {
        $limit = (int) config('gm.ai.booking.careful_max_fatigue');
        $min = (int) config('gm.ai.morale.hot_partner_streak');
        $people = ($wrestler->wrestler->isDiva() ? $this->divas($this->pool()) : $this->men($this->pool()))
            ->reject(fn (SaveWrestler $w): bool => $w->id === $wrestler->id)
            ->values();
        $partners = $people->filter(fn (SaveWrestler $w): bool => $w->win_streak >= $min)
            ->sortByDesc('win_streak')
            ->values();
        $id = (int) $wrestler->id;

        foreach ($partners as $partner) {
            $booking = $this->hotTagBooking($wrestler, $partner, $people, $limit)
                ?? $this->hotManagerBooking($wrestler, $partner, $people, $limit);

            if ($booking !== null && $this->tryBook($position, $booking)) {
                $this->bookToWin($position, $booking, $id);

                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, SaveWrestler>  $people  same gender, the superstar left out
     */
    private function hotTagBooking(SaveWrestler $wrestler, SaveWrestler $partner, Collection $people, int $limit): ?Booking
    {
        $team = new Collection([$wrestler, $partner]);
        $types = $this->randomizer->eligibleTypes($this->show, $this->brand)
            ->filter(fn (MatchType $t): bool => $t->sides === [2, 2]
                && ! $t->isMoneyInTheBank()
                && $this->randomizer->poolFor($t, $team)->count() === 2
                && $this->fit($team, $t, $limit)->count() === 2)
            ->values();

        foreach ($this->typeOrder($types, false) as $type) {
            $opponents = $this->fit($this->randomizer->poolFor($type, $people), $type, $limit)
                ->reject(fn (SaveWrestler $w): bool => $w->id === $partner->id)
                ->values();

            if ($opponents->count() < 2) {
                continue;
            }

            $them = array_map('intval', $this->rng->sample($this->ids($opponents), 2));
            $sides = [[(int) $wrestler->id, (int) $partner->id], $them];
            $helpers = $people->reject(fn (SaveWrestler $w): bool => in_array((int) $w->id, [...$sides[0], ...$them], true))->values();
            $managers = $this->pickManagers($type, $helpers, $sides);

            try {
                $referee = $this->randomizer->pickReferee($type, $helpers->reject(fn (SaveWrestler $w): bool => in_array($w->id, $managers, true))->values(), $sides, $this->rng);
            } catch (GameRuleException) {
                continue;
            }

            return new Booking($type, $sides, $managers, refereeId: $referee);
        }

        return null;
    }

    /**
     * @param  Collection<int, SaveWrestler>  $people  same gender, the superstar left out
     */
    private function hotManagerBooking(SaveWrestler $wrestler, SaveWrestler $partner, Collection $people, int $limit): ?Booking
    {
        $types = $this->randomizer->eligibleTypes($this->show, $this->brand)
            ->filter(fn (MatchType $t): bool => $t->isOneOnOne()
                && in_array(0, $t->manager_sides, true)
                && $this->randomizer->poolFor($t, new Collection([$partner]))->count() === 1
                && $this->fit(new Collection([$partner]), $t, $limit)->count() === 1)
            ->values();

        foreach ($this->typeOrder($types, false) as $type) {
            $opponents = $this->fit($this->randomizer->poolFor($type, $people), $type, $limit)
                ->reject(fn (SaveWrestler $w): bool => $w->id === $partner->id)
                ->values();

            if ($opponents->isEmpty()) {
                continue;
            }

            $opponent = (int) $this->rng->pick($this->ids($opponents));
            $sides = [[(int) $partner->id], [$opponent]];
            $helpers = $people->reject(fn (SaveWrestler $w): bool => in_array((int) $w->id, [(int) $partner->id, $opponent], true))->values();
            // The superstar manages their partner's side; any other managed
            // side gets a manager of its own.
            $managers = [0 => (int) $wrestler->id] + $this->pickManagers($type, $helpers, $sides);

            if (count(array_unique($managers)) !== count($managers)) {
                continue;
            }

            try {
                $referee = $this->randomizer->pickReferee($type, $helpers->reject(fn (SaveWrestler $w): bool => in_array($w->id, $managers, true))->values(), $sides, $this->rng);
            } catch (GameRuleException) {
                continue;
            }

            return new Booking($type, $sides, $managers, refereeId: $referee);
        }

        return null;
    }

    /**
     * The rival's Book to Win: the side the superstar is on, or manages.
     */
    private function bookToWin(int $position, Booking $booking, int $id): void
    {
        foreach ($booking->sides as $index => $side) {
            if (in_array($id, $side, true) || ($booking->managers[$index] ?? null) === $id) {
                MatchSlot::where('show_id', $this->show->id)->where('position', $position)->update(['booked_side' => $index]);

                return;
            }
        }
    }

    /**
     * Planned stipulations (house rule, 2026-11-13): the #1 Contender's
     * Match in the run-up to its own PPV, and Loser Leaves Show and Last
     * Chance when a roll is won and one is eligible.
     *
     * @param  list<int>  $open
     * @return list<int>
     */
    private function bookPlannedStipulations(array $open): array
    {
        if ($this->show->isInterbrand()) {
            return $open;
        }

        $save = $this->show->game;
        $week = $this->show->week_number;
        $ppv = $this->stipulations->upcomingPpv($this->show, $this->brand);

        if ($open !== [] && $ppv !== null && ! $this->stipulations->contenderBookedFor($save, $this->brand, $ppv)) {
            $position = $this->pickPosition($open);

            if ($this->bookPpvContender($position)) {
                $this->stipulations->contenderBooked($save, $this->brand, $ppv);
                $open = array_values(array_diff($open, [$position]));
            }
        }

        if ($open !== [] && $this->stipulations->wants($save, $this->brand, AiStipulationPlanner::LAST_CHANCE, $week)) {
            $position = $this->pickPosition($open);
            $booking = $this->lastChanceBooking();

            if ($booking !== null && $this->tryBook($position, $booking)) {
                $this->stipulations->booked($save, $this->brand, AiStipulationPlanner::LAST_CHANCE, $week);
                $open = array_values(array_diff($open, [$position]));
            }
        }

        if ($open !== [] && $this->stipulations->wants($save, $this->brand, AiStipulationPlanner::LOSER_LEAVES, $week)) {
            $position = $this->pickPosition($open);
            $booking = $this->loserLeavesBooking();

            if ($booking !== null && $this->tryBook($position, $booking)) {
                $this->stipulations->booked($save, $this->brand, AiStipulationPlanner::LOSER_LEAVES, $week);
                $open = array_values(array_diff($open, [$position]));
            }
        }

        return $open;
    }

    /**
     * Loser Leaves Show between its two least valuable superstars of one
     * gender (trade value; never champions or Legends).
     */
    private function loserLeavesBooking(): ?Booking
    {
        $save = $this->show->game;
        $limit = (int) config('gm.ai.booking.careful_max_fatigue');
        $pool = $this->pool()->filter(fn (SaveWrestler $w): bool => ! $w->wrestler->isLegend()
            && ($this->fatigue[$w->id] ?? 0) < $limit
            && ! $this->contention->holdsAnyTitle($save, (int) $w->id));

        foreach ([$this->men($pool), $this->divas($pool)] as $people) {
            if ($people->count() < 2) {
                continue;
            }

            $pair = $people
                ->sortBy(fn (SaveWrestler $w): int => $this->judge->value($save, $this->brand, [(int) $w->id]) * 100000 + $w->id)
                ->take(2)
                ->map(fn (SaveWrestler $w): int => (int) $w->id)
                ->values()
                ->all();
            $booking = $this->pairBooking([$pair[0], $pair[1]]);

            if ($booking !== null && $booking->matchType->allowsStipulation(Stipulation::LoserLeavesShow)) {
                return new Booking($booking->matchType, $booking->sides, $booking->managers, null, Stipulation::LoserLeavesShow, refereeId: $booking->refereeId);
            }
        }

        return null;
    }

    /**
     * A Last Chance title match against a challenger who qualifies.
     */
    private function lastChanceBooking(): ?Booking
    {
        $save = $this->show->game;
        $limit = (int) config('gm.ai.booking.careful_max_fatigue');

        foreach (Championship::where('brand', $this->brand)->orderBy('sort_order')->get() as $championship) {
            $reign = $this->titles->currentReign($save, $championship);

            if ($reign === null || in_array($championship->id, $this->titlesOnCard, true)) {
                continue;
            }

            $type = $this->plainType($championship->is_tag);

            foreach ($this->lastChance->availableFor($save, $reign) as $option) {
                $everyone = [...$option['champion_ids'], ...$option['contender_ids']];

                if (array_intersect($everyone, $this->taken) !== []
                    || collect($everyone)->contains(fn (int $id): bool => ($this->fatigue[$id] ?? 0) + $type->fatigueCost() > $limit)) {
                    continue;
                }

                return new Booking($type, [$option['champion_ids'], $option['contender_ids']], [], $championship->id, Stipulation::LastChance);
            }
        }

        return null;
    }

    /**
     * The run-up to its own PPV: a #1 Contender's Match for a title drawn by
     * weight, multi-man more often for the secondary and special titles,
     * 1v1 / 2v2 more often for the world and tag titles.
     */
    private function bookPpvContender(int $position): bool
    {
        $save = $this->show->game;
        $rng = $save->rng("ai.contender.{$this->show->streamKey()}", $this->show->week_number);
        $limit = (int) config('gm.ai.booking.careful_max_fatigue');
        $titles = Championship::where('brand', $this->brand)->orderBy('sort_order')->get()
            ->filter(fn (Championship $c): bool => $this->titles->currentReign($save, $c) !== null
                && ! in_array($c->id, $this->titlesOnCard, true)
                && $this->contention->contendership($save, $c->id) === null
                && $this->contention->earnedShot($save, $c->id) === null)
            ->values()
            ->all();

        foreach ($this->stipulations->titleOrder(array_values($titles), $rng) as $championship) {
            $holders = $this->titles->currentReign($save, $championship)?->holderIds() ?? [];
            $people = $this->pool()->filter(fn (SaveWrestler $w): bool => $championship->allowsWrestler($w->wrestler)
                && ! in_array((int) $w->id, $holders, true))->values();
            $size = $championship->is_tag ? 2 : 1;
            $preferMulti = in_array(AiStipulationPlanner::tier($championship), ['second', 'special'], true);
            $multiFirst = $preferMulti === $rng->chance((float) config('gm.ai.stipulations.number_one_contender_ppv.shape_preference'));

            $types = $this->randomizer->eligibleTypes($this->show, $this->brand)
                ->filter(fn (MatchType $t): bool => $t->allowsStipulation(Stipulation::NumberOneContender)
                    && ! $t->isMoneyInTheBank()
                    && count(array_unique($t->sides)) === 1 && $t->sides[0] === $size
                    && count($t->sides) <= ($championship->is_tag ? 3 : 6)
                    && $this->fit($this->randomizer->poolFor($t, $people), $t, $limit)->count() >= $t->competitorCount())
                ->values();
            $ordered = collect($rng->shuffle($types->all()))
                ->sortBy(fn (MatchType $t): int => (count($t->sides) > 2) === $multiFirst ? 0 : 1)
                ->values();

            foreach ($ordered as $type) {
                $candidates = $this->fit($this->randomizer->poolFor($type, $people), $type, $limit);
                $sides = $this->randomizer->fillSides($this->show, $type, $candidates, $rng);
                $inMatch = array_merge(...$sides);
                $managers = $this->pickManagers($type, $this->pool()->reject(fn (SaveWrestler $w): bool => in_array((int) $w->id, $inMatch, true))->values(), $sides);

                try {
                    $referee = $this->randomizer->pickReferee($type, $this->pool()->reject(fn (SaveWrestler $w): bool => in_array((int) $w->id, [...$managers, ...$inMatch], true))->values(), $sides, $rng);
                } catch (GameRuleException) {
                    continue;
                }

                if ($this->tryBook($position, new Booking($type, $sides, $managers, null, Stipulation::NumberOneContender, $championship->id, $referee))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function plainType(bool $tag): MatchType
    {
        return $tag
            ? MatchType::where('family', 'tag_team')->where('variant', 'Normal Tag')->firstOrFail()
            : MatchType::where('family', 'single')->where('variant', 'Single')->firstOrFail();
    }

    /**
     * An open slot that isn't the main event when another is free.
     *
     * @param  list<int>  $open
     */
    private function pickPosition(array $open): int
    {
        $main = $this->mainEventPosition();
        $others = array_values(array_diff($open, [$main]));

        return (int) $this->rng->pick($others !== [] ? $others : $open);
    }

    private static function levelRank(ComplaintLevel $level): int
    {
        return match ($level) {
            ComplaintLevel::Upset => 3,
            ComplaintLevel::Unsatisfied => 2,
            default => 1,
        };
    }

    /**
     * Which complaint it fixes first at the same level.
     */
    private static function fixOrder(MoraleComplaintType $type): int
    {
        return match ($type) {
            MoraleComplaintType::LosingStreak => 3,
            MoraleComplaintType::TitleMatch => 2,
            default => 1,
        };
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
        // House rule (2026-11-17): a qualifier is kept healthy for its PPV,
        // only in a Normal Matches or Tag Team type, at the careful limit.
        $safe = in_array($type->family->value, (array) config('gm.ppv.qualifiers.rival_safe_families'), true);
        $careful = (int) config('gm.ai.booking.careful_max_fatigue');

        return $people->filter(function (SaveWrestler $w) use ($type, $limit, $safe, $careful): bool {
            $after = ($this->fatigue[$w->id] ?? $w->fatigue) + $type->fatigueCost();

            if (in_array((int) $w->id, $this->protected, true)) {
                return $safe && $after <= min($limit, $careful);
            }

            return $after <= $limit;
        })->values();
    }

    /**
     * The superstars it's keeping healthy for a PPV (its qualifiers).
     *
     * @return list<int>
     */
    public function protectedIds(): array
    {
        return $this->protected;
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
