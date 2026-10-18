<?php

namespace App\Services\Week\Steps;

use App\Services\Calendar\CalendarService;
use App\Services\Week\ShowScheduler;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Moves the save to the next week and sets up its cards.
 */
class AdvanceWeek implements WeekStep
{
    public function __construct(
        private ShowScheduler $shows,
        private CalendarService $calendar,
    ) {}

    public function handle(WeekContext $context): void
    {
        $next = $context->week + 1;

        $context->save->update([
            'current_week' => $next,
            'current_season' => $this->calendar->seasonOf($next),
        ]);

        $this->shows->ensureWeek($context->save, $next);
    }
}
