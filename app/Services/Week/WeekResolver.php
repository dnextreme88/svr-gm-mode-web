<?php

namespace App\Services\Week;

use App\Enums\MatchFamily;
use App\Enums\SaveStage;
use App\Enums\ShowStatus;
use App\Exceptions\GameRuleException;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\Show;
use App\Models\WeekAiring;
use App\Models\WeekResolution;
use App\Services\Calendar\CalendarService;
use App\Services\Matches\ShowResolver;
use App\Services\Week\Steps\AirShows;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a week (§2): runs every step in one DB transaction and records a
 * WeekResolution so the same week can never resolve twice.
 *
 * Live results (house rule): `begin()` airs the week up to the player's
 * first match and stops. The player then decides each of their matches in
 * air order (`decide()`: simulate or Book to Win), or `simulateAll()`. The
 * rival GM's shows air on their own as soon as they come up. After the last
 * match the rest of the week runs. Between requests the week's state lives
 * in a `WeekAiring`.
 */
class WeekResolver
{
    /**
     * Step order (see docs/20261018-gm-mode-build.md, Architecture).
     *
     * @var list<class-string<WeekStep>>
     */
    public const STEPS = [
        Steps\PrepareShows::class,
        AirShows::class,
        // Right after the shows: teams that just won their 3rd match together.
        Steps\FactionFormation::class,
        Steps\RecoverFatigue::class,
        Steps\InjuryReturns::class,
        Steps\TickContracts::class,
        Steps\UpdateMorale::class,
        // After defections, so the rival GM can refill what it just lost.
        Steps\RivalRosterUpkeep::class,
        Steps\ApplyPendingMoves::class,
        Steps\TitleUpkeep::class,
        // House rule: the rival awards or forfeits its vacated titles' #1 contenderships.
        Steps\SettleTitleVacancies::class,
        Steps\TrackDeficit::class,
        Steps\SendContractEmails::class,
        Steps\SendReminders::class,
        Steps\PpvBuzz::class,
        Steps\WrestleManiaWrapUp::class,
        // Last before the week ends: fills whatever the week's news left empty.
        Steps\QuietWeekNews::class,
        // Once the week is done: each brand's shows earn their income.
        Steps\PayShowIncome::class,
        Steps\AdvanceWeek::class,
    ];

    /**
     * @var list<class-string<WeekGuard>>
     */
    public const GUARDS = [
        Guards\DeficitChoiceGuard::class,
        Guards\TitleShotGuard::class,
        Guards\TitleVacancyGuard::class,
        Guards\PpvEntrantsGuard::class,
    ];

    /**
     * @param  list<WeekStep>|null  $steps  override the step list (tests)
     */
    public function __construct(
        private CalendarService $calendar,
        private ?array $steps = null,
    ) {}

    /**
     * Resolve the save's current week.
     */
    public function resolve(Save $save): WeekResolution
    {
        return $this->resolveWeek($save, $save->current_week);
    }

    /**
     * Idempotent per (save, week): a week that already resolved returns its
     * existing record without running anything again. A week that is airing
     * live is finished by simulating the rest of it.
     */
    public function resolveWeek(Save $save, int $week): WeekResolution
    {
        $existing = WeekResolution::where('save_id', $save->id)->where('week_number', $week)->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($this->airing($save, $week) !== null) {
            $this->simulateAll($save);

            return WeekResolution::where('save_id', $save->id)->where('week_number', $week)->firstOrFail();
        }

        foreach (self::GUARDS as $guard) {
            app($guard)->check($save, $week);
        }

        return DB::transaction(function () use ($save, $week): WeekResolution {
            $locked = $this->lock($save, $week);

            $existing = WeekResolution::where('save_id', $locked->id)->where('week_number', $week)->first();

            if ($existing !== null) {
                return $existing;
            }

            $resolution = WeekResolution::create([
                'save_id' => $locked->id,
                'week_number' => $week,
                'season' => $this->calendar->seasonOf($week),
            ]);

            $context = new WeekContext($locked, $week, $this->calendar->seasonOf($week));

            foreach ($this->steps() as $step) {
                $step->handle($context);
            }

            $resolution->update(['summary' => $context->data['summary'] ?? []]);
            $save->refresh();

            return $resolution;
        });
    }

    /**
     * The week airing live and not finished yet, if any.
     */
    public function airing(Save $save, ?int $week = null): ?WeekAiring
    {
        return WeekAiring::where('save_id', $save->id)
            ->where('week_number', $week ?? $save->current_week)
            ->whereNull('completed_at')
            ->first();
    }

    /**
     * Roster moves wait until the week's results are in.
     */
    public function assertNotAiring(Save $save): void
    {
        if ($this->airing($save) !== null) {
            throw new GameRuleException("Finish this week's results first.");
        }
    }

    /**
     * Start airing the current week live: book the AI's cards, air the
     * rival's shows that come before the player's first match, and wait
     * there. Does nothing if the week already started or resolved.
     */
    public function begin(Save $save): void
    {
        $week = $save->current_week;

        if ($this->started($save, $week)) {
            return;
        }

        foreach (self::GUARDS as $guard) {
            app($guard)->check($save, $week);
        }

        DB::transaction(function () use ($save, $week): void {
            $locked = $this->lock($save, $week);

            if ($this->started($locked, $week)) {
                return;
            }

            $airing = WeekAiring::create(['save_id' => $locked->id, 'week_number' => $week, 'context' => [], 'rng_states' => []]);
            $context = new WeekContext($locked, $week, $this->calendar->seasonOf($week));

            foreach ($this->opening() as $step) {
                app($step)->handle($context);
            }

            $this->airShows()->start($context);
            Show::where('save_id', $locked->id)
                ->where('week_number', $week)
                ->where('status', '!=', ShowStatus::Aired)
                ->update(['status' => ShowStatus::Airing]);

            $this->proceed($airing, $context);
        });

        $save->refresh();
    }

    /**
     * Air the player's next match: simulated, or won by `$bookedSide` (Book
     * to Win). `$slotId` must be the match that is up next. A Gauntlet
     * takes `$bookedBouts` instead: the side booked to win each bout, in
     * order, with null (and only nulls after it) to simulate the rest.
     *
     * @param  list<int|null>  $bookedBouts
     */
    public function decide(Save $save, int $slotId, ?int $bookedSide = null, array $bookedBouts = []): void
    {
        DB::transaction(function () use ($save, $slotId, $bookedSide, $bookedBouts): void {
            [$airing, $context] = $this->resume($save);
            $show = $this->airShows()->current($context);
            $next = $show === null ? null : $this->shows()->nextMatch($show);

            if ($show === null || $next === null || $next->id !== $slotId) {
                throw new GameRuleException('That match is not up next.');
            }

            if ($bookedSide !== null && ! array_key_exists($bookedSide, $next->sides())) {
                throw new GameRuleException('Pick one of the sides in this match.');
            }

            if ($bookedBouts !== []) {
                $this->assertGauntletPicks($next, $bookedBouts);
            }

            $this->shows()->airNext($show, $context, $bookedSide, $bookedBouts);
            $this->proceed($airing, $context);
        });

        $save->refresh();
    }

    /**
     * Gauntlet picks follow the chain: bout 1 is entrants 1 and 2, and each
     * booked winner meets the next entrant. Once a bout is simulated the
     * next pairing isn't known, so the rest must be simulated too.
     *
     * @param  list<int|null>  $picks
     */
    private function assertGauntletPicks(MatchSlot $slot, array $picks): void
    {
        $order = array_keys($slot->sides());

        if ($slot->matchType?->family !== MatchFamily::Gauntlet || count($picks) > count($order) - 1) {
            throw new GameRuleException('Bout picks are only for a Gauntlet, one per bout.');
        }

        $holder = $order[0];

        foreach ($picks as $round => $pick) {
            if ($pick === null) {
                if (array_filter(array_slice($picks, $round), fn (?int $p): bool => $p !== null) !== []) {
                    throw new GameRuleException('After a simulated bout, the rest of the Gauntlet is simulated too.');
                }

                return;
            }

            if (! in_array($pick, [$holder, $order[$round + 1]], true)) {
                throw new GameRuleException('Bout '.($round + 1).': pick one of the two wrestlers in it.');
            }

            $holder = $pick;
        }
    }

    /**
     * Simulate every match left this week, then finish the week.
     */
    public function simulateAll(Save $save): void
    {
        DB::transaction(function () use ($save): void {
            [$airing, $context] = $this->resume($save);

            while (! $airing->isComplete() && ($show = $this->airShows()->current($context)) !== null) {
                $this->shows()->airNext($show, $context);
                $this->proceed($airing, $context);
            }
        });

        $save->refresh();
    }

    /**
     * Air whatever needs no decision (the rival's shows, a finished show's
     * last promos), stop at the next match the player decides, and finish
     * the week once everything has aired.
     */
    private function proceed(WeekAiring $airing, WeekContext $context): void
    {
        foreach ($this->airShows()->ordered($context) as $show) {
            if ($show->status === ShowStatus::Aired) {
                continue;
            }

            if (! $this->airShows()->isLive($show, $context->save)) {
                $this->shows()->air($show, $context);

                continue;
            }

            if ($this->shows()->nextMatch($show) !== null) {
                $airing->update(['context' => $context->data, 'rng_states' => $context->streamStates()]);

                return;
            }

            $this->shows()->close($show, $context);
        }

        foreach ($this->closing() as $step) {
            app($step)->handle($context);
        }

        WeekResolution::create([
            'save_id' => $context->save->id,
            'week_number' => $context->week,
            'season' => $context->season,
            'summary' => $context->data['summary'] ?? [],
        ]);

        $airing->update(['context' => $context->data, 'rng_states' => $context->streamStates(), 'completed_at' => now()]);
    }

    /**
     * @return array{0: WeekAiring, 1: WeekContext}
     */
    private function resume(Save $save): array
    {
        /** @var Save $locked */
        $locked = Save::whereKey($save->id)->lockForUpdate()->firstOrFail();
        $airing = $this->airing($locked) ?? throw new GameRuleException('This week is not airing.');

        $context = new WeekContext($locked, $airing->week_number, $this->calendar->seasonOf($airing->week_number));
        $context->data = $airing->context;
        $context->restoreStreams($airing->rng_states);

        return [$airing, $context];
    }

    private function started(Save $save, int $week): bool
    {
        return WeekResolution::where('save_id', $save->id)->where('week_number', $week)->exists()
            || WeekAiring::where('save_id', $save->id)->where('week_number', $week)->exists();
    }

    private function lock(Save $save, int $week): Save
    {
        /** @var Save $locked */
        $locked = Save::whereKey($save->id)->lockForUpdate()->firstOrFail();

        if ($locked->stage !== SaveStage::InSeason) {
            throw new GameRuleException('The season has not started yet.');
        }

        if ($week !== $locked->current_week) {
            throw new GameRuleException("Week {$week} is not the current week.");
        }

        return $locked;
    }

    /**
     * Steps that run before the shows air.
     *
     * @return list<class-string<WeekStep>>
     */
    private function opening(): array
    {
        return array_slice(self::STEPS, 0, (int) array_search(AirShows::class, self::STEPS, true));
    }

    /**
     * Steps that run after the shows air.
     *
     * @return list<class-string<WeekStep>>
     */
    private function closing(): array
    {
        return array_slice(self::STEPS, (int) array_search(AirShows::class, self::STEPS, true) + 1);
    }

    private function airShows(): AirShows
    {
        return app(AirShows::class);
    }

    private function shows(): ShowResolver
    {
        return app(ShowResolver::class);
    }

    /**
     * @return list<WeekStep>
     */
    private function steps(): array
    {
        return $this->steps ?? array_map(fn (string $class): WeekStep => app($class), self::STEPS);
    }
}
