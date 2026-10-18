<?php

namespace App\Services\Card;

use App\Enums\Brand;
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
 */
class CardRandomizer
{
    public function __construct(
        private InjuryStatus $injuries,
        private TitleMatchPlanner $titles,
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
     * @return list<int>
     */
    public function bookedIds(Show $show, ?int $exceptPosition = null): array
    {
        return array_values(array_unique(MatchSlot::with('participants')
            ->where('show_id', $show->id)
            ->when($exceptPosition !== null, fn ($q) => $q->where('position', '!=', $exceptPosition))
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
     */
    public function proposeSlot(Show $show, int $position, ?MatchType $type, Rng $rng, array $exclude = [], array $titlesOnCard = [], ?Brand $brand = null): Proposal
    {
        $brand ??= $show->brand ?? throw new GameRuleException('This slot has no brand to book from.');
        $pool = $this->pool($show, $brand, array_values(array_unique([...$this->bookedIds($show, $position), ...$exclude])));

        if ($pool->count() < 2) {
            throw new GameRuleException("Not enough available wrestlers to book this match (need 2, have {$pool->count()}).");
        }

        if ($type === null) {
            $fillable = $this->eligibleTypes($show, $brand)
                ->filter(fn (MatchType $t): bool => $this->poolFor($t, $pool)->count() >= $t->competitorCount())
                ->values();

            if ($fillable->isEmpty()) {
                throw new GameRuleException("Not enough available wrestlers to book this match (need 2, have {$pool->count()}).");
            }

            $type = $rng->pick($fillable->all());
        }

        $candidates = $this->poolFor($type, $pool);
        $need = $type->competitorCount();

        if ($candidates->count() < $need) {
            throw new GameRuleException("Not enough available wrestlers to book {$type->name} (need {$need}, have {$candidates->count()}).");
        }

        $title = $this->titles->plan($show, $brand, $type, $candidates, $titlesOnCard, $rng);

        if ($title !== null) {
            return new Proposal($position, new Booking($type, $title['sides'], [], $title['championship_id']), $title['sides']);
        }

        $sides = $this->fillSides($show, $type, $candidates, $rng);

        return new Proposal($position, new Booking($type, $sides), $sides);
    }

    /**
     * Preset (§24): fill only empty, non-fixed slots in card order, removing
     * each slot's wrestlers from the pool and never repeating a title.
     *
     * @return array{proposals: list<Proposal>, unfilled_slots: list<int>, total_cost: int, deficit_warning: bool, errors: array<int, string>}
     */
    public function preset(Show $show, Rng $rng, ?Brand $brand = null): array
    {
        $brand ??= $show->brand;
        $slots = MatchSlot::with('participants')->where('show_id', $show->id)->orderBy('position')->get();
        $taken = [];
        $titles = array_values($slots->pluck('title_championship_id')->filter()->map(fn ($id): int => (int) $id)->all());
        $proposals = [];
        $unfilled = [];
        $errors = [];

        foreach ($this->titles->obligations($show, $brand) as $obligation) {
            $empty = $slots->first(fn (MatchSlot $s): bool => ! $s->isFixed() && ! $s->isBooked()
                && ($brand === null || $s->owner_brand === null || $s->owner_brand === $brand)
                && ! in_array($s->position, array_map(fn (Proposal $p): int => $p->position, $proposals), true));

            if ($empty === null) {
                break;
            }

            $proposals[] = new Proposal($empty->position, $obligation, $obligation->sides);
            $taken = [...$taken, ...$obligation->competitorIds()];
            $titles[] = (int) $obligation->titleChampionshipId;
        }

        $usedPositions = array_map(fn (Proposal $p): int => $p->position, $proposals);

        foreach ($slots as $slot) {
            if ($slot->isFixed() || $slot->isBooked() || in_array($slot->position, $usedPositions, true)
                || ($brand !== null && $slot->owner_brand !== null && $slot->owner_brand !== $brand)) {
                continue;
            }

            try {
                $proposal = $this->proposeSlot($show, $slot->position, null, $rng, $taken, $titles, $brand);
                $proposals[] = $proposal;
                $taken = [...$taken, ...$proposal->booking->competitorIds()];

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
    private function fillSides(Show $show, MatchType $type, Collection $candidates, Rng $rng): array
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
