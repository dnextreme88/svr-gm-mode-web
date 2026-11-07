<?php

namespace App\Services\Matches\Stories;

use App\Enums\Brand;
use App\Enums\ConditionalStory;
use App\Enums\ConditionalStoryOutcome;
use App\Models\ConditionalStoryTrigger;
use App\Models\Save;
use App\Models\SaveFaction;
use App\Models\SaveWrestler;
use App\Services\Calendar\CalendarService;
use App\Services\Injuries\InjuryStatus;
use App\Services\Matches\ResolvedMatch;
use App\Support\Rng\Rng;

/**
 * What the conditional stories share: finding a wrestler by catalog slug,
 * whether a helper can take part, cooldowns, and the referee line.
 */
abstract class StoryRule implements ConditionalStoryRule
{
    public function __construct(
        protected InjuryStatus $injuries,
        protected CalendarService $calendar,
    ) {}

    public function before(StoryMatch $match): ?StoryPlan
    {
        return null;
    }

    public function after(StoryMatch $match, ResolvedMatch $resolved, ?StoryPlan $plan): ?StoryPlan
    {
        return $plan;
    }

    protected function wrestler(Save $save, string $slug): ?SaveWrestler
    {
        return SaveWrestler::with('wrestler')
            ->where('save_id', $save->id)
            ->whereHas('wrestler', fn ($q) => $q->where('slug', $slug))
            ->first();
    }

    /**
     * A helper can take part: on the brand, not seriously injured or sitting
     * out, and not in this match. They may be booked elsewhere on the card.
     */
    protected function available(?SaveWrestler $helper, ?Brand $brand, StoryMatch $match): bool
    {
        return $helper !== null
            && $helper->brand !== null
            && $helper->brand === $brand
            && ! $this->injuries->isSeriouslyInjured($helper, $match->week())
            && ! $helper->isSittingOut($match->week())
            && ! in_array($helper->id, $match->participantIds(), true);
    }

    /**
     * The stories that change results stay out of these families.
     */
    protected function excluded(StoryMatch $match): bool
    {
        $family = $match->family();

        return $family === null || in_array($family->value, (array) config('gm.stories.excluded_families'), true);
    }

    protected function factionActive(Save $save, string $name, int $week): ?SaveFaction
    {
        $faction = SaveFaction::where('save_id', $save->id)->where('faction_name', $name)->first();

        return $faction !== null && $faction->isActiveAt($week) ? $faction : null;
    }

    /**
     * The latest time this story played out, optionally only successes, or
     * only within one rivalry.
     */
    protected function lastTrigger(Save $save, ?ConditionalStoryOutcome $outcome = null, ?int $rivalryId = null): ?ConditionalStoryTrigger
    {
        return ConditionalStoryTrigger::where('save_id', $save->id)
            ->where('story', $this->story())
            ->when($outcome !== null, fn ($q) => $q->where('outcome', $outcome))
            ->when($rivalryId !== null, fn ($q) => $q->where('rivalry_id', $rivalryId))
            ->latest('week_number')
            ->first();
    }

    /**
     * Whether a cooldown that started at a trigger is still running: a
     * trigger in week 4 with 8 weeks locks out weeks 4–11.
     */
    protected function coolingDown(?ConditionalStoryTrigger $trigger, int $weeks, int $week): bool
    {
        return $trigger !== null && $week < $trigger->week_number + $weeks;
    }

    /**
     * A story that decides a match never causes a DQ (house rule): in a
     * type with DQs the referee was down or distracted, 50/50. In a type
     * without DQs, `$noDq` (often nothing).
     */
    protected function refereeOpening(StoryMatch $match, Rng $rng, string $distractedBy, string $noDq = ''): string
    {
        if (! $match->hasDqs()) {
            return $noDq;
        }

        return $rng->chance(0.5)
            ? 'With the referee down in the corner,'
            : "With the referee distracted by {$distractedBy},";
    }

    /**
     * A season week label, "Week 14" (or "Season 2, Week 3" in a later season).
     */
    protected function weekLabel(int $week, int $from): string
    {
        $season = $this->calendar->seasonOf($week);

        return $season === $this->calendar->seasonOf($from)
            ? 'Week '.$this->calendar->seasonWeek($week)
            : "Season {$season}, Week {$this->calendar->seasonWeek($week)}";
    }

    protected function key(StoryPlan $plan, string $suffix = ''): string
    {
        return "{$plan->story->value}.{$plan->outcome->value}{$suffix}";
    }

    abstract public function story(): ConditionalStory;
}
