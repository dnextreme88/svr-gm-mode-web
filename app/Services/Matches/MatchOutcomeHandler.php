<?php

namespace App\Services\Matches;

use App\Models\MatchResult;
use App\Models\MatchSlot;
use App\Services\Week\WeekContext;

/**
 * What a result means beyond the win/loss: titles and stipulations
 * (§16, §20, §22). Filled in with the title rules in phase 4.
 */
class MatchOutcomeHandler
{
    public function apply(MatchSlot $slot, MatchResult $result, ResolvedMatch $resolved, WeekContext $context): void
    {
        //
    }
}
