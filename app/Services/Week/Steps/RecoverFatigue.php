<?php

namespace App\Services\Week\Steps;

use App\Models\SaveWrestler;
use App\Services\Fatigue\FatigueZones;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Wrestlers who didn't compete this week rest and recover fatigue (§6).
 * ASSUMPTION: the recovery amount is TBD in the spec; it lives in config.
 */
class RecoverFatigue implements WeekStep
{
    public function handle(WeekContext $context): void
    {
        $competed = array_keys($context->data['competed'] ?? []);
        $recovery = (int) config('gm.fatigue.rest_recovery_bars') * (int) config('gm.fatigue.bar_size');

        SaveWrestler::where('save_id', $context->save->id)
            ->whereNotIn('id', $competed)
            ->where('fatigue', '>', 0)
            ->get()
            ->each(fn (SaveWrestler $w) => $w->update(['fatigue' => FatigueZones::cap($w->fatigue - $recovery)]));
    }
}
