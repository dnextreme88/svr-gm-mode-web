<?php

namespace App\Services\Week\Steps;

use App\Enums\Brand;
use App\Enums\PpvKey;
use App\Enums\ShowType;
use App\Exceptions\GameRuleException;
use App\Models\Show;
use App\Services\Ai\AiGm;
use App\Services\Card\CardService;
use App\Services\Ppv\PpvCardService;
use App\Services\Ppv\QualifierService;
use App\Services\Week\ShowScheduler;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekResolver;
use App\Services\Week\WeekStep;

/**
 * Makes sure this week's cards exist and the AI brand has booked its share:
 * its own cards, its WrestleMania slots and its PPV entrants.
 */
class PrepareShows implements WeekStep
{
    public function __construct(
        private ShowScheduler $shows,
        private CardService $cards,
        private PpvCardService $ppvCards,
        private AiGm $ai,
        private QualifierService $qualifiers,
    ) {}

    public function handle(WeekContext $context): void
    {
        $shows = $this->shows->ensureWeek($context->save, $context->week);
        $aiBrand = $context->save->rivalBrand();
        $entrantCards = [];

        foreach ($shows as $show) {
            // House rule (2026-11-17): a weekly show's Qualifying Match.
            $this->qualifiers->setUp($show);
            $this->cards->ensureSlots($show);

            // Entrants come last, once the rival's other cards of the night
            // are booked (its Royal Rumble picks avoid booked champions).
            if ($show->isInterbrandMainEvent()) {
                // House rule (2026-11-17): Survivor Series qualifiers take their spots.
                $this->ppvCards->seatSurvivorQualifiers($show);
                // The rest of the shared card is the rival's to book too.
                $this->ai->bookShow($show, $aiBrand);
                $entrantCards[] = $show;

                continue;
            }

            // A shared card (SummerSlam): the rival's own slots, if it
            // hasn't booked them when the week opened.
            if ($show->isInterbrand() && $show->ppvKey() === PpvKey::Summerslam) {
                $this->ai->bookShow($show, $aiBrand);

                continue;
            }

            if ($show->isWrestleMania()) {
                $this->ppvCards->refreshSeries($show, $context->rng('wm.series.refresh'));
                $this->ai->bookShow($show, $aiBrand);

                continue;
            }

            // In a PPV week the rival books its weekly show after the PPV
            // airs (`WeekResolver::continueWeek`).
            if ($show->brand === $aiBrand && ($show->isPpv() || ($context->data['phase'] ?? null) !== WeekResolver::PHASE_PPV)) {
                $this->ai->bookShow($show);
            }

            // A qualifier still empty as its show airs (Simulate to here,
            // tests): filled with healthy entrants. Not before a PPV night,
            // when the weekly show isn't booked yet.
            if ($show->show_type === ShowType::Weekly && ($context->data['phase'] ?? null) !== WeekResolver::PHASE_PPV) {
                $this->qualifiers->fillMissing($show, $context->rng("qualifier.fill.{$show->streamKey()}"));
            }
        }

        foreach ($entrantCards as $show) {
            $this->entrants($show, $aiBrand, $context);
        }

        $context->data['shows'] = $shows->map(fn (Show $s): int => $s->id)->all();
    }

    private function entrants(Show $show, Brand $brand, WeekContext $context): void
    {
        if ($this->ppvCards->entrantsSet($show, $brand)) {
            return;
        }

        try {
            $this->ppvCards->aiEntrants($show, $brand, $context->rng("ai.entrants.{$show->streamKey()}"));
        } catch (GameRuleException) {
            // A brand with nobody bookable simply sends no one.
        }
    }
}
