<?php

namespace App\Services\Matches;

use App\Enums\LedgerCategory;
use App\Enums\MatchFamily;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\ShowStatus;
use App\Models\MatchResult;
use App\Models\MatchSlot;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Fatigue\FatigueZones;
use App\Services\Finance\LedgerService;
use App\Services\Injuries\InjuryService;
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
    ) {}

    public function air(Show $show, WeekContext $context): void
    {
        if ($show->status === ShowStatus::Aired) {
            return;
        }

        $rng = $context->rng("show.{$show->streamKey()}");
        $slots = MatchSlot::with(['matchType.ppvExclusive', 'participants.saveWrestler.wrestler.specialties', 'show'])
            ->where('show_id', $show->id)
            ->orderBy('position')
            ->get();

        // Promos air before the match they're placed in front of (§5), so
        // their fatigue counts going into that match.
        $promoRng = $context->rng("promos.{$show->streamKey()}");
        $mainEvent = $slots->filter(fn (MatchSlot $s): bool => $s->isBooked())->last();

        foreach ($slots as $slot) {
            $this->promos->resolveBefore($show, $slot->position, $promoRng);

            if (! $slot->isBooked()) {
                continue;
            }

            $this->airMatch($slot, $show, $context, $rng, $slot->is($mainEvent));
        }

        $this->promos->resolve($show, $promoRng);
        $this->promoStories->write($show, $context->rng("promo-stories.{$show->streamKey()}"));

        $show->update(['status' => ShowStatus::Aired]);
    }

    private function airMatch(MatchSlot $slot, Show $show, WeekContext $context, Rng $rng, bool $mainEvent): void
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

        $resolved = $this->matches->resolve($slot, $rng, $this->rivalries->active($context->save));

        $result = MatchResult::create([
            'match_slot_id' => $slot->id,
            'winner_side' => $resolved->winnerSide,
            'method' => $resolved->method,
            'finish_event' => $resolved->finishEvent,
            'interferer_id' => $resolved->interfererId,
            'summary' => $resolved->summary,
            'rng_seed_step' => $rng->steps(),
        ]);

        foreach ($slot->participants as $p) {
            $context->data['used'][$p->save_wrestler_id] = true;

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

        if ($type->sides !== [] && max($type->sides) === 1) {
            foreach ($slot->sides()[$resolved->loserSide] ?? [] as $loserId) {
                $this->rivalries->recordMeeting($context->save, $slot, $resolved->winners[0], $loserId, $week, $show->isPpv());
            }
        }

        if ($resolved->interfererId !== null) {
            $interferer = SaveWrestler::with('wrestler')->find($resolved->interfererId);
            $this->news->record($context->save, NewsEventType::InterferenceFinish, NewsTier::Story, $show->brand ?? NewsBrand::Both, [
                'interferer' => $interferer?->name(),
                'loser' => $this->namesOf($resolved->losers, $wrestlers),
                'winner' => $this->namesOf($resolved->winners, $wrestlers),
            ], ['wrestler_ids' => [...$resolved->winners, ...$resolved->losers]], priority: 30, week: $week, dedupeKey: "slot:{$slot->id}");
        }

        $this->announceResult($slot, $show, $resolved, $wrestlers, $week, $mainEvent);

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
     * @param  Collection<int, SaveWrestler>  $wrestlers
     */
    private function announceResult(MatchSlot $slot, Show $show, ResolvedMatch $resolved, Collection $wrestlers, int $week, bool $mainEvent): void
    {
        $this->news->record(
            $show->game,
            NewsEventType::MatchResult,
            NewsTier::Story,
            $slot->owner_brand ?? $show->brand ?? NewsBrand::Both,
            [
                'winner' => $this->namesOf($resolved->winners, $wrestlers),
                'defeats' => count($resolved->winners) > 1 ? 'defeat' : 'defeats',
                'loser' => $this->namesOf($resolved->losers, $wrestlers),
                'by' => $resolved->isDecisive() ? '' : ' by '.mb_strtolower($resolved->method->label()),
                'match' => $slot->matchType?->name,
                'show' => $show->name,
                'brand' => $show->brand?->label(),
            ],
            ['wrestler_ids' => [...$resolved->winners, ...$resolved->losers]],
            priority: (int) config('gm.news.match_result_priority.'.($mainEvent ? 'main_event' : 'other')),
            week: $week,
            dedupeKey: "slot:{$slot->id}",
        );
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
                'winner' => $this->namesOf($resolved->winners, $all),
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
     * @param  list<int>  $ids
     * @param  Collection<int, SaveWrestler>  $all
     */
    private function namesOf(array $ids, Collection $all): string
    {
        return implode(' & ', array_map(fn (int $id): string => $all->get($id)?->name() ?? '?', $ids));
    }
}
