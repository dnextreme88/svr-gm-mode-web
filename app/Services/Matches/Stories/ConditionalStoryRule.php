<?php

namespace App\Services\Matches\Stories;

use App\Enums\ConditionalStory;
use App\Services\Matches\ResolvedMatch;

/**
 * One conditional match story (house rule, 2026-11-07).
 */
interface ConditionalStoryRule
{
    public function story(): ConditionalStory;

    /**
     * Before the match: whether the story happens here, and the result it
     * forces. Null when it doesn't (or, for a story-only one, always).
     */
    public function before(StoryMatch $match): ?StoryPlan;

    /**
     * After the match: the plan to record (a story-only one decides here),
     * or null when nothing happened.
     */
    public function after(StoryMatch $match, ResolvedMatch $resolved, ?StoryPlan $plan): ?StoryPlan;

    /**
     * The story's words, for a plan that played out.
     */
    public function text(StoryMatch $match, ResolvedMatch $resolved, StoryPlan $plan): StoryText;
}
