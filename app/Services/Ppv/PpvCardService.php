<?php

namespace App\Services\Ppv;

use App\Enums\Brand;
use App\Enums\FixedSlotKind;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\ParticipantRole;
use App\Enums\PpvKey;
use App\Enums\RosterType;
use App\Enums\ShowType;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\MatchParticipant;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Models\NewsEvent;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Ai\AiTradeJudge;
use App\Services\Attendance\SummerSlamMainEvent;
use App\Services\Card\CardRandomizer;
use App\Services\Injuries\InjuryStatus;
use App\Services\News\NewsRecorder;
use App\Services\Titles\TitleService;
use App\Support\Rng\Rng;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Fixed PPV cards (§10, §13, §23): the 30-man Royal Rumble, the Survivor
 * Series 5 vs 5 main event, and the 8-match interbrand WrestleMania card.
 */
class PpvCardService
{
    /** The Royal Rumble winner's brand's WrestleMania slots (house rule, 2026-11-15). */
    public const WM_LEAD_SLOTS = [1, 3];

    /** The other brand's WrestleMania slots. */
    public const WM_OTHER_SLOTS = [2, 4];

    /** The interbrand series, 1v1, 2v2 then Money in the Bank, before the main event. */
    public const WM_SERIES_SLOTS = [5, 6, 7];

    public const WM_MAIN_EVENT = 8;

    /** WrestleMania's match count (house rule, 2026-11-15: 8, was 10). */
    public const WM_MATCHES = 8;

    /**
     * House rule (2026-11-15): SummerSlam, the Royal Rumble and Survivor
     * Series are each one 7-match card both brands book. The brand whose
     * weekly shows drew more fans this season before the PPV
     * (`SummerSlamMainEvent::headliner`) gets the slots below, the other
     * brand the rest. SummerSlam's leader also has the main event; the
     * Royal Rumble and the Survivor Series 5 vs 5 are always Match 7.
     */
    public const SHARED_PPV_MATCHES = 7;

    public const SUMMERSLAM_HEADLINER_SLOTS = [1, 3, 5, 7];

    public const SUMMERSLAM_OTHER_SLOTS = [2, 4, 6];

    public const ROYAL_RUMBLE_LEAD_SLOTS = [1, 2, 6];

    public const SURVIVOR_SERIES_LEAD_SLOTS = [4, 5, 6];

    public function __construct(
        private CardRandomizer $randomizer,
        private TitleService $titles,
        private InjuryStatus $injuries,
        private NewsRecorder $news,
        private SummerSlamMainEvent $summerSlam,
        private AiTradeJudge $judge,
    ) {}

    /**
     * Create the fixed slots a PPV card needs. Safe to call repeatedly.
     */
    public function setUp(Show $show): void
    {
        $show->loadMissing('ppvEvent');

        if ($show->isWrestleMania()) {
            $this->setUpWrestleMania($show);

            return;
        }

        if ($show->isInterbrand() && $show->ppvKey() === PpvKey::Summerslam) {
            $this->setUpSummerSlam($show);

            return;
        }

        if ($show->isInterbrandMainEvent()) {
            $rumble = $show->ppvKey() === PpvKey::RoyalRumble;
            $lead = $this->summerSlam->headliner($show->game, $show->season, $show->week_number);
            $leadSlots = $rumble ? self::ROYAL_RUMBLE_LEAD_SLOTS : self::SURVIVOR_SERIES_LEAD_SLOTS;

            foreach (range(1, self::SHARED_PPV_MATCHES - 1) as $position) {
                MatchSlot::firstOrCreate(['show_id' => $show->id, 'position' => $position], [
                    'owner_brand' => in_array($position, $leadSlots, true) ? $lead : $lead->rival(),
                ]);
            }

            // The Royal Rumble or the 5 vs 5: always the main event, shared.
            MatchSlot::firstOrCreate(['show_id' => $show->id, 'position' => self::SHARED_PPV_MATCHES], [
                'match_type_id' => ($rumble ? $this->type('royal_rumble', '30-Man') : $this->type('survivor_series_elimination', 'Traditional 5 vs 5'))->id,
                'fixed_kind' => $rumble ? FixedSlotKind::RoyalRumble : FixedSlotKind::SurvivorSeriesMainEvent,
                'cost' => 0,
            ]);
        }
    }

    /**
     * Royal Rumble entrants (15 per brand) or a Survivor Series team (5).
     *
     * @param  list<int>  $ids
     */
    public function setEntrants(Show $show, Brand $brand, array $ids): void
    {
        $slot = $this->mainEventSlot($show);
        $rumble = $slot->fixed_kind === FixedSlotKind::RoyalRumble;
        $needed = $rumble ? $this->rumbleEntrantsFor($show, $brand) : (int) config('gm.ppv.survivor_series_team_size');
        $ids = array_values(array_unique($ids));

        if (count($ids) !== $needed) {
            throw new GameRuleException($rumble ? "Pick {$needed} Royal Rumble entrants." : "Pick a team of {$needed}.");
        }

        $pool = $this->randomizer->pool($show, $brand)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        foreach ($ids as $id) {
            if (! in_array($id, $pool, true)) {
                throw new GameRuleException('Entrants must be bookable superstars on your roster.');
            }
        }

        // House rule: Divas can enter neither the Royal Rumble nor Survivor Series.
        $divas = SaveWrestler::with('wrestler')->whereIn('id', $ids)->get()->filter(fn (SaveWrestler $w): bool => $w->wrestler->roster_type === RosterType::Diva);

        if ($divas->isNotEmpty()) {
            throw new GameRuleException($rumble ? 'Royal Rumble entrants are Superstars and Legends.' : 'The Survivor Series teams are Superstars and Legends.');
        }

        $existing = $slot->participants()->with('saveWrestler')->get();
        $existing->filter(fn (MatchParticipant $p): bool => $p->saveWrestler->brand === $brand)->each->delete();

        $side = $rumble ? ((int) MatchParticipant::where('match_slot_id', $slot->id)->max('side')) + 1 : ($brand === Brand::Raw ? 0 : 1);

        if ($rumble && ! MatchParticipant::where('match_slot_id', $slot->id)->exists()) {
            $side = 0;
        }

        foreach ($ids as $id) {
            MatchParticipant::create([
                'match_slot_id' => $slot->id,
                'save_wrestler_id' => $id,
                'side' => $rumble ? $side++ : $side,
                'role' => ParticipantRole::Competitor,
            ]);
        }

        if (! $rumble && $this->entrantsSet($show, Brand::Raw) && $this->entrantsSet($show, Brand::Smackdown)) {
            $captains = $this->captains($slot->fresh('participants.saveWrestler.wrestler'));

            $this->news->record($show->game, NewsEventType::SurvivorSeriesTeams, NewsTier::Story, NewsBrand::Both, [
                'captain_a' => $captains[0] ?? 'Raw',
                'captain_b' => $captains[1] ?? 'Smackdown',
            ], [], priority: 40, week: $show->week_number, dedupeKey: "survivor:{$show->id}");
        }
    }

    /**
     * Fill a brand's entrants at random (AI, or the Random button).
     */
    public function randomEntrants(Show $show, Brand $brand, Rng $rng): void
    {
        $slot = $this->mainEventSlot($show);
        $rumble = $slot->fixed_kind === FixedSlotKind::RoyalRumble;
        $pool = $this->entrantPool($show, $brand);
        $needed = $rumble ? $this->rumbleEntrantsFor($show, $brand) : (int) config('gm.ppv.survivor_series_team_size');

        if ($pool->count() < $needed) {
            throw new GameRuleException("Not enough bookable superstars (need {$needed}, have {$pool->count()}).");
        }

        $this->setEntrants($show, $brand, array_map('intval', $rng->sample($pool->pluck('id')->all(), $needed)));
    }

    /**
     * The rival GM's entrants (house rule, 2026-11-15). For the Royal Rumble
     * it sends non-champions first, at random; when it has fewer than 15 it
     * adds champions, first those not booked on its other cards of the
     * Royal Rumble night, then those who are. Survivor Series stays random.
     */
    public function aiEntrants(Show $show, Brand $brand, Rng $rng): void
    {
        $slot = $this->mainEventSlot($show);

        if ($slot->fixed_kind !== FixedSlotKind::RoyalRumble) {
            $this->randomEntrants($show, $brand, $rng);

            return;
        }

        $needed = $this->rumbleEntrantsFor($show, $brand);
        $champions = $this->titles->currentReigns($show->game)->flatMap(fn ($r) => $r->holderIds())->map(fn ($id): int => (int) $id)->all();
        $booked = MatchParticipant::whereHas('matchSlot', fn ($q) => $q->where('id', '!=', $slot->id)
            ->whereHas('show', fn ($s) => $s->where('save_id', $show->save_id)->where('week_number', $show->week_number)->where('show_type', ShowType::Ppv)))
            ->pluck('save_wrestler_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $ids = $this->entrantPool($show, $brand)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $tiers = [
            array_values(array_diff($ids, $champions)),
            array_values(array_diff(array_intersect($ids, $champions), $booked)),
            array_values(array_intersect($ids, $champions, $booked)),
        ];
        $picked = [];

        foreach ($tiers as $tier) {
            $left = $needed - count($picked);

            if ($left <= 0) {
                break;
            }

            array_push($picked, ...array_map('intval', $rng->sample($tier, min($left, count($tier)))));
        }

        $this->setEntrants($show, $brand, $picked);
    }

    public function entrantsSet(Show $show, Brand $brand): bool
    {
        $slot = $this->mainEventSlot($show);

        return $slot->participants()->whereHas('saveWrestler', fn ($q) => $q->where('brand', $brand))->exists();
    }

    /**
     * 15 per brand, or every bookable wrestler a short-handed brand has (§10).
     */
    public function rumbleEntrantsFor(Show $show, Brand $brand): int
    {
        $wanted = (int) config('gm.ppv.royal_rumble_entrants_per_brand');

        return min($wanted, $this->entrantPool($show, $brand)->count());
    }

    /**
     * Bookable wrestlers who can enter the Royal Rumble or a Survivor Series
     * team: never Divas (house rule).
     *
     * @return Collection<int, SaveWrestler>
     */
    public function entrantPool(Show $show, Brand $brand): Collection
    {
        return $this->randomizer->pool($show, $brand)
            ->reject(fn (SaveWrestler $w): bool => $w->wrestler->roster_type === RosterType::Diva)
            ->values();
    }

    public function mainEventSlot(Show $show): MatchSlot
    {
        $this->setUp($show);

        return MatchSlot::with('participants')->where('show_id', $show->id)
            ->whereIn('fixed_kind', [FixedSlotKind::RoyalRumble, FixedSlotKind::SurvivorSeriesMainEvent])
            ->firstOrFail();
    }

    /**
     * The WrestleMania interbrand series (house rule, 2026-11-15): 1v1, 2v2,
     * then the Money in the Bank ladder match. Each brand picks its own
     * entrants. The rival GM picks its most valuable bookable non-champions
     * (`AiTradeJudge`, ties to the seeded RNG) as soon as the card is set up;
     * the player's spots stay empty for them to fill on the card.
     */
    public function drawSeries(Show $show, Rng $rng): void
    {
        $rival = $show->game->rivalBrand();

        foreach ($this->seriesSlots($show) as $slot) {
            $sides = self::seriesSides($slot->position, $rival);

            if ($sides === [] || $this->spotIds($slot, $rival) !== []) {
                continue;
            }

            $picked = [];

            foreach ($sides as $_) {
                $pool = $this->seriesPool($show, $slot, $rival)->reject(fn (SaveWrestler $w): bool => in_array((int) $w->id, $picked, true));
                $best = $this->bestOf($show, $rival, $pool, $rng);

                if ($best === null) {
                    break;
                }

                $picked[] = (int) $best->id;
            }

            if (count($picked) === count($sides)) {
                $this->placeSeries($show, $slot, $sides, $picked);
            }
        }
    }

    /**
     * Fill a brand's empty series spots at random (Simulate to here, and a
     * week resolved without the player picking: tests).
     */
    public function fillSeries(Show $show, Brand $brand, Rng $rng): void
    {
        foreach ($this->seriesSlots($show) as $slot) {
            $sides = self::seriesSides($slot->position, $brand);

            if ($sides === [] || $this->spotIds($slot, $brand) !== []) {
                continue;
            }

            $pool = $this->seriesPool($show, $slot, $brand)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

            if (count($pool) >= count($sides)) {
                $this->placeSeries($show, $slot, $sides, array_map('intval', $rng->sample($pool, count($sides))));
            }
        }
    }

    /**
     * Series matches where a brand still has empty spots, for the air check.
     *
     * @return list<int> positions
     */
    public function seriesMissing(Show $show, Brand $brand): array
    {
        if (! $show->isWrestleMania()) {
            return [];
        }

        $missing = [];

        foreach ($this->seriesSlots($show) as $slot) {
            if (count($this->spotIds($slot, $brand)) < count(self::seriesSides($slot->position, $brand))) {
                $missing[] = $slot->position;
            }
        }

        return $missing;
    }

    /**
     * A brand's spots (side indexes) in a series match: its side in the 1v1
     * and the 2v2 (Raw 0, Smackdown 1; the 2v2 has two spots on it), and 3
     * of the ladder match's 6 sides (Raw 0–2, Smackdown 3–5).
     *
     * @return list<int>
     */
    public static function seriesSides(int $position, Brand $brand): array
    {
        $raw = $brand === Brand::Raw;

        return match (array_search($position, self::WM_SERIES_SLOTS, true)) {
            0 => [$raw ? 0 : 1],
            1 => $raw ? [0, 0] : [1, 1],
            2 => $raw ? [0, 1, 2] : [3, 4, 5],
            default => [],
        };
    }

    /**
     * What the player can set in each series match (house rule, 2026-11-15):
     * their spots (side indexes, in order), who's in them (null while
     * empty), and who could take them. The rival's spots aren't theirs.
     *
     * @return array<int, array{sides: list<int>, current: list<int|null>, eligible: list<int>}>
     */
    public function seriesOptions(Show $show, Brand $brand): array
    {
        if (! $show->isWrestleMania()) {
            return [];
        }

        $options = [];

        foreach ($this->seriesSlots($show) as $slot) {
            $sides = self::seriesSides($slot->position, $brand);

            if ($sides === [] || $slot->matchType === null) {
                continue;
            }

            $current = $this->spotIds($slot, $brand);

            $options[$slot->position] = [
                'sides' => $sides,
                'current' => array_map(fn (int $i): ?int => $current[$i] ?? null, array_keys($sides)),
                // Everyone they could pick, those already in it included.
                'eligible' => array_values(array_unique([...$current, ...$this->seriesPool($show, $slot, $brand, $current)->pluck('id')->map(fn ($id): int => (int) $id)->all()])),
            ];
        }

        return $options;
    }

    /**
     * Set a brand's superstars in one interbrand series match (house rule,
     * 2026-11-15). `$ids` fill its spots in order; the other brand's stay.
     *
     * @param  list<int>  $ids
     */
    public function setSeriesEntrants(Show $show, int $position, Brand $brand, array $ids): void
    {
        $options = $this->seriesOptions($show, $brand)[$position] ?? throw new GameRuleException('That isn’t an interbrand series match you can change.');
        $ids = array_map('intval', $ids);
        $needed = count($options['sides']);

        if (count($ids) !== $needed || count(array_unique($ids)) !== $needed) {
            throw new GameRuleException('Pick '.$needed.' different '.Str::plural('superstar', $needed).' for this match.');
        }

        foreach ($ids as $id) {
            if (! in_array($id, $options['eligible'], true)) {
                $name = SaveWrestler::with('wrestler')->find($id)?->name() ?? 'That superstar';

                throw new GameRuleException("{$name} can’t be in this match: pick your bookable non-champions who aren’t in another series match.");
            }
        }

        $slot = MatchSlot::with(['matchType', 'participants.saveWrestler'])->where('show_id', $show->id)->where('position', $position)->firstOrFail();
        $before = $this->spotIds($slot, $brand);

        MatchParticipant::where('match_slot_id', $slot->id)->whereIn('save_wrestler_id', $before)->delete();
        $this->placeSeries($show, $slot, $options['sides'], $ids, announce: false);

        // Money in the Bank: the qualifier stories follow the field.
        if ($slot->matchType?->isMoneyInTheBank()) {
            foreach (array_diff($before, $ids) as $id) {
                NewsEvent::where('save_id', $show->save_id)->where('type', NewsEventType::MitbQualified)->where('week_number', $show->week_number)
                    ->get()
                    ->filter(fn (NewsEvent $e): bool => in_array($id, (array) ($e->subject_ids['wrestler_ids'] ?? []), true))
                    ->each->delete();
            }

            foreach (array_diff($ids, $before) as $id) {
                $this->announceQualifier($show, $id);
            }
        }
    }

    /**
     * Who a brand has in a series match, by spot.
     *
     * @return list<int>
     */
    private function spotIds(MatchSlot $slot, Brand $brand): array
    {
        $sides = self::seriesSides($slot->position, $brand);

        return array_values(array_map('intval', MatchParticipant::where('match_slot_id', $slot->id)
            ->where('role', ParticipantRole::Competitor)
            ->whereIn('side', array_unique($sides))
            ->orderBy('side')
            ->orderBy('id')
            ->pluck('save_wrestler_id')
            ->all()));
    }

    /**
     * @param  list<int>  $sides
     * @param  list<int>  $ids
     */
    private function placeSeries(Show $show, MatchSlot $slot, array $sides, array $ids, bool $announce = true): void
    {
        foreach ($ids as $i => $id) {
            MatchParticipant::create(['match_slot_id' => $slot->id, 'save_wrestler_id' => $id, 'side' => $sides[$i], 'role' => ParticipantRole::Competitor]);

            if ($announce && $slot->matchType?->isMoneyInTheBank()) {
                $this->announceQualifier($show, $id);
            }
        }
    }

    private function announceQualifier(Show $show, int $id): void
    {
        $this->news->record($show->game, NewsEventType::MitbQualified, NewsTier::Story, NewsBrand::Both, [
            'wrestler' => SaveWrestler::with('wrestler')->find($id)?->name(),
        ], ['wrestler_ids' => [$id]], priority: 10, week: $show->week_number);
    }

    /**
     * The rival's pick: its most valuable superstar in the pool, ties drawn.
     *
     * @param  Collection<int, SaveWrestler>  $pool
     */
    private function bestOf(Show $show, Brand $brand, Collection $pool, Rng $rng): ?SaveWrestler
    {
        if ($pool->isEmpty()) {
            return null;
        }

        $save = $show->game;
        $values = $pool->mapWithKeys(fn (SaveWrestler $w): array => [$w->id => $this->judge->value($save, $brand->rival(), [(int) $w->id])]);
        $best = $values->max();

        return $rng->pick($pool->filter(fn (SaveWrestler $w): bool => $values[$w->id] === $best)->values()->all());
    }

    /**
     * A brand's possible entrants for one series match: bookable, not a
     * champion, not in another series match (or in this one, unless listed
     * in `$keep`), and allowed in the match type.
     *
     * @param  list<int>  $keep  ids already in this match who stay pickable
     * @return Collection<int, SaveWrestler>
     */
    private function seriesPool(Show $show, MatchSlot $slot, Brand $brand, array $keep = []): Collection
    {
        $champions = array_values(array_map('intval', $this->titles->currentReigns($show->game)->flatMap(fn ($r) => $r->holderIds())->all()));
        $taken = MatchParticipant::whereIn('match_slot_id', MatchSlot::where('show_id', $show->id)->whereIn('position', self::WM_SERIES_SLOTS)->pluck('id'))
            ->pluck('save_wrestler_id')
            ->map(fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => in_array($id, $keep, true))
            ->values()
            ->all();

        return $this->randomizer->poolFor($slot->matchType, $this->randomizer->pool($show, $brand, [...$champions, ...$taken]))->values();
    }

    /**
     * Royal Rumble winner vs their brand's world champion, title on the line.
     * If either is seriously injured (or there's no winner or champion), the
     * main event becomes a normal slot the winner's brand books (§23).
     */
    public function setMainEvent(Show $show): void
    {
        $slot = MatchSlot::where('show_id', $show->id)->where('position', self::WM_MAIN_EVENT)->firstOrFail();

        if ($slot->participants()->exists() || $slot->match_type_id !== null) {
            return;
        }

        $winnerId = $show->game->seasons()->where('number', $show->season)->value('royal_rumble_winner_id');
        $winner = $winnerId === null ? null : SaveWrestler::with('wrestler')->whereKey($winnerId)->first();
        $brand = $winner->brand ?? Brand::Raw;
        $championship = Championship::where('brand', $brand)->where('is_world', true)->firstOrFail();
        $reign = $this->titles->currentReign($show->game, $championship);
        $week = $show->week_number;

        $playable = $winner !== null
            && $winner->brand !== null
            && $reign !== null
            && ! in_array($winner->id, $reign->holderIds(), true)
            && ! $this->injuries->isSeriouslyInjured($winner, $week)
            && ! $this->injuries->isSeriouslyInjured(SaveWrestler::findOrFail($reign->holderIds()[0]), $week);

        $slot->update(['owner_brand' => $brand]);

        if (! $playable) {
            $slot->update(['fixed_kind' => null]);

            return;
        }

        $champion = SaveWrestler::findOrFail($reign->holderIds()[0]);
        // House rule (2026-11-15): the brand that has the main event picks its
        // match type. The player starts on a Single; the rival draws one.
        $type = $show->game->isHuman($brand)
            ? $this->type('single', 'Single')
            : $this->rivalMainEventType($show, $brand, [$champion, $winner], $show->game->rng('wm.main-event.type', $week));

        $slot->update([
            'match_type_id' => $type->id,
            'cost' => $type->cost,
            'title_championship_id' => $championship->id,
            'fixed_kind' => FixedSlotKind::WrestlemaniaMainEvent,
        ]);

        MatchParticipant::create(['match_slot_id' => $slot->id, 'save_wrestler_id' => $champion->id, 'side' => 0, 'role' => ParticipantRole::Competitor]);
        MatchParticipant::create(['match_slot_id' => $slot->id, 'save_wrestler_id' => $winner->id, 'side' => 1, 'role' => ParticipantRole::Competitor]);

        $this->news->record($show->game, NewsEventType::PpvMainEventSet, NewsTier::Headline, NewsBrand::Both, [
            'ppv' => 'WrestleMania',
            'wrestler_a' => $reign->holderName(),
            'wrestler_b' => $winner->name(),
        ], ['wrestler_ids' => [$winner->id, ...$reign->holderIds()]], priority: 80, week: $week - 1);
    }

    /**
     * The match types the WrestleMania main event can be (house rule,
     * 2026-11-15): one on one, with the title on the line, and no manager,
     * special referee or Money in the Bank.
     *
     * @return Collection<int, MatchType>
     */
    public function mainEventTypes(Show $show, Brand $brand): Collection
    {
        return new Collection($this->randomizer->eligibleTypes($show, $brand)
            ->filter(fn (MatchType $t): bool => $t->isOneOnOne() && $t->titles_allowed && ! $t->hasManagers() && ! $t->hasSpecialReferee() && ! $t->isMoneyInTheBank())
            ->values()
            ->all());
    }

    /**
     * The player changes the main event's match type (only the brand that
     * has the main event, while the card can be booked).
     */
    public function setMainEventType(Show $show, Brand $brand, int $typeId): void
    {
        $slot = MatchSlot::where('show_id', $show->id)->where('position', self::WM_MAIN_EVENT)->first();

        if (! $show->isWrestleMania() || $slot === null || $slot->fixed_kind !== FixedSlotKind::WrestlemaniaMainEvent || $slot->owner_brand !== $brand) {
            throw new GameRuleException('Only the brand with the WrestleMania main event picks its match type.');
        }

        $type = $this->mainEventTypes($show, $brand)->firstWhere('id', $typeId)
            ?? throw new GameRuleException('The main event has to be a one-on-one match the title can be on the line in.');

        $slot->update(['match_type_id' => $type->id, 'cost' => $type->cost]);
    }

    /**
     * The rival's main event: a random type that leaves both of them at
     * `gm.card.randomize_max_fatigue` or less, so neither can get hurt; a
     * Single when nothing else fits.
     *
     * @param  list<SaveWrestler>  $competitors
     */
    private function rivalMainEventType(Show $show, Brand $brand, array $competitors, Rng $rng): MatchType
    {
        $max = (int) config('gm.card.randomize_max_fatigue');
        $tired = max(0, ...array_map(fn (SaveWrestler $w): int => (int) $w->fatigue, $competitors));
        $safe = $this->mainEventTypes($show, $brand)->filter(fn (MatchType $t): bool => $tired + $t->fatigueCost() <= $max)->values();

        return $safe->isEmpty() ? $this->type('single', 'Single') : $rng->pick($safe->all());
    }

    /**
     * Series entrants who can no longer compete (left the brand, became a
     * champion, seriously injured, sitting out) lose their spot before the
     * show: the rival picks again, and the player's empty spots are filled
     * at random if they weren't picked (Simulate to here, tests).
     */
    public function refreshSeries(Show $show, Rng $rng): void
    {
        $champions = $this->titles->currentReigns($show->game)->flatMap(fn ($r) => $r->holderIds())->all();

        foreach (MatchSlot::with('participants.saveWrestler')->where('show_id', $show->id)->whereIn('position', self::WM_SERIES_SLOTS)->get() as $slot) {
            foreach ($slot->participants as $p) {
                $gone = $p->saveWrestler->brand === null
                    || in_array($p->save_wrestler_id, $champions, true)
                    || $this->injuries->isSeriouslyInjured($p->saveWrestler, $show->week_number)
                    || $p->saveWrestler->isSittingOut($show->week_number)
                    || ! in_array($p->side, self::seriesSides($slot->position, $p->saveWrestler->brand), true);

                if ($gone) {
                    // A brand's spots go together, so its side is picked again whole.
                    $brand = in_array($p->side, self::seriesSides($slot->position, Brand::Raw), true) ? Brand::Raw : Brand::Smackdown;
                    MatchParticipant::where('match_slot_id', $slot->id)->whereIn('side', array_unique(self::seriesSides($slot->position, $brand)))->delete();
                }
            }
        }

        $this->drawSeries($show, $rng);
        $this->fillSeries($show, $show->game->player_brand, $rng);
    }

    /**
     * @return Collection<int, MatchSlot>
     */
    public function seriesSlots(Show $show): Collection
    {
        return MatchSlot::with(['participants.saveWrestler', 'result', 'matchType'])
            ->where('show_id', $show->id)
            ->whereIn('position', self::WM_SERIES_SLOTS)
            ->orderBy('position')
            ->get();
    }

    /**
     * The shared SummerSlam card's 5 slots, split by who drew more fans
     * this season (`SummerSlamMainEvent`).
     */
    private function setUpSummerSlam(Show $show): void
    {
        $headliner = $this->summerSlam->headliner($show->game, $show->season, $show->week_number);
        $other = $headliner === Brand::Raw ? Brand::Smackdown : Brand::Raw;

        foreach (range(1, self::SHARED_PPV_MATCHES) as $position) {
            MatchSlot::firstOrCreate(['show_id' => $show->id, 'position' => $position], [
                'owner_brand' => in_array($position, self::SUMMERSLAM_HEADLINER_SLOTS, true) ? $headliner : $other,
            ]);
        }
    }

    /**
     * House rule (2026-11-15): the brand whose superstar won the Royal Rumble
     * (it has the main event) books Matches 1 and 3, the other brand
     * Matches 2 and 4. Raw leads when there's no Rumble winner.
     */
    public function wrestleManiaLead(Show $show): Brand
    {
        $winnerId = $show->game->seasons()->where('number', $show->season)->value('royal_rumble_winner_id');

        $winner = $winnerId === null ? null : SaveWrestler::whereKey($winnerId)->first();

        return $winner->brand ?? Brand::Raw;
    }

    private function setUpWrestleMania(Show $show): void
    {
        $lead = $this->wrestleManiaLead($show);

        foreach (range(1, self::WM_MATCHES) as $position) {
            $owner = match (true) {
                in_array($position, self::WM_LEAD_SLOTS, true) => $lead,
                in_array($position, self::WM_OTHER_SLOTS, true) => $lead->rival(),
                default => null,
            };

            $series = in_array($position, self::WM_SERIES_SLOTS, true);

            MatchSlot::firstOrCreate(['show_id' => $show->id, 'position' => $position], [
                'owner_brand' => $owner,
                'fixed_kind' => $series ? FixedSlotKind::InterbrandSeries : ($position === self::WM_MAIN_EVENT ? FixedSlotKind::WrestlemaniaMainEvent : null),
                'match_type_id' => match (array_search($position, self::WM_SERIES_SLOTS, true)) {
                    0 => $this->type('single', 'Single')->id,
                    1 => $this->type('tag_team', 'Normal Tag')->id,
                    2 => $this->type('ladder', MatchType::MITB_VARIANT)->id,
                    default => null,
                },
                'cost' => 0,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function captains(MatchSlot $slot): array
    {
        return array_values(collect($slot->sides())->map(fn (array $ids): string => $slot->participants->firstWhere('save_wrestler_id', $ids[0])?->saveWrestler->wrestler->name ?? '?')->all());
    }

    private function type(string $family, string $variant): MatchType
    {
        return MatchType::where('family', $family)->where('variant', $variant)->firstOrFail();
    }
}
