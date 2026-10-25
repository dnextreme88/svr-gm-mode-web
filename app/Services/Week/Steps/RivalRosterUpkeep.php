<?php

namespace App\Services\Week\Steps;

use App\Services\Ai\AiRosterManager;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * The rival GM renews expiring contracts and signs free agents (house rule).
 * Signings take effect next week, as the player's do.
 */
class RivalRosterUpkeep implements WeekStep
{
    public function __construct(private AiRosterManager $roster) {}

    public function handle(WeekContext $context): void
    {
        $this->roster->upkeep($context->save, $context->save->rivalBrand(), $context->week);
    }
}
