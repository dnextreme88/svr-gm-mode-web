<?php

namespace App\Services\Week\Steps;

use App\Services\Titles\ContendershipService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * House rule: the rival answers its own vacancy prompts (a title vacated
 * with a #1 contender waiting). The player's wait for the player.
 */
class SettleTitleVacancies implements WeekStep
{
    public function __construct(private ContendershipService $contenderships) {}

    public function handle(WeekContext $context): void
    {
        $this->contenderships->settleRival($context->save, $context->week);
    }
}
