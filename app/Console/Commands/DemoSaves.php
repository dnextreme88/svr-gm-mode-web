<?php

namespace App\Console\Commands;

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Enums\ContractStatus;
use App\Enums\DraftMode;
use App\Enums\InjurySeverity;
use App\Enums\PpvKey;
use App\Enums\ReignEndReason;
use App\Enums\RosterType;
use App\Enums\SaveStage;
use App\Enums\ShowType;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\Contract;
use App\Models\Injury;
use App\Models\MatchParticipant;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Ai\AiGm;
use App\Services\Ai\AiTradeJudge;
use App\Services\Attendance\SummerSlamMainEvent;
use App\Services\Calendar\CalendarService;
use App\Services\Contracts\ContractPricing;
use App\Services\Contracts\ContractService;
use App\Services\Game\NewGameService;
use App\Services\Ppv\PpvCardService;
use App\Services\Ppv\ReDraftService;
use App\Services\Titles\ChampionshipAssignment;
use App\Services\Titles\TitleService;
use App\Services\Week\ShowScheduler;
use App\Services\Week\WeekResolver;
use App\Services\Week\WeekSimulator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ready-made careers for testing (house rule, 2026-11-15). Run it after
 * `php artisan migrate:fresh --seed`, or `composer demo` for both. The
 * player runs Smackdown and the rival GM runs Raw; WWE rosters, champions
 * crowned, the player's expiring contracts renewed along the way.
 *
 * 1. WrestleMania week of season 1, Matt Hardy (Smackdown) the Royal
 *    Rumble winner.
 * 2. The same, with Jeff Hardy (Raw) the Royal Rumble winner.
 * 3. Season 2, week 1, after the re-draft.
 *
 * In the WrestleMania careers the Hardy is signed before the season (both
 * start in Free Agency), wins the Rumble as its booked winner, and is kept
 * clear of serious injuries. Smackdown leads the season's crowd race (its
 * weekly crowds are topped up if it trails), the rival has booked its
 * WrestleMania slots, and the player's slots are left empty to book.
 */
#[Signature('gm:demo-saves {--seed=demo : The base RNG seed; the same seed builds the same careers}')]
#[Description('Build the three test careers: two at WrestleMania (Matt or Jeff Hardy won the Rumble) and one at season 2, week 1')]
class DemoSaves extends Command
{
    private const PLAYER = Brand::Smackdown;

    public function __construct(
        private NewGameService $games,
        private ChampionshipAssignment $titles,
        private WeekSimulator $simulator,
        private WeekResolver $resolver,
        private ReDraftService $redraft,
        private ContractService $contracts,
        private ContractPricing $pricing,
        private CalendarService $calendar,
        private ShowScheduler $shows,
        private PpvCardService $ppvCards,
        private AiGm $ai,
        private AiTradeJudge $judge,
        private SummerSlamMainEvent $crowds,
        private TitleService $titleService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $seed = is_string($this->option('seed')) ? $this->option('seed') : 'demo';
        $mania = $this->calendar->absoluteWeek(1, $this->calendar->weeksPerSeason());

        $careers = [
            fn (): Save => $this->wrestleMania('WrestleMania: Matt Hardy won the Rumble', "{$seed}-matt", 'Matt Hardy', Brand::Smackdown, $mania),
            fn (): Save => $this->wrestleMania('WrestleMania: Jeff Hardy won the Rumble', "{$seed}-jeff", 'Jeff Hardy', Brand::Raw, $mania),
            fn (): Save => $this->seasonTwo('Season 2, week 1', "{$seed}-season-2", $this->calendar->absoluteWeek(2, 1)),
        ];

        foreach ($careers as $build) {
            try {
                $save = $build();
            } catch (GameRuleException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info("Career #{$save->id} \"{$save->name}\": season {$save->current_season}, week {$this->calendar->seasonWeek($save->current_week)} ({$save->stage->value}). Open it at ".route('gm.saves.show', $save, false));
        }

        return self::SUCCESS;
    }

    /**
     * Season 1 at WrestleMania week, with `$name` the Royal Rumble winner.
     */
    private function wrestleMania(string $title, string $seed, string $name, Brand $brand, int $mania): Save
    {
        $save = $this->newCareer($title, $seed, fn (Save $save) => $this->signForBrand($save, $name, $brand));
        $hardy = SaveWrestler::where('save_id', $save->id)->whereHas('wrestler', fn ($q) => $q->where('name', $name))->firstOrFail();
        $rumbleWeek = $this->calendar->absoluteWeek(1, (int) DB::table('ppv_events')->where('key', PpvKey::RoyalRumble->value)->value('season_week'));

        $this->simulateTo($save, $mania, function (Save $save, int $week) use ($hardy, $rumbleWeek): void {
            $this->heal($hardy, $week);

            if ($week === $rumbleWeek) {
                $this->bookRumbleWinner($save, $hardy, $week);
            }
        });

        // Week 51's matches could still have hurt him (or the champion he
        // faces) before the main event was set: heal both and set it again.
        $this->heal($hardy, $mania);
        $this->healWorldChampion($save, $hardy->refresh()->brand ?? $brand, $mania);
        $wrestleMania = $this->wrestleManiaShow($save, $mania);
        $this->ppvCards->setMainEvent($wrestleMania);
        $this->ai->bookShow($wrestleMania, $save->rivalBrand());
        $this->leadCrowdRace($save, $mania);

        $main = MatchSlot::where('show_id', $wrestleMania->id)->where('position', 10)->with('participants')->first();

        if (! $main?->participants->contains('save_wrestler_id', $hardy->id)) {
            $this->warn("{$name} isn't in the WrestleMania main event of \"{$title}\" (he may hold the world title or have changed brands).");
        }

        return $save->refresh();
    }

    private function seasonTwo(string $title, string $seed, int $target): Save
    {
        $save = $this->newCareer($title, $seed);
        $this->simulateTo($save, $target);

        return $save->refresh();
    }

    /**
     * A saved WWE-roster career for Smackdown with both brands' champions
     * crowned; `$before` runs before the titles are awarded.
     *
     * @param  (callable(Save): void)|null  $before
     */
    private function newCareer(string $title, string $seed, ?callable $before = null): Save
    {
        $save = $this->games->create($title, self::PLAYER, $seed, DraftMode::WweRoster);

        if ($before !== null) {
            $before($save);
        }

        $this->titles->autoAssign($save, self::PLAYER);
        $this->titles->autoAssign($save, $save->rivalBrand());
        $this->titles->complete($save->refresh());
        $save->update(['saved_at' => now()]);
        $this->line("Building \"{$title}\"…");

        return $save->refresh();
    }

    /**
     * Air weeks until `$target` opens. `$each` runs before each week is
     * booked and aired. A re-draft on the way is run with both GMs' tags
     * on their most valuable superstars.
     *
     * @param  (callable(Save, int): void)|null  $each
     */
    private function simulateTo(Save $save, int $target, ?callable $each = null): void
    {
        $bar = $this->output->createProgressBar($target - 1);
        $bar->start();

        while ($save->refresh()->current_week < $target) {
            $week = $save->current_week;

            DB::transaction(function () use ($save, $week, $each): void {
                if ($save->stage === SaveStage::Redrafting) {
                    $this->redraft->run($save->refresh());
                    $this->shows->ensureWeek($save->refresh(), $save->current_week);

                    return;
                }

                $this->renewExpiring($save);
                $this->shows->ensureWeek($save, $week);

                if ($each !== null) {
                    $each($save, $week);
                }

                if ($this->resolver->airing($save, $week) === null || $this->resolver->awaitingWeekly($save)) {
                    $this->simulator->bookWeek($save, $week);
                }

                if ($each !== null) {
                    $each($save, $week);
                }

                $this->resolver->resolveWeek($save, $week);
            });

            $bar->setProgress(min($target - 1, $save->refresh()->current_week - 1));
        }

        if ($save->stage === SaveStage::Redrafting) {
            DB::transaction(fn () => $this->redraft->run($save->refresh()));
            $this->shows->ensureWeek($save->refresh(), $save->current_week);
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Put a free agent on a full WWE roster: the brand's least valuable
     * superstar goes back to Free Agency (an expired deal, so no release
     * fee) and the newcomer signs for 12 months.
     */
    private function signForBrand(Save $save, string $name, Brand $brand): void
    {
        $wrestler = SaveWrestler::with('wrestler')->where('save_id', $save->id)->whereHas('wrestler', fn ($q) => $q->where('name', $name))->firstOrFail();

        if ($wrestler->brand === $brand) {
            return;
        }

        $out = SaveWrestler::with(['wrestler', 'activeContract'])
            ->where('save_id', $save->id)
            ->where('brand', $brand)
            ->whereHas('wrestler', fn ($q) => $q->where('roster_type', RosterType::Superstar))
            ->orderBy('id')
            ->get()
            ->sortBy(fn (SaveWrestler $w): int => $this->judge->value($save, $brand->rival(), [(int) $w->id]))
            ->first();

        if ($out !== null) {
            $this->contracts->release($out, $save->current_week, ReignEndReason::ContractExpired);
        }

        $this->contracts->signDrafted($save->refresh(), $wrestler, $brand, $save->current_week, ContractLength::OneYear, checkBudget: false);
    }

    /**
     * Make sure he's one of his brand's Rumble entrants and book him to win.
     */
    private function bookRumbleWinner(Save $save, SaveWrestler $winner, int $week): void
    {
        $rumble = Show::with('ppvEvent')->where('save_id', $save->id)->where('week_number', $week)->get()
            ->first(fn (Show $s): bool => $s->ppvKey() === PpvKey::RoyalRumble && $s->isInterbrandMainEvent());

        if ($rumble === null) {
            return;
        }

        $winner->refresh();
        $brand = $winner->brand ?? throw new GameRuleException("{$winner->name()} has no brand at the Royal Rumble.");

        foreach (Brand::cases() as $side) {
            if (! $this->ppvCards->entrantsSet($rumble, $side)) {
                $rng = $save->rng("demo.entrants.{$side->value}", $week);
                $save->isHuman($side) ? $this->ppvCards->randomEntrants($rumble, $side, $rng) : $this->ppvCards->aiEntrants($rumble, $side, $rng);
            }
        }

        $slot = $this->ppvCards->mainEventSlot($rumble);
        $theirs = MatchParticipant::where('match_slot_id', $slot->id)
            ->whereHas('saveWrestler', fn ($q) => $q->where('brand', $brand))
            ->orderBy('side')
            ->pluck('save_wrestler_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        if (! in_array($winner->id, $theirs, true)) {
            array_pop($theirs);
            $this->ppvCards->setEntrants($rumble, $brand, [...$theirs, $winner->id]);
        }

        $side = MatchParticipant::where('match_slot_id', $slot->id)->where('save_wrestler_id', $winner->id)->value('side');
        $slot->update(['booked_side' => $side]);
    }

    private function heal(SaveWrestler $wrestler, int $week): void
    {
        Injury::where('save_wrestler_id', $wrestler->id)
            ->whereNull('healed_week')
            ->where('severity', InjurySeverity::Serious)
            ->where('ends_week', '>', $week)
            ->update(['healed_week' => $week]);
    }

    private function healWorldChampion(Save $save, Brand $brand, int $week): void
    {
        $championship = Championship::where('brand', $brand)->where('is_world', true)->first();
        $reign = $championship === null ? null : $this->titleService->currentReign($save, $championship);

        foreach (SaveWrestler::whereIn('id', $reign?->holderIds() ?? [])->get() as $holder) {
            $this->heal($holder, $week);
        }
    }

    private function wrestleManiaShow(Save $save, int $week): Show
    {
        return Show::with('ppvEvent')->where('save_id', $save->id)->where('week_number', $week)->whereNull('brand')->get()
            ->first(fn (Show $s): bool => $s->isWrestleMania())
            ?? throw new GameRuleException('WrestleMania week has no WrestleMania card.');
    }

    /**
     * The player's weekly shows must have drawn more fans this season than
     * the rival's (the immunity tag's crowd race): if they haven't, their
     * crowds are topped up, up to each show's maximum.
     */
    private function leadCrowdRace(Save $save, int $week): void
    {
        $totals = $this->crowds->totals($save, 1, $week);
        $gap = $totals[$save->rivalBrand()->value]['crowd'] - $totals[self::PLAYER->value]['crowd'] + 5_000;

        if ($gap <= 0) {
            return;
        }

        $max = (int) config('gm.attendance.ranges.weekly.1', 20_000);
        $shows = Show::where('save_id', $save->id)->where('season', 1)->where('brand', self::PLAYER)
            ->where('show_type', ShowType::Weekly)->where('week_number', '<', $week)->whereNotNull('attendance')
            ->orderBy('attendance')->get();

        foreach ($shows as $show) {
            if ($gap <= 0) {
                break;
            }

            $add = min($gap, $max - (int) $show->attendance, (int) ceil($gap / max(1, $shows->count())) + 500);

            if ($add > 0) {
                $show->update(['attendance' => (int) $show->attendance + $add]);
                $gap -= $add;
            }
        }
    }

    /**
     * Re-sign the player's superstars with 4 or fewer weeks left: 12 months
     * when the money's there, else the longest deal that fits.
     */
    private function renewExpiring(Save $save): void
    {
        $expiring = Contract::with('saveWrestler.wrestler')
            ->where('save_id', $save->id)
            ->where('brand', $save->player_brand)
            ->where('status', ContractStatus::Active)
            ->where('weeks_remaining', '<=', (int) config('gm.contracts.expiring_threshold_weeks'))
            ->orderBy('id')
            ->get();

        foreach ($expiring as $contract) {
            $wrestler = $contract->saveWrestler;

            foreach (array_reverse(ContractLength::cases()) as $length) {
                if ($save->brand($save->player_brand)->budget >= $this->pricing->quote($wrestler->wrestler, $length)['total']) {
                    $this->contracts->renegotiate($wrestler, $length, $save->current_week);

                    break;
                }
            }
        }
    }
}
