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
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\MatchParticipant;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Card\CardRandomizer;
use App\Services\Injuries\InjuryStatus;
use App\Services\News\NewsRecorder;
use App\Services\Titles\TitleService;
use App\Support\Rng\Rng;
use Illuminate\Database\Eloquent\Collection;

/**
 * Fixed PPV cards (§10, §13, §23): the 30-man Royal Rumble, the Survivor
 * Series 3 vs 3 main event, and the 10-match interbrand WrestleMania card.
 */
class PpvCardService
{
    public const WM_RAW_SLOTS = [1, 2, 3];

    public const WM_SMACKDOWN_SLOTS = [4, 5, 6];

    public const WM_SERIES_SLOTS = [7, 8, 9];

    public const WM_MAIN_EVENT = 10;

    public function __construct(
        private CardRandomizer $randomizer,
        private TitleService $titles,
        private InjuryStatus $injuries,
        private NewsRecorder $news,
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

        if ($show->isInterbrandMainEvent()) {
            $kind = $show->ppvKey() === PpvKey::RoyalRumble ? FixedSlotKind::RoyalRumble : FixedSlotKind::SurvivorSeriesMainEvent;
            $type = $kind === FixedSlotKind::RoyalRumble ? $this->type('royal_rumble', '30-Man') : $this->type('survivor_series_elimination', 'Traditional 3 vs 3');

            MatchSlot::firstOrCreate(['show_id' => $show->id, 'position' => 1], [
                'match_type_id' => $type->id,
                'fixed_kind' => $kind,
                'cost' => 0,
            ]);
        }
    }

    /**
     * Royal Rumble entrants (15 per brand) or a Survivor Series team (3).
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
                throw new GameRuleException('Entrants must be bookable wrestlers on your roster.');
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
            throw new GameRuleException("Not enough bookable wrestlers (need {$needed}, have {$pool->count()}).");
        }

        $this->setEntrants($show, $brand, array_map('intval', $rng->sample($pool->pluck('id')->all(), $needed)));
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

        return MatchSlot::with('participants')->where('show_id', $show->id)->orderBy('position')->firstOrFail();
    }

    /**
     * Draw the three interbrand series matches at random from each brand's
     * bookable non-champions (§23, to confirm): 1v1, 2v2, then Money in the Bank.
     */
    public function drawSeries(Show $show, Rng $rng): void
    {
        $champions = array_values(array_map('intval', $this->titles->currentReigns($show->game)->flatMap(fn ($r) => $r->holderIds())->all()));
        $taken = [];
        $plans = [
            7 => [$this->type('single', 'No Manager'), [1, 1]],
            8 => [$this->type('tag_team', 'Normal Tag'), [2, 2]],
            9 => [$this->type('ladder', MatchType::MITB_VARIANT), [3, 3]],
        ];

        foreach ($plans as $position => [$type, [$perRaw, $perSmackdown]]) {
            $slot = MatchSlot::where('show_id', $show->id)->where('position', $position)->firstOrFail();

            if ($slot->participants()->exists()) {
                $taken = [...$taken, ...$slot->participants()->pluck('save_wrestler_id')->map(fn ($id): int => (int) $id)->all()];

                continue;
            }

            $pick = function (Brand $brand, int $count) use ($show, $champions, &$taken, $type, $rng): array {
                $pool = $this->randomizer->pool($show, $brand, array_values([...$champions, ...$taken]));
                $pool = $this->randomizer->poolFor($type, $pool);

                if ($pool->count() < $count) {
                    return [];
                }

                $ids = array_map('intval', $rng->sample($pool->pluck('id')->all(), $count));
                $taken = [...$taken, ...$ids];

                return $ids;
            };

            $raw = $pick(Brand::Raw, $perRaw);
            $smackdown = $pick(Brand::Smackdown, $perSmackdown);

            if ($raw === [] || $smackdown === []) {
                continue;
            }

            // The MITB ladder match is six individuals; the others are two teams.
            $sides = $type->isMoneyInTheBank()
                ? array_map(fn (int $id): array => [$id], [...$raw, ...$smackdown])
                : [$raw, $smackdown];

            foreach ($sides as $side => $ids) {
                foreach ($ids as $id) {
                    MatchParticipant::create(['match_slot_id' => $slot->id, 'save_wrestler_id' => $id, 'side' => $side, 'role' => ParticipantRole::Competitor]);
                }

                if ($type->isMoneyInTheBank()) {
                    $this->news->record($show->game, NewsEventType::MitbQualified, NewsTier::Story, NewsBrand::Both, [
                        'wrestler' => SaveWrestler::with('wrestler')->find($ids[0])?->name(),
                    ], ['wrestler_ids' => $ids], priority: 10, week: $show->week_number);
                }
            }
        }
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

        $slot->update([
            'match_type_id' => $this->type('single', 'No Manager')->id,
            'title_championship_id' => $championship->id,
            'fixed_kind' => FixedSlotKind::WrestlemaniaMainEvent,
        ]);

        MatchParticipant::create(['match_slot_id' => $slot->id, 'save_wrestler_id' => $reign->holderIds()[0], 'side' => 0, 'role' => ParticipantRole::Competitor]);
        MatchParticipant::create(['match_slot_id' => $slot->id, 'save_wrestler_id' => $winner->id, 'side' => 1, 'role' => ParticipantRole::Competitor]);

        $this->news->record($show->game, NewsEventType::PpvMainEventSet, NewsTier::Headline, NewsBrand::Both, [
            'ppv' => 'WrestleMania',
            'wrestler_a' => $reign->holderName(),
            'wrestler_b' => $winner->name(),
        ], ['wrestler_ids' => [$winner->id, ...$reign->holderIds()]], priority: 80, week: $week - 1);
    }

    /**
     * Series participants who can no longer compete are redrawn before the show.
     */
    public function refreshSeries(Show $show, Rng $rng): void
    {
        $champions = $this->titles->currentReigns($show->game)->flatMap(fn ($r) => $r->holderIds())->all();

        foreach (MatchSlot::with('participants.saveWrestler')->where('show_id', $show->id)->whereIn('position', self::WM_SERIES_SLOTS)->get() as $slot) {
            $invalid = $slot->participants->contains(fn (MatchParticipant $p): bool => $p->saveWrestler->brand === null
                || in_array($p->save_wrestler_id, $champions, true)
                || $this->injuries->isSeriouslyInjured($p->saveWrestler, $show->week_number)
                || $p->saveWrestler->isSittingOut($show->week_number));

            if ($invalid) {
                $slot->participants()->delete();
            }
        }

        $this->drawSeries($show, $rng);
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

    private function setUpWrestleMania(Show $show): void
    {
        foreach (range(1, 10) as $position) {
            $owner = match (true) {
                in_array($position, self::WM_RAW_SLOTS, true) => Brand::Raw,
                in_array($position, self::WM_SMACKDOWN_SLOTS, true) => Brand::Smackdown,
                default => null,
            };

            $series = in_array($position, self::WM_SERIES_SLOTS, true);

            MatchSlot::firstOrCreate(['show_id' => $show->id, 'position' => $position], [
                'owner_brand' => $owner,
                'fixed_kind' => $series ? FixedSlotKind::InterbrandSeries : ($position === self::WM_MAIN_EVENT ? FixedSlotKind::WrestlemaniaMainEvent : null),
                'match_type_id' => match ($position) {
                    7 => $this->type('single', 'No Manager')->id,
                    8 => $this->type('tag_team', 'Normal Tag')->id,
                    9 => $this->type('ladder', MatchType::MITB_VARIANT)->id,
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
