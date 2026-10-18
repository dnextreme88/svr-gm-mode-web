<?php

namespace App\Services\Week\Steps;

use App\Enums\Brand;
use App\Exceptions\GameRuleException;
use App\Models\Show;
use App\Services\Ai\AiGm;
use App\Services\Card\CardService;
use App\Services\Ppv\PpvCardService;
use App\Services\Week\ShowScheduler;
use App\Services\Week\WeekContext;
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
    ) {}

    public function handle(WeekContext $context): void
    {
        $shows = $this->shows->ensureWeek($context->save, $context->week);
        $aiBrand = $context->save->rivalBrand();

        foreach ($shows as $show) {
            $this->cards->ensureSlots($show);

            if ($show->isInterbrandMainEvent()) {
                $this->entrants($show, $aiBrand, $context);

                continue;
            }

            if ($show->isWrestleMania()) {
                $this->ppvCards->refreshSeries($show, $context->rng('wm.series.refresh'));
                $this->ai->bookShow($show, $aiBrand);

                continue;
            }

            if ($show->brand === $aiBrand) {
                $this->ai->bookShow($show);
            }
        }

        $context->data['shows'] = $shows->map(fn (Show $s): int => $s->id)->all();
    }

    private function entrants(Show $show, Brand $brand, WeekContext $context): void
    {
        if ($this->ppvCards->entrantsSet($show, $brand)) {
            return;
        }

        try {
            $this->ppvCards->randomEntrants($show, $brand, $context->rng("ai.entrants.{$show->streamKey()}"));
        } catch (GameRuleException) {
            // A brand with nobody bookable simply sends no one.
        }
    }
}
