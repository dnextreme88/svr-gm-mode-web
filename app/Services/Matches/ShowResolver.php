<?php

namespace App\Services\Matches;

use App\Enums\FixedSlotKind;
use App\Enums\InjurySeverity;
use App\Enums\LedgerCategory;
use App\Enums\MatchFamily;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\ParticipantRole;
use App\Enums\RosterType;
use App\Enums\ShowStatus;
use App\Models\GauntletBout;
use App\Models\Injury;
use App\Models\MatchResult;
use App\Models\MatchSlot;
use App\Models\NewsEvent;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Attendance\AttendanceService;
use App\Services\Factions\FactionNamer;
use App\Services\Fatigue\FatigueZones;
use App\Services\Finance\LedgerService;
use App\Services\Injuries\InjuryService;
use App\Services\Injuries\InjuryStatus;
use App\Services\News\NewsRecorder;
use App\Services\Promos\PromoService;
use App\Services\Promos\PromoStoryWriter;
use App\Services\Rivalries\RivalryService;
use App\Services\Week\WeekContext;
use App\Support\Rng\Rng;
use Illuminate\Database\Eloquent\Collection;

/**
 * Airs a card: resolves each match in order, then applies records, fatigue,
 * injuries, rivalries and costs (§4, §6, §20).
 */
class ShowResolver
{
    public function __construct(
        private MatchResolver $matches,
        private InjuryService $injuries,
        private RivalryService $rivalries,
        private LedgerService $ledger,
        private NewsRecorder $news,
        private MatchOutcomeHandler $outcomes,
        private PromoService $promos,
        private PromoStoryWriter $promoStories,
        private InjuryStatus $injuryStatus,
        private FactionNamer $factions,
        private AttendanceService $attendance,
    ) {}

    public function air(Show $show, WeekContext $context): void
    {
        if ($show->status === ShowStatus::Aired) {
            return;
        }

        while ($this->airNext($show, $context) !== null) {
            // One match at a time, in card order.
        }

        $this->close($show, $context);
    }

    /**
     * The next booked match that hasn't aired, in card order.
     */
    public function nextMatch(Show $show): ?MatchSlot
    {
        return $this->slots($show)->first(fn (MatchSlot $s): bool => $s->isBooked() && $s->result === null);
    }

    /**
     * Airs the next match: first the promos placed in front of it (§5), so
     * their fatigue counts going into it, then the match itself. With a
     * booked side, that side wins (Book to Win); a Gauntlet takes a booked
     * side per bout instead. Returns null when every match has aired.
     *
     * @param  list<int|null>  $bookedBouts
     */
    public function airNext(Show $show, WeekContext $context, ?int $bookedSide = null, array $bookedBouts = []): ?MatchSlot
    {
        $slots = $this->slots($show);
        $next = $slots->first(fn (MatchSlot $s): bool => $s->isBooked() && $s->result === null);

        if ($next === null) {
            return null;
        }

        $promoRng = $context->stream("promos.{$show->streamKey()}");

        foreach ($slots as $slot) {
            if ($slot->position > $next->position) {
                break;
            }

            $this->promos->resolveBefore($show, $slot->position, $promoRng);
        }

        // The main event is the card's last booked match, never a Money in
        // the Bank cash-in placed after it (house rule).
        $mainEvent = $slots->filter(fn (MatchSlot $s): bool => $s->isBooked() && $s->fixed_kind !== FixedSlotKind::MitbCashIn)->last();
        $this->airMatch($next, $show, $context, $context->stream("show.{$show->streamKey()}"), $next->is($mainEvent), $bookedSide, $bookedBouts);

        return $next;
    }

    /**
     * Once the last match is in: the promos still to air, the promo stories,
     * and the show is marked aired.
     */
    public function close(Show $show, WeekContext $context): void
    {
        $promoRng = $context->stream("promos.{$show->streamKey()}");

        foreach ($this->slots($show) as $slot) {
            $this->promos->resolveBefore($show, $slot->position, $promoRng);
        }

        $this->promos->resolve($show, $promoRng);
        // Attendance first: an unbooked Interview Space can quote the crowd.
        $this->attendance->tally($show, $context);
        $this->promoStories->write($show, $context->rng("promo-stories.{$show->streamKey()}"));
        $this->confirmInjuredWins($show);

        $show->update(['status' => ShowStatus::Aired]);
    }

    /**
     * @return Collection<int, MatchSlot>
     */
    private function slots(Show $show): Collection
    {
        return MatchSlot::with(['matchType.ppvExclusive', 'participants.saveWrestler.wrestler.specialties', 'show', 'result'])
            ->where('show_id', $show->id)
            ->orderBy('position')
            ->get();
    }

    /**
     * @param  list<int|null>  $bookedBouts
     */
    private function airMatch(MatchSlot $slot, Show $show, WeekContext $context, Rng $rng, bool $mainEvent, ?int $bookedSide, array $bookedBouts = []): void
    {
        $week = $context->week;
        $type = $slot->matchType;

        if ($type === null) {
            return;
        }

        // Fatigue is checked going into the match (§6), so read it fresh: an
        // earlier match on the same card may already have added fatigue.
        $wrestlers = SaveWrestler::with('wrestler')->whereIn('id', $slot->participantIds())->get()->keyBy('id');
        $goingIn = [];

        foreach ($slot->competitors() as $p) {
            $goingIn[$p->save_wrestler_id] = $wrestlers[$p->save_wrestler_id]->fatigue;
        }

        // Who goes in with a minor injury, for the injured-win headline.
        $minorGoingIn = array_keys(array_filter(
            array_intersect_key($this->injuryStatus->activeByWrestler($context->save->id, $week), $goingIn),
            fn (Injury $injury): bool => $injury->severity === InjurySeverity::Minor,
        ));

        $resolved = $this->matches->resolve($slot, $rng, $this->rivalries->active($context->save), $bookedSide, $bookedBouts);

        $result = MatchResult::create([
            'match_slot_id' => $slot->id,
            'winner_side' => $resolved->winnerSide,
            'method' => $resolved->method,
            'finish_event' => $resolved->finishEvent,
            'interferer_id' => $resolved->interfererId,
            // A Gauntlet counts as booked when its deciding bout was.
            'booked' => $resolved->bouts === [] ? $bookedSide !== null : $resolved->bouts[array_key_last($resolved->bouts)]['booked'],
            'summary' => $resolved->summary,
            'rng_seed_step' => $rng->steps(),
        ]);

        foreach ($resolved->bouts as $bout) {
            GauntletBout::create(['match_slot_id' => $slot->id, ...$bout]);
        }

        foreach ($slot->participants as $p) {
            $context->data['used'][$p->save_wrestler_id] = true;

            // House rule (2026-10-30): a manager wins or loses with their
            // side. The record only: no fatigue, and streaks are for those
            // who wrestled.
            if ($p->role === ParticipantRole::Manager) {
                $wrestlers[$p->save_wrestler_id]->increment($p->side === $resolved->winnerSide ? 'wins' : 'losses');

                continue;
            }

            if ($p->role->value !== 'competitor') {
                continue;
            }

            $context->data['competed'][$p->save_wrestler_id] = true;
            $won = $p->side === $resolved->winnerSide;
            $p->update(['result' => $won ? 'win' : 'loss', 'fatigue_before' => $goingIn[$p->save_wrestler_id]]);
            $this->applyRecord($wrestlers[$p->save_wrestler_id], $won, $type->fatigueCost(), $slot, $resolved, $wrestlers);
        }

        $buriedAlive = $type->family === MatchFamily::BuriedAlive;

        foreach ($slot->competitors() as $p) {
            // House rule: whoever is buried alive can't be injured by it.
            if ($buriedAlive && in_array($p->save_wrestler_id, $resolved->losers, true)) {
                continue;
            }

            $this->injuries->rollForMatch($wrestlers[$p->save_wrestler_id]->refresh(), $goingIn[$p->save_wrestler_id], $type, $week, $rng, $slot->id);
        }

        if ($buriedAlive) {
            foreach ($resolved->losers as $loserId) {
                $this->bury($wrestlers[$loserId]->refresh(), $week);
            }
        }

        $this->outcomes->apply($slot, $result, $resolved, $context);

        if ($resolved->bouts !== []) {
            // A Gauntlet: each bout is its own meeting.
            $sides = $slot->sides();

            foreach ($resolved->bouts as $bout) {
                $loser = $bout['winner_side'] === $bout['side_a'] ? $bout['side_b'] : $bout['side_a'];
                $this->rivalries->recordMeeting($context->save, $slot, $sides[$bout['winner_side']][0], $sides[$loser][0], $week, $show->isPpv());
            }
        } elseif ($type->sides !== [] && max($type->sides) === 1) {
            foreach ($slot->sides()[$resolved->loserSide] ?? [] as $loserId) {
                $this->rivalries->recordMeeting($context->save, $slot, $resolved->winners[0], $loserId, $week, $show->isPpv());
            }
        }

        if ($resolved->interfererId !== null) {
            $interferer = SaveWrestler::with('wrestler')->find($resolved->interfererId);
            $this->news->record($context->save, NewsEventType::InterferenceFinish, NewsTier::Story, $show->brand ?? NewsBrand::Both, [
                'interferer' => $interferer?->name(),
                'loser' => $this->namesOf($slot, $resolved->losers),
                'winner' => $this->namesOf($slot, $resolved->winners),
            ], ['wrestler_ids' => [...$resolved->winners, ...$resolved->losers]], priority: 30, week: $week, dedupeKey: "slot:{$slot->id}");
        }

        $playedHurt = array_values(array_filter(
            array_intersect($resolved->winners, $minorGoingIn),
            fn (int $id): bool => ! $this->injuryStatus->isSeriouslyInjured($wrestlers[$id]->refresh(), $week),
        ));

        $this->announceResult($slot, $show, $resolved, $wrestlers, $week, $mainEvent, $playedHurt);

        if ($mainEvent && $show->isPpv() && $slot->fixed_kind !== FixedSlotKind::RoyalRumble) {
            $this->announcePpvMainEvent($slot, $show, $resolved, $result->refresh(), $wrestlers, $week);
        }

        if ($show->isInterbrand() && $resolved->isDecisive()) {
            $this->announceFactionInterbrandWin($slot, $show, $resolved, $week);
        }

        $brand = $slot->owner_brand ?? $show->brand;

        if ($slot->cost > 0 && $brand !== null) {
            $this->ledger->post($context->save, $brand, LedgerCategory::MatchCost, -$slot->cost, "{$type->name} ({$show->name})", $week, $slot);
        }
    }

    /**
     * Every match makes the news as a `match_result` story; the main event
     * (the last booked match) ranks higher. It gives way to bigger news from
     * the same match (title change, streak…).
     *
     * A decisive win by someone who went in with a minor injury gets the
     * injured-win headline (house rule).
     *
     * @param  Collection<int, SaveWrestler>  $wrestlers
     * @param  list<int>  $playedHurt  winners who went in with a minor injury that didn't turn serious
     */
    private function announceResult(MatchSlot $slot, Show $show, ResolvedMatch $resolved, Collection $wrestlers, int $week, bool $mainEvent, array $playedHurt = []): void
    {
        $this->news->record(
            $show->game,
            NewsEventType::MatchResult,
            NewsTier::Story,
            $slot->owner_brand ?? $show->brand ?? NewsBrand::Both,
            [
                'winner' => $this->namesOf($slot, $resolved->winners),
                'defeats' => count($resolved->winners) > 1 ? 'defeat' : 'defeats',
                'loser' => $this->namesOf($slot, $resolved->losers),
                'by' => $resolved->isDecisive() ? '' : ' by '.mb_strtolower($resolved->method->label()),
                'via' => $resolved->via($slot->matchType),
                'how' => $resolved->how($slot->matchType),
                'outcome' => match (true) {
                    ! $resolved->isDecisive() => 'not_decisive',
                    $playedHurt !== [] => 'injured',
                    default => 'decisive',
                },
                'injured_winners' => $playedHurt,
                'pronoun' => $this->pronoun($resolved->winners, $wrestlers),
                'match_kind' => $this->matchKind($slot),
                'match' => $slot->matchType?->name,
                'show' => $show->name,
                'brand' => $show->brand?->label(),
                // A quiet brand's headline defaults to its main event, a PPV's
                // first (NewsComposer).
                'main_event' => $mainEvent,
                'ppv' => $show->isPpv(),
            ],
            ['wrestler_ids' => [...$resolved->winners, ...$resolved->losers]],
            priority: $this->resultPriority($slot, $show, $resolved, $mainEvent),
            week: $week,
            dedupeKey: "slot:{$slot->id}",
        );
    }

    /**
     * House rule: the injured-win headline holds only if the minor injury is
     * still minor once the show is over. A double-booked winner can see it
     * turn serious in a later match, and then it's a plain decisive win.
     */
    private function confirmInjuredWins(Show $show): void
    {
        $slotKeys = MatchSlot::where('show_id', $show->id)->pluck('id')->map(fn (int $id): string => "slot:{$id}")->all();
        $events = NewsEvent::where('save_id', $show->save_id)
            ->where('type', NewsEventType::MatchResult)
            ->whereIn('dedupe_key', $slotKeys)
            ->get()
            ->filter(fn (NewsEvent $e): bool => ($e->payload['outcome'] ?? null) === 'injured');

        foreach ($events as $event) {
            $still = array_values(array_filter(
                (array) ($event->payload['injured_winners'] ?? []),
                fn (int $id): bool => ($w = SaveWrestler::find($id)) !== null && ! $this->injuryStatus->isSeriouslyInjured($w, $show->week_number),
            ));

            if ($still === []) {
                $this->news->rewrite($event, [...$event->payload, 'outcome' => 'decisive', 'injured_winners' => []]);
            }
        }
    }

    /**
     * A PPV's main event is the WWE card's headline that week (house rule):
     * who won, and whether a title or a gimmick match was involved. The
     * interbrand card's main event (Survivor Series, WrestleMania) outranks a
     * brand PPV's; the Royal Rumble has its own result.
     *
     * @param  Collection<int, SaveWrestler>  $wrestlers
     */
    private function announcePpvMainEvent(MatchSlot $slot, Show $show, ResolvedMatch $resolved, MatchResult $result, Collection $wrestlers, int $week): void
    {
        $title = $slot->titleChampionship?->name;
        $champions = $result->championReign?->holderIds() ?? [];

        $outcome = match (true) {
            $title === null => 'main_event',
            $result->title_changed => 'title_change',
            $champions !== [] && array_diff($champions, $resolved->winners) === [] => 'title_retained',
            $champions !== [] => 'title_kept',
            default => 'main_event',
        };

        $this->news->record($show->game, NewsEventType::PpvResults, NewsTier::Headline, NewsBrand::Both, [
            'ppv' => $show->name,
            'outcome' => $outcome,
            'winner' => $this->namesOf($slot, $resolved->winners),
            'defeats' => count($resolved->winners) > 1 ? 'defeat' : 'defeats',
            'loser' => $this->namesOf($slot, $resolved->losers),
            'via' => $resolved->via($slot->matchType),
            // The gimmick already names the match, so a win by its own
            // condition adds nothing after it.
            'ppv_how' => $this->isGimmick($slot) && $slot->matchType !== null && $resolved->wonByOwnCondition($slot->matchType)
                ? ''
                : ' '.$resolved->how($slot->matchType),
            'title' => $title,
            'gimmick' => $this->isGimmick($slot) ? " in a {$this->matchKind($slot)} match" : '',
        ], ['wrestler_ids' => [...$resolved->winners, ...$resolved->losers]], priority: (int) config($show->isInterbrand() ? 'gm.news.ppv_main_event_priority.interbrand' : 'gm.news.ppv_main_event_priority.brand'), week: $week, dedupeKey: "ppv-main:{$slot->id}");
    }

    /**
     * A faction that wins on an interbrand card (Royal Rumble, Survivor
     * Series, WrestleMania) makes the WWE card (house rule).
     */
    private function announceFactionInterbrandWin(MatchSlot $slot, Show $show, ResolvedMatch $resolved, int $week): void
    {
        $faction = $this->factions->factionsOn($show->save_id, $resolved->winners, $week)[0] ?? null;

        if ($faction === null) {
            return;
        }

        $brand = SaveWrestler::find($faction->memberIds()[0] ?? 0)->brand ?? $slot->owner_brand;

        $this->news->record($show->game, NewsEventType::FactionInterbrandWin, NewsTier::Headline, NewsBrand::Both, [
            'faction' => $faction->faction_name,
            'brand' => $brand?->label() ?? 'their brand',
            'ppv' => $show->name,
            'loser' => $this->namesOf($slot, $resolved->losers),
            'via' => $resolved->via($slot->matchType),
            'how' => $resolved->how($slot->matchType),
        ], ['wrestler_ids' => [...$resolved->winners, ...$resolved->losers], 'faction_id' => $faction->id], priority: (int) config('gm.news.faction_priority.interbrand_win'), week: $week, dedupeKey: "faction-interbrand:{$slot->id}");
    }

    /**
     * "He", "She" (a Diva) or "They" (a team) for the winning side.
     *
     * @param  list<int>  $winners
     * @param  Collection<int, SaveWrestler>  $wrestlers
     */
    private function pronoun(array $winners, Collection $wrestlers): string
    {
        if (count($winners) > 1) {
            return 'They';
        }

        return $wrestlers->get($winners[0])?->wrestler->roster_type === RosterType::Diva ? 'She' : 'He';
    }

    /**
     * The match in a word or two for the news: the family ("Ladder",
     * "Hardcore", "TLC"), with Normal Matches told apart by how many sides
     * there are (singles, Triple Threat, Fatal 4-Way).
     */
    private function matchKind(MatchSlot $slot): string
    {
        $family = $slot->matchType?->family;

        if ($family === MatchFamily::Single) {
            return match (count($slot->sides())) {
                3 => 'Triple Threat',
                4 => 'Fatal 4-Way',
                default => 'singles',
            };
        }

        return $family?->label() ?? 'match';
    }

    /**
     * How big a match result is: a title match above any non-title match, a
     * PPV match above a weekly show's, the main event above the undercard, a
     * gimmick match above a plain one, and a DQ or count-out win below a
     * decisive one.
     */
    private function resultPriority(MatchSlot $slot, Show $show, ResolvedMatch $resolved, bool $mainEvent): int
    {
        $cfg = (array) config('gm.news.match_result_priority');
        $priority = (int) $cfg[$mainEvent ? 'main_event' : 'other'];

        if ($slot->title_championship_id !== null) {
            $priority += (int) $cfg['title_bonus'];
        }

        if ($show->isPpv()) {
            $priority += (int) $cfg['ppv_bonus'];
        }

        if ($this->isGimmick($slot)) {
            $priority += (int) $cfg['gimmick_bonus'];
        }

        if (! $resolved->isDecisive()) {
            $priority -= (int) $cfg['not_decisive_penalty'];
        }

        return max(1, $priority);
    }

    private function isGimmick(MatchSlot $slot): bool
    {
        return $slot->matchType?->isGimmick() ?? false;
    }

    /**
     * House rule: the Buried Alive loser sits out for a month. The contract
     * pauses for those weeks (see `SaveWrestler::isBuried()`).
     */
    private function bury(SaveWrestler $wrestler, int $week): void
    {
        $wrestler->update([
            'buried_week' => $week,
            'unbookable_until_week' => max($wrestler->unbookable_until_week ?? 0, $week + 1 + (int) config('gm.buried_alive.sit_out_weeks')),
        ]);
    }

    /**
     * @param  Collection<int, SaveWrestler>  $all
     */
    private function applyRecord(SaveWrestler $wrestler, bool $won, int $fatigueCost, MatchSlot $slot, ResolvedMatch $resolved, Collection $all): void
    {
        $week = $slot->show->week_number;
        $save = $slot->show->game;
        $previousWinStreak = $wrestler->win_streak;

        $wrestler->fatigue = FatigueZones::cap($wrestler->fatigue + $fatigueCost);

        if ($won) {
            $wrestler->wins++;
            $wrestler->win_streak++;
            $wrestler->loss_streak = 0;
        } else {
            $wrestler->losses++;
            $wrestler->loss_streak++;
            $wrestler->win_streak = 0;
        }

        $wrestler->save();

        $brand = $wrestler->brand ?? NewsBrand::Both;

        if ($won && in_array($wrestler->win_streak, (array) config('gm.news.win_streaks'), true)) {
            $headline = $wrestler->win_streak >= (int) config('gm.news.win_streak_headline_at');
            $this->news->record($save, NewsEventType::WinStreak, $headline ? NewsTier::Headline : NewsTier::Story, $brand, [
                'wrestler' => $wrestler->name(),
                'n' => $wrestler->win_streak,
            ], ['wrestler_ids' => [$wrestler->id]], priority: 10 + $wrestler->win_streak * 3, week: $week);
        }

        if (! $won && $previousWinStreak >= (int) config('gm.news.streak_snapped_min')) {
            $this->news->record($save, NewsEventType::StreakSnapped, NewsTier::Headline, $brand, [
                'loser' => $wrestler->name(),
                'n' => $previousWinStreak,
                'winner' => $this->namesOf($slot, $resolved->winners),
                'show' => $slot->show->name,
            ], ['wrestler_ids' => [$wrestler->id, ...$resolved->winners]], priority: 60, week: $week, dedupeKey: "slot:{$slot->id}");
        }

        if (! $won && $wrestler->loss_streak >= (int) config('gm.news.losing_streak_min')) {
            $this->news->record($save, NewsEventType::LosingStreak, NewsTier::Ticker, $brand, [
                'wrestler' => $wrestler->name(),
                'n' => $wrestler->loss_streak,
            ], ['wrestler_ids' => [$wrestler->id]], week: $week);
        }
    }

    /**
     * Names for the news, side by side, with factions named as such (house
     * rule): "Evolution & Kane".
     *
     * @param  list<int>  $ids
     */
    private function namesOf(MatchSlot $slot, array $ids): string
    {
        return $this->factions->name($slot->show->save_id, $ids, $slot->show->week_number, $slot->sides());
    }
}
