<?php

namespace App\Services\Week\Steps;

use App\Models\SaveWrestler;
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
        // Fatigue going into the week, for the results screen's fatigue log.
        $context->data['fatigue_start'] = SaveWrestler::where('save_id', $context->save->id)
            ->pluck('fatigue', 'id')
            ->map(fn ($fatigue): int => (int) $fatigue)
            ->all();

        $shows = Show::where('save_id', $context->save->id)
            ->where('week_number', $context->week)
            ->get()
            // Weeks start on Sunday: the PPV cards air first, the interbrand
            // main event closes the PPV night, then the weekly shows.
            ->sortBy(fn (Show $s): string => ($s->isInterbrand() ? '1' : ($s->isPpv() ? '0' : '2')).($s->brand->value ?? 'z'))
            ->values();

        foreach ($shows as $show) {
            $this->resolver->air($show, $context);
        }
    }
}
