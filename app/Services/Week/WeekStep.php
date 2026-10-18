<?php

namespace App\Services\Week;

/**
 * One stage of week resolution. Steps run in order inside a single
 * transaction (§2), so any failure rolls the whole week back.
 */
interface WeekStep
{
    public function handle(WeekContext $context): void;
}
