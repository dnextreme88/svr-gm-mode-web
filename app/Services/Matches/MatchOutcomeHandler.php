<?php

namespace App\Services\Matches;

use App\Enums\Brand;
use App\Enums\FixedSlotKind;
use App\Enums\LedgerCategory;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\ReignEndReason;
use App\Enums\Stipulation;
use App\Enums\WonVia;
use App\Models\Championship;
use App\Models\MatchResult;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Season;
use App\Models\TagTeam;
use App\Models\TitleReign;
use App\Services\Contracts\ContractService;
use App\Services\Finance\LedgerService;
use App\Services\News\NewsRecorder;
use App\Services\Titles\ChampionshipAssignment;
use App\Services\Titles\LastChanceService;
use App\Services\Titles\MitbService;
use App\Services\Titles\TitleRecords;
use App\Services\Titles\TitleService;
use App\Services\Titles\TitleShotService;
use App\Services\Week\WeekContext;

/**
 * What a result means beyond the win/loss: titles, stipulations and
 * Money in the Bank (§10, §16, §20, §22).
 */
class MatchOutcomeHandler
{
    public function __construct(
        private TitleService $titles,
        private ChampionshipAssignment $assignment,
        private TitleShotService $shots,
        private LastChanceService $lastChance,
        private MitbService $mitb,
        private ContractService $contracts,
        private LedgerService $ledger,
        private NewsRecorder $news,
        private TitleRecords $records,
    ) {}

    public function apply(MatchSlot $slot, MatchResult $result, ResolvedMatch $resolved, WeekContext $context): void
    {
        $slot->loadMissing(['titleChampionship', 'stipulationChampionship', 'show', 'participants.saveWrestler.wrestler']);
        $names = $slot->participants->mapWithKeys(fn ($p): array => [$p->save_wrestler_id => $p->saveWrestler->wrestler->name])->all();
        $nameOf = fn (array $ids): string => implode(' & ', array_map(fn (int $id): string => $names[$id] ?? '?', $ids));

        $titleChanged = false;
        $banner = null;
        $championSide = null;
        $stipulationOutcome = null;
        $reignBefore = null;

        if ($slot->titleChampionship !== null) {
            foreach ($slot->competitorIds() as $id) {
                $context->data['title_match'][$id] = true;
            }

            [$titleChanged, $banner, $championSide, $reignBefore] = $this->resolveTitle($slot, $slot->titleChampionship, $resolved, $context, $nameOf);
            $this->shots->fulfil($slot, $context->week);
        }

        if ($slot->stipulation !== null) {
            $stipulationOutcome = $this->resolveStipulation($slot, $resolved, $context, $nameOf, $championSide, $reignBefore, $titleChanged);
        }

        if ($slot->matchType?->isMoneyInTheBank()) {
            $winner = SaveWrestler::with('wrestler')->findOrFail($resolved->winners[0]);
            $this->mitb->award($context->save, $winner, $context->week);
            $stipulationOutcome = "{$winner->name()} earns a {$this->mitb->worldTitle($winner->brand ?? $slot->owner_brand ?? $slot->show->brand ?? Brand::Raw)->name} shot";
        }

        if ($slot->fixed_kind === FixedSlotKind::RoyalRumble) {
            $winner = SaveWrestler::with('wrestler')->findOrFail($resolved->winners[0]);
            Season::where('save_id', $context->save->id)->where('number', $context->season)->update(['royal_rumble_winner_id' => $winner->id]);
            $stipulationOutcome = "{$winner->name()} is going to the WrestleMania main event";

            $this->news->record($context->save, NewsEventType::PpvResults, NewsTier::Headline, NewsBrand::Both, [
                'ppv' => 'Royal Rumble',
                'top_result' => "{$winner->name()}'s Royal Rumble win",
            ], ['wrestler_ids' => [$winner->id]], priority: 90, week: $context->week);
        }

        if ($slot->fixed_kind === FixedSlotKind::SurvivorSeriesMainEvent) {
            $brand = SaveWrestler::find($resolved->winners[0])?->brand;
            $stipulationOutcome = "Team {$brand?->label()} survives";
        }

        if ($slot->fixed_kind === FixedSlotKind::MitbCashIn && $reignBefore !== null) {
            $this->mitb->settleCashIn($slot, $titleChanged, $reignBefore->holderName(), $context->week);
        }

        $result->update([
            'title_changed' => $titleChanged,
            'title_banner' => $banner,
            'stipulation_outcome' => $stipulationOutcome,
        ]);
    }

    /**
     * Titles change hands only on a decisive win (§20). Defenses count up.
     *
     * @param  callable(list<int>): string  $nameOf
     * @return array{0: bool, 1: string|null, 2: int|null, 3: TitleReign|null}
     */
    private function resolveTitle(MatchSlot $slot, Championship $championship, ResolvedMatch $resolved, WeekContext $context, callable $nameOf): array
    {
        $save = $context->save;
        $week = $context->week;
        $show = $slot->show;
        $reign = $this->titles->currentReign($save, $championship);
        $sides = $slot->sides();

        if ($reign === null) {
            if (! $resolved->isDecisive()) {
                return [false, null, null, null];
            }

            $new = $this->titles->changeHands($save, $championship, $this->holderFor($save, $championship, $resolved->winners), $week, WonVia::Match, $show, $slot->id);
            $this->announceChange($new, $resolved, $nameOf, $show->name, $week);

            return [true, 'new_champion', null, null];
        }

        $championSide = null;

        foreach ($sides as $side => $ids) {
            if (array_diff($reign->holderIds(), $ids) === []) {
                $championSide = $side;
            }
        }

        $championWins = $resolved->winnerSide === $championSide;

        if ($championWins || ! $resolved->isDecisive()) {
            $reign->increment('defenses');
            $challengers = array_values(array_diff($slot->competitorIds(), $reign->holderIds()));

            $this->news->record($save, $championWins ? NewsEventType::TitleRetained : NewsEventType::RetainedByDq, NewsTier::Story, $championship->brand, [
                'champion' => $reign->holderName(),
                'title' => $championship->name,
                'challenger' => $nameOf($challengers),
                'method' => mb_strtolower($resolved->method->label()),
            ], ['championship_id' => $championship->id, 'wrestler_ids' => $slot->competitorIds()], priority: 35, week: $week, dedupeKey: "slot:{$slot->id}");

            return [false, 'and_still', $championSide, $reign];
        }

        $via = $slot->fixed_kind === FixedSlotKind::MitbCashIn ? WonVia::MitbCashIn : WonVia::Match;
        $new = $this->titles->changeHands($save, $championship, $this->holderFor($save, $championship, $resolved->winners), $week, $via, $show, $slot->id);
        $this->records->checkAfterReignEnded($reign->refresh(), $week);
        $this->records->checkAfterReignStarted($new, $week);
        $this->announceChange($new, $resolved, $nameOf, $show->name, $week, $reign->holderName());

        return [true, 'new_champion', $championSide, $reign];
    }

    /**
     * @param  callable(list<int>): string  $nameOf
     */
    private function resolveStipulation(MatchSlot $slot, ResolvedMatch $resolved, WeekContext $context, callable $nameOf, ?int $championSide, ?TitleReign $reign, bool $titleChanged): ?string
    {
        $save = $context->save;
        $week = $context->week;
        $sides = $slot->sides();

        if ($slot->stipulation === Stipulation::ChampionVsContender) {
            $championship = $slot->stipulationChampionship;
            $cvcReign = $championship === null ? null : $this->titles->currentReign($save, $championship);

            if ($championship === null || $cvcReign === null) {
                return null;
            }

            $champSide = $this->sideOf($sides, $cvcReign->holderIds());
            $contenders = array_values(array_diff($slot->competitorIds(), $cvcReign->holderIds()));

            // A DQ or count-out win by the contender counts as a champion win (§22).
            if ($resolved->winnerSide !== $champSide && $resolved->isDecisive()) {
                $this->shots->create($save, $championship, $contenders, $cvcReign->holderIds(), $slot, $week);
                $this->news->record($save, NewsEventType::ContenderEarnedShot, NewsTier::Headline, $championship->brand, [
                    'contender' => $nameOf($contenders),
                    'champion' => $cvcReign->holderName(),
                    'title' => $championship->name,
                    'next_show' => 'next week',
                ], ['championship_id' => $championship->id, 'wrestler_ids' => $slot->competitorIds()], priority: 70, week: $week);

                return "{$nameOf($contenders)} earns a {$championship->name} match next week";
            }

            return "{$cvcReign->holderName()} holds off the challenge";
        }

        // For stipulations, a contender's DQ or count-out win is a loss to the champion(s).
        $stipulationWinner = $resolved->winnerSide;

        if ($championSide !== null && ! $resolved->isDecisive()) {
            $stipulationWinner = $championSide;
        }

        $losers = array_merge(...array_values(array_diff_key($sides, [$stipulationWinner => true])));

        if ($slot->stipulation === Stipulation::LoserLeavesShow) {
            return $this->loserLeaves($slot, $losers, $sides[$stipulationWinner] ?? [], $context, $nameOf);
        }

        if ($slot->stipulation === Stipulation::LastChance && $reign !== null) {
            $contenders = array_values(array_diff($slot->competitorIds(), $reign->holderIds()));
            $won = $titleChanged;

            if (! $won) {
                $this->lastChance->lockOut($reign, $contenders, $week, $slot->id);
            }

            $this->news->record($save, NewsEventType::LastChanceResult, NewsTier::Headline, $reign->brand, [
                'contender' => $nameOf($contenders),
                'won_or_lost' => $won ? 'won' : 'lost',
                'title' => $reign->championship->name,
            ], ['championship_id' => $reign->championship_id, 'wrestler_ids' => $slot->competitorIds()], priority: 70, week: $week);

            return $won
                ? "{$nameOf($contenders)} wins their last chance"
                : "{$nameOf($contenders)} can't challenge {$reign->holderName()} again while they hold the title";
        }

        return null;
    }

    /**
     * Loser Leaves Show (§22): released right after the show, contract refunded,
     * titles vacated, briefcase voided, and a 4-week re-sign lockout.
     *
     * @param  list<int>  $losers
     * @param  list<int>  $winners
     * @param  callable(list<int>): string  $nameOf
     */
    private function loserLeaves(MatchSlot $slot, array $losers, array $winners, WeekContext $context, callable $nameOf): string
    {
        $week = $context->week;
        $brand = null;

        foreach ($losers as $id) {
            $wrestler = SaveWrestler::with(['wrestler', 'activeContract'])->findOrFail($id);
            $brand = $wrestler->brand;

            if ($brand === null) {
                continue;
            }

            $refund = $wrestler->activeContract?->remainingValue() ?? 0;

            if ($refund > 0) {
                $this->ledger->post($context->save, $brand, LedgerCategory::LoserLeavesRefund, $refund, "Loser Leaves Show refund: {$wrestler->name()}", $week, $wrestler->activeContract);
            }

            $this->contracts->release($wrestler, $week, ReignEndReason::LoserLeaves);
            $wrestler->update(['unsignable_until_week' => $week + 1 + (int) config('gm.stipulations.loser_leaves_resign_lockout_weeks')]);
        }

        $brandLabel = $brand?->label() ?? 'the brand';

        $this->news->record($context->save, NewsEventType::LoserLeaves, NewsTier::Headline, $brand ?? NewsBrand::Both, [
            'loser' => $nameOf($losers),
            'brand' => $brandLabel,
            'winner' => $nameOf($winners),
        ], ['wrestler_ids' => [...$losers, ...$winners]], priority: 80, week: $week);

        return "{$nameOf($losers)} leaves {$brandLabel}";
    }

    /**
     * @param  list<int>  $winnerIds
     */
    private function holderFor(Save $save, Championship $championship, array $winnerIds): SaveWrestler|TagTeam
    {
        $members = SaveWrestler::with('wrestler')->whereIn('id', $winnerIds)->get();

        return $championship->is_tag
            ? $this->assignment->findOrCreateTeam($save, $championship->brand, $members)
            : $members->firstOrFail();
    }

    /**
     * @param  callable(list<int>): string  $nameOf
     */
    private function announceChange(TitleReign $new, ResolvedMatch $resolved, callable $nameOf, string $showName, int $week, ?string $loser = null): void
    {
        $this->news->record($new->game, NewsEventType::TitleChange, NewsTier::Headline, $new->brand, [
            'winner' => $new->holderName(),
            'loser' => $loser ?? $nameOf($resolved->losers),
            'title' => $new->championship->name,
            'show' => $showName,
        ], ['championship_id' => $new->championship_id, 'wrestler_ids' => [...$resolved->winners, ...$resolved->losers]], priority: 100, week: $week, dedupeKey: "slot:{$new->won_match_id}");
    }

    /**
     * @param  array<int, list<int>>  $sides
     * @param  list<int>  $holders
     */
    private function sideOf(array $sides, array $holders): ?int
    {
        foreach ($sides as $side => $ids) {
            if (array_diff($holders, $ids) === []) {
                return $side;
            }
        }

        return null;
    }
}
