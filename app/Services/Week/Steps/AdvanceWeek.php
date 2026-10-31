<?php

namespace App\Services\Week\Steps;

use App\Enums\PpvKey;
use App\Services\Ai\AiGm;
use App\Services\Calendar\CalendarService;
use App\Services\Week\ShowScheduler;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Moves the save to the next week and sets up its cards. The rival books
 * its half of a shared SummerSlam card straight away, so the player sees
 * those matches while booking theirs (house rule, 2026-11-01).
 */
class AdvanceWeek implements WeekStep
{
    public function __construct(
        private ShowScheduler $shows,
        private CalendarService $calendar,
        private AiGm $ai,
    ) {}

    public function handle(WeekContext $context): void
    {
        $next = $context->week + 1;

        $context->save->update([
            'current_week' => $next,
            'current_season' => $this->calendar->seasonOf($next),
        ]);

        foreach ($this->shows->ensureWeek($context->save, $next) as $show) {
            if ($show->ppvKey() === PpvKey::Summerslam) {
                $this->ai->bookShow($show, $context->save->rivalBrand());
            }
        }
    }
}
