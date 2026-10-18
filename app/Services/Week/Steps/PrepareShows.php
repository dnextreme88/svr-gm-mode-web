<?php

namespace App\Services\Week\Steps;

use App\Models\Show;
use App\Services\Ai\AiGm;
use App\Services\Card\CardService;
use App\Services\Week\ShowScheduler;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Makes sure this week's cards exist and the AI brand has booked its own.
 */
class PrepareShows implements WeekStep
{
    public function __construct(
        private ShowScheduler $shows,
        private CardService $cards,
        private AiGm $ai,
    ) {}

    public function handle(WeekContext $context): void
    {
        $shows = $this->shows->ensureWeek($context->save, $context->week);

        foreach ($shows as $show) {
            $this->cards->ensureSlots($show);

            if ($show->brand !== null && ! $context->save->isHuman($show->brand)) {
                $this->ai->bookShow($show);
            }
        }

        $context->data['shows'] = $shows->map(fn (Show $s): int => $s->id)->all();
    }
}
