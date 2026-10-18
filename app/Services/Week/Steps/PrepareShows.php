<?php

namespace App\Services\Week\Steps;

use App\Services\Week\ShowScheduler;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Makes sure this week's cards exist before they air.
 */
class PrepareShows implements WeekStep
{
    public function __construct(private ShowScheduler $shows) {}

    public function handle(WeekContext $context): void
    {
        $context->data['shows'] = $this->shows->ensureWeek($context->save, $context->week);
    }
}
