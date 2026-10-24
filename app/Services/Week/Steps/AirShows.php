<?php

namespace App\Services\Week\Steps;

use App\Enums\ShowStatus;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Matches\ShowResolver;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;
use Illuminate\Support\Collection;

/**
 * Airs the weekly shows, then the PPVs, resolving each match in card order (§20).
 *
 * Run as a step, it airs everything at once (tests, Simulate to here). Live
 * results (`WeekResolver::begin()`) use the helpers instead and stop at each
 * of the player's shows, so the player can decide its matches.
 */
class AirShows implements WeekStep
{
    public function __construct(private ShowResolver $resolver) {}

    public function handle(WeekContext $context): void
    {
        $this->start($context);

        foreach ($this->ordered($context) as $show) {
            $this->resolver->air($show, $context);
        }
    }

    /**
     * Fatigue going into the week, for the results screen's fatigue log.
     */
    public function start(WeekContext $context): void
    {
        $context->data['fatigue_start'] = SaveWrestler::where('save_id', $context->save->id)
            ->pluck('fatigue', 'id')
            ->map(fn ($fatigue): int => (int) $fatigue)
            ->all();
    }

    /**
     * The week's shows in air order.
     *
     * @return Collection<int, Show>
     */
    public function ordered(WeekContext $context): Collection
    {
        return Show::where('save_id', $context->save->id)
            ->where('week_number', $context->week)
            ->get()
            // Weeks start on Sunday: the PPV cards air first, the interbrand
            // main event closes the PPV night, then the weekly shows.
            ->sortBy(fn (Show $s): string => ($s->isInterbrand() ? '1' : ($s->isPpv() ? '0' : '2')).($s->brand->value ?? 'z'))
            ->values()
            ->toBase();
    }

    /**
     * Shows the player decides on the results screen: their own brand's and
     * the interbrand cards. The rival GM's own shows always simulate.
     */
    public function isLive(Show $show, Save $save): bool
    {
        return $show->brand === null || $show->brand === $save->player_brand;
    }

    /**
     * The player's first show still airing, if any.
     */
    public function current(WeekContext $context): ?Show
    {
        return $this->ordered($context)->first(fn (Show $s): bool => $s->status !== ShowStatus::Aired && $this->isLive($s, $context->save));
    }
}
