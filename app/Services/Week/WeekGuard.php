<?php

namespace App\Services\Week;

use App\Models\Save;

/**
 * A check that must pass before a week can air (e.g. an unbooked title shot
 * or an unresolved deficit). Throws GameRuleException when it fails.
 */
interface WeekGuard
{
    public function check(Save $save, int $week): void;
}
