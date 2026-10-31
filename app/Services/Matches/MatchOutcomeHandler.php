<?php

namespace App\Services\Matches;

use App\Enums\Brand;
use App\Enums\ChampionshipEligibility;
use App\Enums\FixedSlotKind;
use App\Enums\LedgerCategory;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\ReignEndReason;
use App\Enums\RosterType;
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
use App\Services\Factions\FactionNamer;
use App\Services\Finance\LedgerService;
use App\Services\News\NewsRecorder;
use App\Services\Titles\ChampionshipAssignment;
use App\Services\Titles\ContendershipService;
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
        private FactionNamer $factions,
        private ContendershipService $contenderships,
    ) {}

    public function apply(MatchSlot $slot, MatchResult $result, ResolvedMatch $resolved, WeekContext $context): void
    {
        $slot->loadMissing(['titleChampionship', 'stipulationChampionship', 'show', 'participants.saveWrestler.wrestler']);
        // Factions are named as such (house rule); they only form from one side.
        $sides = $slot->sides();
        /** @param array<int, int> $ids */
        $nameOf = fn (array $ids): string => $this->factions->name($slot->show->save_id, $ids, $context->week, $sides);

        $titleChanged = false;
        $banner = null;
        $championSide = null;
        $stipulationOutcome = null;
        $reignBefore = null;
        $titlesWon = null;

        if ($slot->titleChampionship !== null) {
            foreach ($slot->competitorIds() as $id) {
                $context->data['title_match'][$id] = true;
            }

            [$titleChanged, $banner, $championSide, $reignBefore] = $this->resolveTitle($slot, $slot->titleChampionship, $resolved, $context, $nameOf);
            $this->shots->fulfil($slot, $context->week);
        }

        if ($slot->stipulation === Stipulation::WinnerTakesAll) {
            [$stipulationOutcome, $titlesWon, $titleChanged] = $this->winnerTakesAll($slot, $resolved, $context, $nameOf);
        } elseif ($slot->stipulation !== null) {
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
            ], ['wrestler_ids' => [$winner->id]], priority: 90, week: $context->week, dedupeKey: "slot:{$slot->id}");
        }

        if ($slot->fixed_kind === FixedSlotKind::SurvivorSeriesMainEvent) {
            $brand = SaveWrestler::find($resolved->winners[0])?->brand;
            $stipulationOutcome = "Team {$brand?->label()} survives";
        }

        // Before, during or after a match (house rule): settled once its match resolves.
        if ($slot->mitb_cash_in !== null && $reignBefore !== null) {
            // In a triple threat the title can go to someone else: the cash-in
            // only wins if the holder does.
            $won = $titleChanged && in_array($this->mitb->cashInHolder($slot), $resolved->winners, true);
            $this->mitb->settleCashIn($slot, $won, $reignBefore->holderName(), $context->week);
        }

        $result->update([
            'title_changed' => $titleChanged,
            'champion_reign_id' => $reignBefore?->id,
            'title_banner' => $banner,
            'stipulation_outcome' => $stipulationOutcome,
            'titles_won' => $titlesWon,
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
            $this->announceChange($new, $slot, $resolved, $nameOf, $show->name, $week);

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

        $via = $slot->mitb_cash_in !== null && in_array($this->mitb->cashInHolder($slot), $resolved->winners, true) ? WonVia::MitbCashIn : WonVia::Match;
        $new = $this->titles->changeHands($save, $championship, $this->holderFor($save, $championship, $resolved->winners), $week, $via, $show, $slot->id);
        $this->records->checkAfterReignEnded($reign->refresh(), $week);
        $this->records->checkAfterReignStarted($new, $week);
        $this->announceChange($new, $slot, $resolved, $nameOf, $show->name, $week, $reign->holderName());

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
                    ...$this->pronouns($contenders),
                ], ['championship_id' => $championship->id, 'wrestler_ids' => $slot->competitorIds()], priority: (int) config('gm.news.stipulation_priority.contender_earned_shot'), week: $week, dedupeKey: "slot:{$slot->id}");

                return "{$nameOf($contenders)} earns a {$championship->name} match next week";
            }

            // House rule: the champion dodged a bullet.
            $this->news->record($save, NewsEventType::ChampionSurvivedChallenge, NewsTier::Story, $championship->brand, [
                'champion' => $cvcReign->holderName(),
                'contender' => $nameOf($contenders),
                'title' => $championship->name,
                ...$this->pronouns($cvcReign->holderIds()),
            ], ['championship_id' => $championship->id, 'wrestler_ids' => $slot->competitorIds()], priority: (int) config('gm.news.stipulation_priority.champion_survived_challenge'), week: $week, dedupeKey: "slot:{$slot->id}");

            return "{$cvcReign->holderName()} holds off the challenge";
        }

        if ($slot->stipulation === Stipulation::NumberOneContender) {
            return $this->numberOneContender($slot, $resolved, $context, $nameOf);
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
            ], ['championship_id' => $reign->championship_id, 'wrestler_ids' => $slot->competitorIds()], priority: 70, week: $week, dedupeKey: "slot:{$slot->id}");

            return $won
                ? "{$nameOf($contenders)} wins their last chance"
                : "{$nameOf($contenders)} can't challenge {$reign->holderName()} again while they hold the title";
        }

        return null;
    }

    /**
     * Winner Takes All (house rule): on a decisive win the winner takes every
     * contested title they don't already hold. A DQ or count-out changes
     * nothing, and each champion's defense counts.
     *
     * @param  callable(list<int>): string  $nameOf
     * @return array{0: string|null, 1: list<array{championship_id: int, reign_id: int}>|null, 2: bool}
     */
    private function winnerTakesAll(MatchSlot $slot, ResolvedMatch $resolved, WeekContext $context, callable $nameOf): array
    {
        $save = $context->save;
        $week = $context->week;
        $show = $slot->show;
        $championships = Championship::whereIn('id', $slot->contested_title_ids ?? [])->orderBy('sort_order')->get();

        foreach ($slot->competitorIds() as $id) {
            $context->data['title_match'][$id] = true;
        }

        $reigns = $championships
            ->map(fn (Championship $c): ?TitleReign => $this->titles->currentReign($save, $c))
            ->filter()
            ->values();

        if ($reigns->isEmpty()) {
            return [null, null, false];
        }

        if (! $resolved->isDecisive()) {
            $reigns->each(fn (TitleReign $reign) => $reign->increment('defenses'));

            return ['Nobody takes it all: every champion keeps their title', null, false];
        }

        $winnerId = $resolved->winners[0];
        $winner = SaveWrestler::with('wrestler')->findOrFail($winnerId);
        $won = [];
        $taken = [];
        $formerChampions = [];

        foreach ($reigns as $reign) {
            if (in_array($winnerId, $reign->holderIds(), true)) {
                $reign->increment('defenses');
                $won[] = ['championship_id' => $reign->championship_id, 'reign_id' => $reign->id];

                continue;
            }

            $new = $this->titles->changeHands($save, $reign->championship, $winner, $week, WonVia::Match, $show, $slot->id);
            $this->records->checkAfterReignEnded($reign->refresh(), $week);
            $this->records->checkAfterReignStarted($new, $week);
            $won[] = ['championship_id' => $reign->championship_id, 'reign_id' => $new->id];
            $taken[] = $reign->championship->name;
            $formerChampions[] = $reign->holderName();
        }

        $titles = $this->listNames(array_values($championships->map(fn (Championship $c): string => $c->name)->all()));

        if ($taken !== []) {
            $variant = (string) $context->rng("winner-takes-all.{$show->streamKey()}.{$slot->position}")
                ->weightedPick((array) config('gm.news.winner_takes_all_variants'));

            $this->news->record($save, NewsEventType::WinnerTakesAll, NewsTier::Headline, $show->brand ?? $winner->brand ?? NewsBrand::Both, [
                'outcome' => $variant,
                'winner' => $winner->name(),
                'losers' => $nameOf($resolved->losers),
                'titles' => $titles,
                'how' => $resolved->how($slot->matchType),
                ...$this->pronouns([$winnerId]),
                'loser_pronoun' => count($resolved->losers) > 1 ? 'they' : 'he',
                'losers_were' => count($resolved->losers) > 1 ? 'were' : 'was',
            ], ['championship_id' => $championships->first()?->id, 'wrestler_ids' => $slot->competitorIds()], priority: (int) config('gm.news.stipulation_priority.winner_takes_all'), week: $week, dedupeKey: "slot:{$slot->id}");
        }

        return ["{$winner->name()} wins it all: {$titles}", $won, $taken !== []];
    }

    /**
     * #1 Contender's Match (house rule): the winning side becomes the
     * title's #1 contender(s), whatever the finish.
     *
     * @param  callable(list<int>): string  $nameOf
     */
    private function numberOneContender(MatchSlot $slot, ResolvedMatch $resolved, WeekContext $context, callable $nameOf): ?string
    {
        $championship = $slot->stipulationChampionship;
        $reign = $championship === null ? null : $this->titles->currentReign($context->save, $championship);

        if ($championship === null || $reign === null) {
            return null;
        }

        $contenders = $resolved->winners;
        $this->contenderships->create($context->save, $championship, $contenders, $reign->holderIds(), $slot, $context->week);

        $variant = (string) $context->rng("number-one-contender.{$slot->show->streamKey()}.{$slot->position}")
            ->weightedPick((array) config('gm.news.number_one_contender_variants'));
        $champions = $reign->holderIds();

        $this->news->record($context->save, NewsEventType::NumberOneContender, NewsTier::Headline, $championship->brand, [
            'outcome' => $variant,
            'contender' => $nameOf($contenders),
            'champion' => $reign->holderName(),
            'title' => $championship->name,
            ...$this->pronouns($contenders),
            'champion_pronoun' => $this->pronouns($champions)['pronoun_lc'],
            'champion_possessive' => count($champions) > 1 ? 'their' : ($this->pronouns($champions)['pronoun_lc'] === 'she' ? 'her' : 'his'),
        ], ['championship_id' => $championship->id, 'wrestler_ids' => $slot->competitorIds()], priority: (int) config('gm.news.stipulation_priority.number_one_contender'), week: $context->week, dedupeKey: "slot:{$slot->id}");

        $become = count($contenders) > 1 ? 'become the #1 contenders' : 'becomes the #1 contender';

        return "{$nameOf($contenders)} {$become} for the {$championship->name}";
    }

    /**
     * News wording for one wrestler (he/she) or a team (they).
     *
     * @param  list<int>  $ids
     * @return array{pronoun: string, pronoun_lc: string, is: string, has: string, possessive: string}
     */
    private function pronouns(array $ids): array
    {
        if (count($ids) > 1) {
            return ['pronoun' => 'They', 'pronoun_lc' => 'they', 'is' => 'are', 'has' => 'have', 'possessive' => 'their'];
        }

        $diva = SaveWrestler::with('wrestler')->find($ids[0] ?? 0)?->wrestler->roster_type === RosterType::Diva;

        return $diva
            ? ['pronoun' => 'She', 'pronoun_lc' => 'she', 'is' => 'is', 'has' => 'has', 'possessive' => 'her']
            : ['pronoun' => 'He', 'pronoun_lc' => 'he', 'is' => 'is', 'has' => 'has', 'possessive' => 'his'];
    }

    /**
     * "the A", "the A and the B", "the A, the B and the C".
     *
     * @param  list<string>  $names
     */
    private function listNames(array $names): string
    {
        $names = array_map(fn (string $n): string => "the {$n}", $names);
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names)." and {$last}";
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
        ], ['wrestler_ids' => [...$losers, ...$winners]], priority: 80, week: $week, dedupeKey: "slot:{$slot->id}");

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
    private function announceChange(TitleReign $new, MatchSlot $slot, ResolvedMatch $resolved, callable $nameOf, string $showName, int $week, ?string $loser = null): void
    {
        $championship = $new->championship;
        // The Women's title is the Divas' only title; tag titles have two champions.
        $tag = $championship->is_tag;

        if ($tag && $this->announceFactionChange($new, $slot, $resolved, $nameOf, $week, $loser)) {
            return;
        }

        $this->news->record($new->game, NewsEventType::TitleChange, NewsTier::Headline, $new->brand, [
            'winner' => $new->holderName(),
            'loser' => $loser ?? $nameOf($resolved->losers),
            'title' => $championship->name,
            'show' => $showName,
            'via' => $resolved->via($slot->matchType),
            'how' => $resolved->how($slot->matchType),
            'walks' => $tag ? 'walk' : 'walks',
            'the_champion' => $tag ? 'The champions' : 'The champion',
            'looks' => $tag ? 'look' : 'looks',
            'champion_pronoun' => $tag ? 'they' : ($championship->eligibility === ChampionshipEligibility::DivasOnly ? 'she' : 'he'),
        ], ['championship_id' => $new->championship_id, 'wrestler_ids' => [...$resolved->winners, ...$resolved->losers]], priority: 100, week: $week, dedupeKey: "slot:{$new->won_match_id}");
    }

    /**
     * Tag titles and factions (house rule): new champions who are a faction
     * get their own headline in place of the title change, and champions
     * who were a faction a story about the loss. Returns whether the
     * headline was written.
     *
     * @param  callable(list<int>): string  $nameOf
     */
    private function announceFactionChange(TitleReign $new, MatchSlot $slot, ResolvedMatch $resolved, callable $nameOf, int $week, ?string $loser): bool
    {
        $save = $new->game;
        $championship = $new->championship;
        $previous = TitleReign::where('save_id', $save->id)
            ->where('championship_id', $championship->id)
            ->where('id', '<', $new->id)
            ->where('lost_match_id', $slot->id)
            ->latest('id')
            ->first();
        $lostBy = $previous === null ? null : $this->factions->factionOf($save->id, $previous->holderIds(), $previous->won_week);

        if ($lostBy !== null) {
            $this->news->record($save, NewsEventType::FactionTitleLost, NewsTier::Story, $new->brand, [
                'faction' => $lostBy->faction_name,
                'title' => $championship->name,
                'winner' => $new->holderName(),
                'via' => $resolved->via($slot->matchType),
                'how' => $resolved->how($slot->matchType),
            ], ['championship_id' => $championship->id, 'wrestler_ids' => $previous->holderIds(), 'faction_id' => $lostBy->id], priority: (int) config('gm.news.faction_priority.title_lost'), week: $week, dedupeKey: "faction-lost:{$slot->id}");
        }

        $wonBy = $this->factions->factionOf($save->id, $new->holderIds(), $week);

        if ($wonBy === null) {
            return false;
        }

        $this->news->record($save, NewsEventType::FactionTitleWon, NewsTier::Headline, $new->brand, [
            'faction' => $wonBy->faction_name,
            'loser' => $loser ?? $nameOf($resolved->losers),
            'title' => $championship->name,
            'via' => $resolved->via($slot->matchType),
            'how' => $resolved->how($slot->matchType),
        ], ['championship_id' => $new->championship_id, 'wrestler_ids' => [...$resolved->winners, ...$resolved->losers], 'faction_id' => $wonBy->id], priority: (int) config('gm.news.faction_priority.title_won'), week: $week, dedupeKey: "slot:{$new->won_match_id}");

        return true;
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
