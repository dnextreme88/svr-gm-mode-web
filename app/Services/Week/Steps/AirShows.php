<?php

namespace App\Services\Week\Steps;

use App\Enums\ShowStatus;
use App\Models\Show;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Airs the weekly show, then the PPV (§2: the tick runs after both air).
 */
class AirShows implements WeekStep
{
    public function handle(WeekContext $context): void
    {
        Show::where('save_id', $context->save->id)
            ->where('week_number', $context->week)
            ->update(['status' => ShowStatus::Aired]);
    }
}
