<?php

namespace App\Services\Ppv;

use App\Enums\Brand;
use App\Enums\FixedSlotKind;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\ParticipantRole;
use App\Enums\PpvKey;
use App\Enums\ShowType;
use App\Exceptions\GameRuleException;
use App\Models\MatchParticipant;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Models\PpvEvent;
use App\Models\PpvQualifier;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Calendar\CalendarService;
use App\Services\Card\CardRandomizer;
use App\Services\Card\PlannedFatigue;
use App\Services\Injuries\InjuryStatus;
use App\Services\Matches\ResolvedMatch;
use App\Services\News\NewsRecorder;
use App\Services\Titles\TitleService;
use App\Support\Rng\Rng;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Qualifying Matches (house rule, 2026-11-17). On both brands' weekly shows
 * in the weeks before Survivor Series (5) and WrestleMania (3), Match 5 is a
 * fixed Qualifying Match: a Ladder Triple Threat for the Survivor Series
 * 5 vs 5 team, a Normal Triple Threat for the Money in the Bank ladder. Its
 * winner (any win, DQ and count-out included) takes one of the brand's
 * spots and can't be removed from the PPV card; only a serious injury or
 * leaving the brand by the PPV reopens the spot, which is then picked by
 * hand (the rival picks its own). A loser can enter a later qualifier.
 */
class QualifierService
{
    /** Sides in a qualifier: a Triple Threat. */
    private const SIDES = [0, 1, 2];

    public function __construct(
        private CalendarService $calendar,
        private CardRandomizer $randomizer,
        private PlannedFatigue $planned,
        private TitleService $titles,
        private InjuryStatus $injuries,
        private NewsRecorder $news,
    ) {}

    /**
     * The PPV a week's weekly shows qualify for, if any: Survivor Series in
     * the 5 weeks before it, WrestleMania in the 3 before it.
     */
    public function window(int $week): ?PpvKey
    {
        $seasonWeek = $this->calendar->seasonWeek($week);

        foreach ((array) config('gm.ppv.qualifiers.windows') as $key => $weeks) {
            $ppvWeek = (int) PpvEvent::where('key', $key)->value('season_week');
            $gap = $ppvWeek - $seasonWeek;

            if ($ppvWeek > 0 && $gap >= 1 && $gap <= (int) $weeks) {
                return PpvKey::from($key);
            }
        }

        return null;
    }

    /**
     * Make a weekly show's Match 5 the fixed Qualifying Match, in a window.
     * Safe to call repeatedly; a Match 5 already booked by hand is left be.
     */
    public function setUp(Show $show): void
    {
        $ppv = $show->show_type === ShowType::Weekly ? $this->window($show->week_number) : null;

        if ($ppv === null) {
            return;
        }

        $type = $this->type($ppv);
        $position = (int) config('gm.ppv.qualifiers.slot');
        $slot = MatchSlot::where('show_id', $show->id)->where('position', $position)->first();

        if ($slot === null) {
            MatchSlot::create([
                'show_id' => $show->id,
                'position' => $position,
                'owner_brand' => $show->brand,
                'match_type_id' => $type->id,
                'fixed_kind' => FixedSlotKind::QualifyingMatch,
                'cost' => $type->cost,
            ]);

            return;
        }

        if ($slot->fixed_kind === null && $slot->match_type_id === null && ! $slot->participants()->exists()) {
            $slot->update(['match_type_id' => $type->id, 'fixed_kind' => FixedSlotKind::QualifyingMatch, 'cost' => $type->cost]);
        }
    }

    /**
     * The show's Qualifying Match, if it has one.
     */
    public function slot(Show $show): ?MatchSlot
    {
        return MatchSlot::with(['matchType', 'participants'])
            ->where('show_id', $show->id)
            ->where('fixed_kind', FixedSlotKind::QualifyingMatch)
            ->first();
    }

    /**
     * Who can be in the show's qualifier: the brand's bookable men who haven't
     * already qualified for this PPV this season (and, for Money in the
     * Bank, aren't champions, like the ladder itself).
     *
     * @param  list<int>  $keep  already in this qualifier, still pickable
     * @return Collection<int, SaveWrestler>
     */
    public function eligible(Show $show, Brand $brand, array $keep = []): Collection
    {
        $ppv = $this->window($show->week_number);

        if ($ppv === null) {
            return collect();
        }

        $season = $this->calendar->seasonOf($show->week_number);
        $exclude = $this->qualifiedIds($show->game, $ppv, $brand, $season);

        if ($ppv === PpvKey::Wrestlemania) {
            array_push($exclude, ...array_map('intval', $this->titles->currentReigns($show->game)->flatMap(fn ($r) => $r->holderIds())->all()));
        }

        $exclude = array_values(array_diff($exclude, $keep));

        return $this->randomizer->pool($show, $brand, $exclude)
            ->reject(fn (SaveWrestler $w): bool => $w->wrestler->isDiva())
            ->values();
    }

    /**
     * The player's pickers for the qualifier, in the shape of the WrestleMania
     * series options (house rule, 2026-11-15), so the card reuses them.
     *
     * @return array<int, array{sides: list<int>, current: list<int|null>, eligible: list<int>, qualifier: string}>
     */
    public function options(Show $show, Brand $brand): array
    {
        $slot = $this->slot($show);
        $ppv = $this->window($show->week_number);

        if ($slot === null || $ppv === null || $show->brand !== $brand) {
            return [];
        }

        $current = $this->entrants($slot);

        return [$slot->position => [
            'sides' => self::SIDES,
            'current' => array_map(fn (int $side): ?int => $current[$side] ?? null, self::SIDES),
            'eligible' => $this->idsOf($this->eligible($show, $brand, array_values($current))),
            'qualifier' => $ppv->value,
        ]];
    }

    /**
     * Set the qualifier's three entrants, one a side.
     *
     * @param  list<int>  $ids
     */
    public function setEntrants(Show $show, Brand $brand, array $ids): void
    {
        $slot = $this->slot($show) ?? throw new GameRuleException('This show has no Qualifying Match.');
        $ids = array_map('intval', $ids);

        if (count($ids) !== count(self::SIDES) || count(array_unique($ids)) !== count(self::SIDES)) {
            throw new GameRuleException('Pick 3 different superstars for the Qualifying Match.');
        }

        $eligible = $this->eligible($show, $brand, array_values($this->entrants($slot)))->pluck('id')->map(fn ($id): int => (int) $id)->all();

        foreach ($ids as $id) {
            if (! in_array($id, $eligible, true)) {
                $name = SaveWrestler::with('wrestler')->find($id)?->name() ?? 'That superstar';

                throw new GameRuleException("{$name} can’t be in this Qualifying Match: pick your bookable superstars who haven’t qualified yet (no Divas).");
            }
        }

        $this->place($slot, $ids);
    }

    /**
     * The rival's entrants: three at random among those the match leaves at
     * its careful fatigue limit or less, so nobody gets hurt (house rule).
     */
    public function aiEntrants(Show $show, Brand $brand, Rng $rng): void
    {
        $slot = $this->slot($show);

        if ($slot === null || $slot->participants->isNotEmpty() || $slot->matchType === null) {
            return;
        }

        $this->place($slot, $this->healthyPicks($show, $brand, $slot->matchType, $rng));
    }

    /**
     * The player's Randomize on the qualifier (house rule, 2026-11-17): three
     * eligible men (never Divas), drawn like the rival's: fresh enough that
     * the match leaves them at 70% or less, else the freshest.
     *
     * @return list<int>
     */
    public function randomPicks(Show $show, Brand $brand, Rng $rng): array
    {
        $slot = $this->slot($show);

        return $slot?->matchType === null ? [] : $this->healthyPicks($show, $brand, $slot->matchType, $rng);
    }

    /**
     * A qualifier still empty when its week airs (Simulate to here, tests):
     * filled the same healthy way.
     */
    public function fillMissing(Show $show, Rng $rng): void
    {
        if ($show->brand !== null) {
            $this->aiEntrants($show, $show->brand, $rng);
        }
    }

    /**
     * Whether the show's qualifier still needs its entrants (the air check).
     */
    public function missing(Show $show): bool
    {
        $slot = $this->slot($show);

        return $slot !== null && $slot->participants->where('role', ParticipantRole::Competitor)->count() < count(self::SIDES);
    }

    /**
     * Record the winner's spot once the qualifier is decided.
     *
     * @return string the outcome line on the results
     */
    public function record(MatchSlot $slot, ResolvedMatch $resolved, int $week): string
    {
        $slot->loadMissing('show');
        $ppv = $this->window($week) ?? PpvKey::SurvivorSeries;
        $winner = SaveWrestler::with('wrestler')->findOrFail($resolved->winners[0]);
        $brand = $slot->owner_brand ?? $slot->show->brand ?? $winner->brand ?? Brand::Raw;

        PpvQualifier::firstOrCreate(['match_slot_id' => $slot->id], [
            'save_id' => $slot->show->save_id,
            'season' => $this->calendar->seasonOf($week),
            'ppv' => $ppv,
            'brand' => $brand,
            'save_wrestler_id' => $winner->id,
            'week_number' => $week,
        ]);

        $count = count($this->qualifiedIds($slot->show->game, $ppv, $brand, $this->calendar->seasonOf($week)));

        if ($ppv === PpvKey::Wrestlemania) {
            $this->news->record($slot->show->game, NewsEventType::MitbQualified, NewsTier::Story, NewsBrand::Both, [
                'wrestler' => $winner->name(),
            ], ['wrestler_ids' => [$winner->id]], priority: 30, week: $week, dedupeKey: "slot:{$slot->id}");

            return "{$winner->name()} qualifies for Money in the Bank ({$count} of ".self::spots($ppv).' for '.$brand->label().')';
        }

        $this->news->record($slot->show->game, NewsEventType::SurvivorSeriesQualified, NewsTier::Story, NewsBrand::from($brand->value), [
            'wrestler' => $winner->name(),
            'brand' => $brand->label(),
            'n' => $count,
            'of' => self::spots($ppv),
        ], ['wrestler_ids' => [$winner->id]], priority: 30, week: $week, dedupeKey: "slot:{$slot->id}");

        return "{$winner->name()} qualifies for Team {$brand->label()} at Survivor Series ({$count} of ".self::spots($ppv).')';
    }

    /**
     * How many spots a PPV's qualifiers fill for each brand.
     */
    public static function spots(PpvKey $ppv): int
    {
        return $ppv === PpvKey::Wrestlemania
            ? count(PpvCardService::seriesSides(PpvCardService::WM_SERIES_SLOTS[2], Brand::Raw))
            : (int) config('gm.ppv.survivor_series_team_size');
    }

    /**
     * A brand's qualifiers for a PPV this season, in the order they won, the
     * vacated ones left out.
     *
     * @return list<int>
     */
    public function qualifiedIds(Save $save, PpvKey $ppv, Brand $brand, int $season): array
    {
        return array_values(array_map('intval', $this->rows($save, $ppv, $brand, $season)->pluck('save_wrestler_id')->all()));
    }

    /**
     * @return Collection<int, PpvQualifier>
     */
    public function rows(Save $save, PpvKey $ppv, Brand $brand, int $season): Collection
    {
        return PpvQualifier::with('saveWrestler.wrestler')
            ->where('save_id', $save->id)
            ->where('season', $season)
            ->where('ppv', $ppv)
            ->where('brand', $brand)
            ->whereNull('vacated_week')
            ->orderBy('week_number')
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    /**
     * The qualifiers who take their spots on the PPV card. One seriously
     * injured or gone from the brand by then loses it (the spot opens).
     *
     * @return list<int>
     */
    public function seatable(Show $ppvShow, PpvKey $ppv, Brand $brand): array
    {
        $week = $ppvShow->week_number;
        $ids = [];

        foreach ($this->rows($ppvShow->game, $ppv, $brand, $this->calendar->seasonOf($week)) as $row) {
            $wrestler = $row->saveWrestler;
            $reason = match (true) {
                $wrestler->brand !== $brand => 'left_brand',
                $this->injuries->isSeriouslyInjured($wrestler, $week) => 'injured',
                default => null,
            };

            if ($reason !== null) {
                $row->update(['vacated_week' => $week, 'vacated_reason' => $reason]);

                continue;
            }

            $ids[] = (int) $wrestler->id;
        }

        return $ids;
    }

    /**
     * Superstars the rival GM keeps healthy (house rule, 2026-11-17): its
     * qualifiers whose PPV hasn't happened yet.
     *
     * @return list<int>
     */
    public function protectedIds(Save $save, Brand $brand, int $week): array
    {
        $season = $this->calendar->seasonOf($week);
        $ids = [];

        foreach (array_keys((array) config('gm.ppv.qualifiers.windows')) as $key) {
            $ppv = PpvKey::from($key);
            $ppvWeek = $this->calendar->absoluteWeek($season, (int) PpvEvent::where('key', $key)->value('season_week'));

            if ($week <= $ppvWeek) {
                array_push($ids, ...$this->qualifiedIds($save, $ppv, $brand, $season));
            }
        }

        return array_values(array_unique($ids));
    }

    public function type(PpvKey $ppv): MatchType
    {
        [$family, $variant] = (array) config("gm.ppv.qualifiers.types.{$ppv->value}");

        return MatchType::where('family', $family)->where('variant', $variant)->firstOrFail();
    }

    /**
     * The entrants by side.
     *
     * @return array<int, int>
     */
    private function entrants(MatchSlot $slot): array
    {
        return MatchParticipant::where('match_slot_id', $slot->id)
            ->where('role', ParticipantRole::Competitor)
            ->orderBy('side')
            ->pluck('save_wrestler_id', 'side')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     */
    private function place(MatchSlot $slot, array $ids): void
    {
        MatchParticipant::where('match_slot_id', $slot->id)->delete();

        foreach ($ids as $side => $id) {
            MatchParticipant::create(['match_slot_id' => $slot->id, 'save_wrestler_id' => $id, 'side' => $side, 'role' => ParticipantRole::Competitor]);
        }

        $slot->unsetRelation('participants');
    }

    /**
     * Three entrants the match leaves at the careful limit or less; short of
     * that, the freshest.
     *
     * @return list<int>
     */
    private function healthyPicks(Show $show, Brand $brand, MatchType $type, Rng $rng): array
    {
        $pool = $this->eligible($show, $brand);

        if ($pool->count() < count(self::SIDES)) {
            return [];
        }

        $fatigue = $this->planned->forWeek($show, $brand);
        $limit = (int) config('gm.ai.booking.careful_max_fatigue');
        $fresh = $pool->filter(fn (SaveWrestler $w): bool => ($fatigue[$w->id] ?? $w->fatigue) + $type->fatigueCost() <= $limit)->values();

        if ($fresh->count() >= count(self::SIDES)) {
            return array_map('intval', $rng->sample($fresh->pluck('id')->all(), count(self::SIDES)));
        }

        return array_values(array_map('intval', $pool->sortBy(fn (SaveWrestler $w): int => $fatigue[$w->id] ?? $w->fatigue)->take(count(self::SIDES))->pluck('id')->all()));
    }

    /**
     * @param  Collection<int, SaveWrestler>  $people
     * @return list<int>
     */
    private function idsOf(Collection $people): array
    {
        $ids = [];

        foreach ($people as $w) {
            $ids[] = (int) $w->id;
        }

        return $ids;
    }

    public static function label(PpvKey $ppv): string
    {
        return $ppv === PpvKey::Wrestlemania ? 'Money in the Bank' : 'Survivor Series';
    }

    /**
     * "2 of 5", for notes.
     */
    public static function progress(int $count, PpvKey $ppv): string
    {
        return $count.' of '.self::spots($ppv).' '.Str::plural('spot', self::spots($ppv));
    }
}
