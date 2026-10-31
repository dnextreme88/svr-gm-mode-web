<?php

namespace App\Services\Week;

use App\Enums\SaveStage;
use App\Exceptions\GameRuleException;
use App\Models\PromoType;
use App\Models\Save;
use App\Models\SaveBrand;
use App\Models\Show;
use App\Services\Ai\AiGm;
use App\Services\Calendar\CalendarService;
use App\Services\Card\CardService;
use App\Services\Ppv\PpvCardService;
use App\Services\Promos\PromoEligibility;
use App\Services\Promos\PromoService;
use App\Services\Week\Steps\TrackDeficit;

/**
 * Simulate to a date: the computer books the player's cards for every week
 * up to the target (Preset rules, random promos, random Rumble entrants) and
 * airs them through the normal week resolution. Everything happens as if the
 * player had booked it: results, fatigue, injuries, title changes, contracts,
 * morale, news and email. The target week is left for the player to book.
 */
class WeekSimulator
{
    public function __construct(
        private WeekResolver $resolver,
        private ShowScheduler $shows,
        private BookingOrder $order,
        private CardService $cards,
        private AiGm $ai,
        private PpvCardService $ppvCards,
        private PromoEligibility $eligibility,
        private PromoService $promos,
        private CalendarService $calendar,
    ) {}

    /**
     * Throws if a simulation to `$target` can't run.
     */
    public function assertCanSimulateTo(Save $save, int $target): void
    {
        if ($save->stage !== SaveStage::InSeason) {
            throw new GameRuleException('You can only simulate during the season.');
        }

        if ($target <= $save->current_week) {
            throw new GameRuleException('Pick a date after this week.');
        }

        $seasonEnd = $this->calendar->absoluteWeek($save->current_season, (int) config('gm.calendar.weeks_per_season'));

        if ($target > $seasonEnd) {
            throw new GameRuleException('You can only simulate up to WrestleMania.');
        }
    }

    /**
     * Air every week before `$target`. Stops early (and says why) if a week
     * can't air, e.g. because nobody on the roster can be booked.
     *
     * @return array{weeks: list<int>, stopped: string|null}
     */
    public function simulateTo(Save $save, int $target): array
    {
        $this->assertCanSimulateTo($save, $target);
        $aired = [];

        while (true) {
            $step = $this->simulateNext($save, $target);

            if ($step['week'] !== null) {
                $aired[] = $step['week'];
            }

            if ($step['stopped'] !== null) {
                return ['weeks' => $aired, 'stopped' => $step['stopped']];
            }

            if ($step['done']) {
                return ['weeks' => $aired, 'stopped' => null];
            }
        }
    }

    /**
     * Air the next week on the way to `$target`: one step of Simulate to
     * here, so the schedule can show progress week by week. `done` once the
     * save reaches `$target` (or leaves the season).
     *
     * @return array{week: int|null, done: bool, stopped: string|null}
     */
    public function simulateNext(Save $save, int $target): array
    {
        if ($save->refresh()->current_week >= $target || $save->stage !== SaveStage::InSeason) {
            return ['week' => null, 'done' => true, 'stopped' => null];
        }

        $week = $save->current_week;

        try {
            // A week already airing live just finishes, as booked.
            if ($this->resolver->airing($save, $week) === null) {
                $this->bookWeek($save, $week);
            }

            $this->resolver->resolveWeek($save, $week);
        } catch (GameRuleException $e) {
            return ['week' => null, 'done' => true, 'stopped' => $e->getMessage()];
        }

        $save->refresh();

        return [
            'week' => $week,
            'done' => $save->current_week >= $target || $save->stage !== SaveStage::InSeason,
            'stopped' => null,
        ];
    }

    /**
     * Book every one of the player's cards this week, as the rival GM would.
     */
    public function bookWeek(Save $save, int $week): void
    {
        $brand = $save->player_brand;
        $this->shows->ensureWeek($save, $week);

        // A long deficit needs a recovery plan before the week airs; the
        // computer picks the one the rival GM uses.
        SaveBrand::where('save_id', $save->id)
            ->where('brand', $brand)
            ->where('deficit_weeks', '>=', (int) config('gm.finances.deficit_weeks_limit'))
            ->whereNull('deficit_choice')
            ->update(['deficit_choice' => TrackDeficit::CHOICE_CHEAP_MATCHES]);

        foreach ($this->order->steps($save, $week) as $show) {
            $this->cards->ensureSlots($show);

            if ($show->isInterbrandMainEvent()) {
                if (! $this->ppvCards->entrantsSet($show, $brand)) {
                    try {
                        $this->ppvCards->randomEntrants($show, $brand, $save->rng("sim.entrants.{$show->streamKey()}", $week));
                    } catch (GameRuleException) {
                        // Nobody bookable: the brand sends no one.
                    }
                }

                continue;
            }

            $this->bookRandomPromos($save, $show);
            $this->ai->bookShow($show, $brand);
        }

        $this->order->confirmAll($save, $week);
    }

    /**
     * Fill empty promo slots with random promos the roster can actually run.
     */
    private function bookRandomPromos(Save $save, Show $show): void
    {
        $brand = $save->player_brand;
        $rng = $save->rng("sim.promos.{$show->streamKey()}", $show->week_number);
        $types = PromoType::orderBy('sort_order')->get()->keyBy(fn (PromoType $t): string => $t->key->value);

        for ($position = 1; $position <= $show->promoSlotCount(); $position++) {
            if ($show->promoSlots()->where('position', $position)->whereNotNull('promo_type_id')->exists()) {
                continue;
            }

            $available = array_filter($this->eligibility->forShow($show, $brand), fn (array $e): bool => $e['available']);

            if ($available === []) {
                return;
            }

            $key = (string) $rng->pick(array_keys($available));
            $eligibility = $available[$key];
            $type = $types[$key];
            $pair = $eligibility['pairs'] === [] ? null : $rng->pick($eligibility['pairs']);

            try {
                $this->promos->book(
                    $show,
                    $position,
                    $type,
                    $pair[0] ?? ($eligibility['wrestler_ids'] === [] ? null : $rng->pick($eligibility['wrestler_ids'])),
                    $pair[1] ?? null,
                    $eligibility['championship_ids'] === [] ? null : $rng->pick($eligibility['championship_ids']),
                );
            } catch (GameRuleException) {
                continue;
            }
        }
    }
}
