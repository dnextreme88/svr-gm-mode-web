<?php

namespace App\Services\Matches\Stories;

use App\Enums\ConditionalStory;
use App\Enums\ConditionalStoryOutcome;
use App\Services\Matches\ForcedOutcome;

/**
 * A conditional story that's going to happen in this match (house rule,
 * 2026-11-07), decided before the match: whether its spot works, the result
 * it forces (if any), and what its text needs.
 */
final readonly class StoryPlan
{
    /**
     * @param  array<string, mixed>  $data  ids and picks for the text (helper, rival, move…)
     */
    public function __construct(
        public ConditionalStory $story,
        public ConditionalStoryOutcome $outcome,
        public ?ForcedOutcome $forced = null,
        public array $data = [],
        public ?int $rivalryId = null,
        public ?int $factionId = null,
    ) {}
}
