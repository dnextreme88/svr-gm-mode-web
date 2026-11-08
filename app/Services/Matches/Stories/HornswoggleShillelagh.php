<?php

namespace App\Services\Matches\Stories;

use App\Enums\ConditionalStory;
use App\Enums\ConditionalStoryOutcome;
use App\Services\Matches\ResolvedMatch;

/**
 * Hornswoggle's shillelagh (house rule, 2026-11-07), story only: when Finlay
 * wins a simulated match decisively, 50% of the time Hornswoggle slipped him
 * his shillelagh. The result never changes. Locked out for 8 weeks after.
 */
final class HornswoggleShillelagh extends StoryRule
{
    public function story(): ConditionalStory
    {
        return ConditionalStory::HornswoggleShillelagh;
    }

    public function after(StoryMatch $match, ResolvedMatch $resolved, ?StoryPlan $plan): ?StoryPlan
    {
        $save = $match->save();
        $finlay = $this->wrestler($save, (string) config('gm.stories.shillelagh.finlay'));

        if ($finlay === null || ! $resolved->isDecisive() || ! in_array($finlay->id, $resolved->winners, true)) {
            return null;
        }

        $helper = $this->wrestler($save, (string) config('gm.stories.shillelagh.helper'));

        if (! $this->available($helper, $finlay->brand, $match)
            || $this->coolingDown($this->lastTrigger($save), (int) config('gm.stories.shillelagh.lockout_weeks'), $match->week())
            || ! $match->rng('shillelagh')->chance((float) config('gm.stories.shillelagh.chance'))) {
            return null;
        }

        $losers = $match->sides()[$resolved->loserSide] ?? $resolved->losers;

        return new StoryPlan($this->story(), ConditionalStoryOutcome::Success, data: [
            'finlay' => $finlay->id,
            'victim' => (int) ($losers[0] ?? $resolved->losers[0]),
        ]);
    }

    public function text(StoryMatch $match, ResolvedMatch $resolved, StoryPlan $plan): StoryText
    {
        $finlay = $match->name((int) $plan->data['finlay']);
        $key = $this->key($plan);
        $next = $match->week() + (int) config('gm.stories.shillelagh.lockout_weeks');

        return new StoryText(
            ConditionalStoryTexts::title($key),
            ConditionalStoryTexts::render($key, ['finlay' => $finlay, 'victim' => $match->name((int) $plan->data['victim'])]),
            "Nothing about the result: {$finlay} won on his own. The news credits Hornswoggle's help.",
            'Next chance',
            "{$this->weekLabel($next, $match->week())}. Hornswoggle's help can't happen again for ".config('gm.stories.shillelagh.lockout_weeks').' weeks.',
        );
    }
}
