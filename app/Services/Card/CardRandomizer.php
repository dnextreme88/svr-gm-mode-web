<?php

namespace App\Services\Card;

use App\Enums\Brand;
use App\Enums\InjurySeverity;
use App\Enums\RosterType;
use App\Exceptions\GameRuleException;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Models\SaveBrand;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Models\TagTeam;
use App\Services\Injuries\InjuryStatus;
use App\Services\Week\Steps\TrackDeficit;
use App\Support\Rng\Rng;
use Illuminate\Database\Eloquent\Collection;

/**
 * Randomize (one slot) and Preset (whole card) proposals (§24). Both only
 * propose; saving goes through CardService so the normal rules apply.
 *
 * Both keep the roster healthy (house rule): nobody is picked to compete
 * if the match would leave them above `gm.card.randomize_max_fatigue`
 * (counting this week's other bookings and tiring promos), and wrestlers
 * carrying a minor injury are only picked when nobody else is left.
 */
class CardRandomizer
{
    public function __construct(
        private InjuryStatus $injuries,
        private TitleMatchPlanner $titles,
        private PlannedFatigue $plannedFatigue,
    ) {}

    /**
     * The bookable pool: the brand roster minus seriously injured wrestlers,
     * anyone already on the card, and anyone sitting out a blocked defection.
     *
     * @param  list<int>  $exclude
     * @return Collection<int, SaveWrestler>
     */
    public function pool(Show $show, Brand $brand, array $exclude = []): Collection
    {
        $injured = $this->injuries->seriouslyInjuredIds($show->save_id, $show->week_number);

        return SaveWrestler::with('wrestler')
            ->where('save_id', $show->save_id)
            ->where('brand', $brand)
            ->whereNotIn('id', [...$injured, ...$exclude])
            ->where(fn ($q) => $q->whereNull('unbookable_until_week')->orWhere('unbookable_until_week', '<=', $show->week_number))
            ->orderBy('id')
            ->get();
    }

    /**
     * Match types the randomizer may roll for this show.
     *
     * @return Collection<int, MatchType>
     */
    public function eligibleTypes(Show $show, Brand $brand): Collection
    {
        $cheapOnly = SaveBrand::where('save_id', $show->save_id)->where('brand', $brand)->value('deficit_choice') === TrackDeficit::CHOICE_CHEAP_MATCHES;

        return MatchType::query()
            ->where('gm_bookable', true)
            ->where('max_competitors', '<=', (int) config('gm.card.max_competitors'))
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (MatchType $t): bool => (! $t->ppv_only || $show->isPpv())
                && ($t->ppv_exclusive_id === null || $t->ppv_exclusive_id === $show->ppv_event_id)
                && ($t->brand_restriction === null || $t->brand_restriction === $brand)
                && (! $cheapOnly || $t->cost === 0))
            ->values();
    }

    /**
     * Ids already on the card, as competitor or manager.
     *
     * @param  int|list<int>|null  $exceptPositions  slots being rebooked, left out
     * @return list<int>
     */
    public function bookedIds(Show $show, int|array|null $exceptPositions = null): array
    {
        $except = $exceptPositions === null ? [] : (array) $exceptPositions;

        return array_values(array_unique(MatchSlot::with('participants')
            ->where('show_id', $show->id)
            ->when($except !== [], fn ($q) => $q->whereNotIn('position', $except))
            ->get()
            ->flatMap(fn (MatchSlot $s): array => $s->participantIds())
            ->map(fn ($id): int => (int) $id)
            ->all()));
    }

    /**
     * Randomize one slot. Keeps a chosen match type; rolls one if the slot is empty.
     *
     * @param  list<int>  $exclude  extra ids to leave out (Preset's running pool)
     * @param  list<int>  $titlesOnCard  championship ids already defended on the card
     * @param  list<int>  $replacing  other slots rebooked along with this one (Preset), whose bookings don't count
     */
    public function proposeSlot(Show $show, int $position, ?MatchType $type, Rng $rng, array $exclude = [], array $titlesOnCard = [], ?Brand $brand = null, array $replacing = []): Proposal
    {
        $brand ??= $show->brand ?? throw new GameRuleException('This slot has no brand to book from.');
        $rebooked = array_values(array_unique([$position, ...$replacing]));
        $pool = $this->pool($show, $brand, array_values(array_unique([...$this->bookedIds($show, $rebooked), ...$exclude])));

        if ($pool->count() < 2) {
            throw new GameRuleException("Not enough available wrestlers to book this match (need 2, have {$pool->count()}).");
        }

        $fatigue = $this->plannedFatigue->forWeek($show, $brand, $this->slotIds($show, $rebooked));
        $minor = $this->minorInjuredIds($show);

        if ($type === null) {
            $roomy = $this->eligibleTypes($show, $brand)
                ->filter(fn (MatchType $t): bool => $this->poolFor($t, $pool)->count() >= $t->competitorCount()
                    && $pool->count() >= $t->competitorCount() + count($t->manager_sides) + ($t->hasSpecialReferee() ? 1 : 0))
                ->values();

            if ($roomy->isEmpty()) {
                throw new GameRuleException("Not enough available wrestlers to book this match (need 2, have {$pool->count()}).");
            }

            $fillable = $roomy->filter(fn (MatchType $t): bool => $this->healthyFor($t, $pool, $fatigue, $minor)->count() >= $t->competitorCount())->values();

            if ($fillable->isEmpty()) {
                throw new GameRuleException('Everyone available is too tired to book a match without risking an injury.');
            }

            $type = $rng->pick($fillable->all());
        }

        $need = $type->competitorCount();
        $eligible = $this->poolFor($type, $pool)->count();

        if ($eligible < $need) {
            throw new GameRuleException("Not enough available wrestlers to book {$type->name} (need {$need}, have {$eligible}).");
        }

        $candidates = $this->healthyFor($type, $pool, $fatigue, $minor);

        if ($candidates->count() < $need) {
            $max = (int) config('gm.card.randomize_max_fatigue');

            throw new GameRuleException("Not enough fresh wrestlers to book {$type->name} without risking an injury: it would push the others past {$max}% fatigue (need {$need}, have {$candidates->count()}).");
        }

        $title = $this->titles->plan($show, $brand, $type, $candidates, $titlesOnCard, $rng);

        if ($title !== null) {
            $referee = $this->pickReferee($type, $pool, $title['sides'], $rng);
            $managers = $this->pickManagers($type, $pool, $title['sides'], $referee, $rng);

            return new Proposal($position, new Booking($type, $title['sides'], $managers, $title['championship_id'], refereeId: $referee), $title['sides']);
        }

        $sides = $this->fillSides($show, $type, $candidates, $rng);
        $referee = $this->pickReferee($type, $pool, $sides, $rng);
        $managers = $this->pickManagers($type, $pool, $sides, $referee, $rng);

        return new Proposal($position, new Booking($type, $sides, $managers, refereeId: $referee), $sides);
    }

    /**
     * Who can compete in a type without risking an injury: nobody the match
     * would leave above `gm.card.randomize_max_fatigue`, and wrestlers with
     * a minor injury (it can turn serious) only when there aren't enough
     * others.
     *
     * @param  Collection<int, SaveWrestler>  $pool
     * @param  array<int, int>  $fatigue  save-wrestler id => fatigue going in
     * @param  list<int>  $minor  ids carrying a minor injury
     * @return Collection<int, SaveWrestler>
     */
    public function healthyFor(MatchType $type, Collection $pool, array $fatigue, array $minor): Collection
    {
        $max = (int) config('gm.card.randomize_max_fatigue');
        $fresh = $this->poolFor($type, $pool)
            ->filter(fn (SaveWrestler $w): bool => ($fatigue[$w->id] ?? $w->fatigue) + $type->fatigueCost() <= $max)
            ->values();
        $uninjured = $fresh->reject(fn (SaveWrestler $w): bool => in_array($w->id, $minor, true))->values();

        return $uninjured->count() >= $type->competitorCount() ? $uninjured : $fresh;
    }

    /**
     * @param  list<int>  $positions
     * @return list<int>
     */
    private function slotIds(Show $show, array $positions): array
    {
        return array_values(array_map('intval', MatchSlot::where('show_id', $show->id)->whereIn('position', $positions)->pluck('id')->all()));
    }

    /**
     * @return list<int>
     */
    private function minorInjuredIds(Show $show): array
    {
        $ids = [];

        foreach ($this->injuries->activeByWrestler($show->save_id, $show->week_number) as $id => $injury) {
            if ($injury->severity === InjurySeverity::Minor) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * A w/Manager type's managers: random wrestlers left in the pool after
     * the competitors and the referee, one per manager side. Every manager
     * slot is filled, like every competitor slot.
     *
     * @param  Collection<int, SaveWrestler>  $pool
     * @param  list<list<int>>  $sides
     * @return array<int, int> side index => manager id
     */
    public function pickManagers(MatchType $type, Collection $pool, array $sides, ?int $refereeId, Rng $rng): array
    {
        if ($type->manager_sides === []) {
            return [];
        }

        $left = $rng->shuffle(array_values(array_diff(
            array_map('intval', $pool->pluck('id')->all()),
            array_merge(...$sides),
            $refereeId === null ? [] : [$refereeId],
        )));

        if (count($left) < count($type->manager_sides)) {
            throw new GameRuleException("Not enough available wrestlers to book {$type->name} with its managers.");
        }

        $managers = [];

        foreach ($type->manager_sides as $i => $side) {
            $managers[(int) $side] = $left[$i];
        }

        return $managers;
    }

    /**
     * A random special referee from whoever is left in the pool (§10).
     *
     * @param  Collection<int, SaveWrestler>  $pool
     * @param  list<list<int>>  $sides
     */
    public function pickReferee(MatchType $type, Collection $pool, array $sides, Rng $rng): ?int
    {
        if (! $type->hasSpecialReferee()) {
            return null;
        }

        $left = array_values(array_diff(array_map('intval', $pool->pluck('id')->all()), array_merge(...$sides)));

        if ($left === []) {
            throw new GameRuleException("Not enough available wrestlers to book {$type->name} with a special referee.");
        }

        return $rng->pick($left);
    }

    /**
     * Preset (§24): book the card's slots in card order, removing each
     * slot's wrestlers from the pool and never repeating a title. Earned
     * title shots come first. Fixed slots and the other brand's slots are
     * never touched. By default only empty slots are filled (Simulate to
     * here); with `$replace` (the Preset card button, house rule) every
     * other slot is rebooked too, its current booking ignored.
     *
     * @return array{proposals: list<Proposal>, unfilled_slots: list<int>, total_cost: int, deficit_warning: bool, errors: array<int, string>}
     */
    public function preset(Show $show, Rng $rng, ?Brand $brand = null, bool $replace = false): array
    {
        $brand ??= $show->brand;
        $slots = MatchSlot::with('participants')->where('show_id', $show->id)->orderBy('position')->get();
        $open = $slots->filter(fn (MatchSlot $s): bool => ! $s->isFixed() && ($replace || ! $s->isBooked())
            && ($brand === null || $s->owner_brand === null || $s->owner_brand === $brand))->values();
        $replacing = $replace ? array_values(array_map('intval', $open->pluck('position')->all())) : [];
        $taken = [];
        $titles = array_values($slots->reject(fn (MatchSlot $s): bool => in_array($s->position, $replacing, true))
            ->pluck('title_championship_id')->filter()->map(fn ($id): int => (int) $id)->all());
        $proposals = [];
        $unfilled = [];
        $errors = [];

        foreach ($this->titles->obligations($show, $brand, $replacing) as $obligation) {
            $used = array_map(fn (Proposal $p): int => $p->position, $proposals);
            $slot = $open->first(fn (MatchSlot $s): bool => ! in_array($s->position, $used, true));

            if ($slot === null) {
                break;
            }

            $proposals[] = new Proposal($slot->position, $obligation, $obligation->sides, titleShot: true);
            $taken = [...$taken, ...$obligation->competitorIds()];
            $titles[] = (int) $obligation->titleChampionshipId;
        }

        $usedPositions = array_map(fn (Proposal $p): int => $p->position, $proposals);

        foreach ($open as $slot) {
            if (in_array($slot->position, $usedPositions, true)) {
                continue;
            }

            try {
                $proposal = $this->proposeSlot($show, $slot->position, null, $rng, $taken, $titles, $brand, $replacing);
                $proposals[] = $proposal;
                $taken = [...$taken, ...$proposal->booking->allIds()];

                if ($proposal->booking->titleChampionshipId !== null) {
                    $titles[] = $proposal->booking->titleChampionshipId;
                }
            } catch (GameRuleException $e) {
                $unfilled[] = $slot->position;
                $errors[$slot->position] = $e->getMessage();
            }
        }

        if ($proposals === [] && $unfilled !== []) {
            throw new GameRuleException((string) reset($errors));
        }

        usort($proposals, fn (Proposal $a, Proposal $b): int => $a->position <=> $b->position);

        $cost = array_sum(array_map(fn (Proposal $p): int => $p->booking->matchType->cost, $proposals));
        $budget = $brand === null ? 0 : (int) SaveBrand::where('save_id', $show->save_id)->where('brand', $brand)->value('budget');

        return [
            'proposals' => $proposals,
            'unfilled_slots' => $unfilled,
            'total_cost' => $cost,
            'deficit_warning' => $cost > 0 && $budget - $cost < 0,
            'errors' => $errors,
        ];
    }

    /**
     * @param  Collection<int, SaveWrestler>  $pool
     * @return Collection<int, SaveWrestler>
     */
    public function poolFor(MatchType $type, Collection $pool): Collection
    {
        return $pool->filter(function (SaveWrestler $w) use ($type): bool {
            $isDiva = $w->wrestler->roster_type === RosterType::Diva;

            return ($type->divas_only ? $isDiva : true) && ($type->divas_allowed || ! $isDiva);
        })->values();
    }

    /**
     * Fill each side; multi-wrestler sides prefer existing tag teams, then pairs.
     *
     * @param  Collection<int, SaveWrestler>  $candidates
     * @return list<list<int>>
     */
    public function fillSides(Show $show, MatchType $type, Collection $candidates, Rng $rng): array
    {
        $available = array_values(array_map('intval', $candidates->pluck('id')->all()));
        $available = $rng->shuffle($available);
        $teams = $type->isTagStyle() ? $this->teams($show, $available, $rng) : [];
        $sides = [];

        foreach ($type->sides as $size) {
            $side = [];

            if ($size > 1) {
                foreach ($teams as $i => $members) {
                    if (count($members) === $size && array_diff($members, $available) === []) {
                        $side = $members;
                        unset($teams[$i]);
                        break;
                    }
                }
            }

            if ($side === []) {
                $side = array_slice($available, 0, $size);
            }

            $available = array_values(array_diff($available, $side));
            $sides[] = $side;
        }

        return $sides;
    }

    /**
     * Active tag teams whose members are all available, in random order.
     *
     * @param  list<int>  $available
     * @return list<list<int>>
     */
    private function teams(Show $show, array $available, Rng $rng): array
    {
        $teams = TagTeam::with('members')
            ->where('save_id', $show->save_id)
            ->where('active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (TagTeam $t): array => $t->memberIds())
            ->filter(fn (array $ids): bool => array_diff($ids, $available) === [])
            ->values()
            ->all();

        return $rng->shuffle($teams);
    }
}
