<?php

namespace App\Services\Week;

use App\Enums\SaveStage;
use App\Exceptions\GameRuleException;
use App\Models\Save;
use App\Models\WeekResolution;
use App\Services\Calendar\CalendarService;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a week (§2): runs every step in one DB transaction and records a
 * WeekResolution so the same week can never resolve twice.
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
        Steps\AirShows::class,
        Steps\RecoverFatigue::class,
        Steps\InjuryReturns::class,
        Steps\TickContracts::class,
        Steps\UpdateMorale::class,
        Steps\ApplyPendingMoves::class,
        Steps\TitleUpkeep::class,
        Steps\TrackDeficit::class,
        Steps\SendContractEmails::class,
        Steps\PpvBuzz::class,
        Steps\WrestleManiaWrapUp::class,
        Steps\AdvanceWeek::class,
    ];

    /**
     * @var list<class-string<WeekGuard>>
     */
    public const GUARDS = [
        Guards\DeficitChoiceGuard::class,
        Guards\TitleShotGuard::class,
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
     * existing record without running anything again.
     */
    public function resolveWeek(Save $save, int $week): WeekResolution
    {
        $existing = WeekResolution::where('save_id', $save->id)->where('week_number', $week)->first();

        if ($existing !== null) {
            return $existing;
        }

        foreach (self::GUARDS as $guard) {
            app($guard)->check($save, $week);
        }

        return DB::transaction(function () use ($save, $week): WeekResolution {
            /** @var Save $locked */
            $locked = Save::whereKey($save->id)->lockForUpdate()->firstOrFail();

            $existing = WeekResolution::where('save_id', $locked->id)->where('week_number', $week)->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($locked->stage !== SaveStage::InSeason) {
                throw new GameRuleException('The season has not started yet.');
            }

            if ($week !== $locked->current_week) {
                throw new GameRuleException("Week {$week} is not the current week.");
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
     * @return list<WeekStep>
     */
    private function steps(): array
    {
        return $this->steps ?? array_map(fn (string $class): WeekStep => app($class), self::STEPS);
    }
}
