<?php

namespace App\Services\Week\Steps;

use App\Models\Show;
use App\Services\Matches\ShowResolver;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Airs the weekly shows, then the PPVs, resolving each match in card order (§20).
 */
class AirShows implements WeekStep
{
    public function __construct(private ShowResolver $resolver) {}

    public function handle(WeekContext $context): void
    {
        $shows = Show::where('save_id', $context->save->id)
            ->where('week_number', $context->week)
            ->get()
            ->sortBy(fn (Show $s): string => ($s->isInterbrand() ? '2' : ($s->isPpv() ? '1' : '0')).($s->brand->value ?? 'z'))
            ->values();

        foreach ($shows as $show) {
            $this->resolver->air($show, $context);
        }
    }
}
