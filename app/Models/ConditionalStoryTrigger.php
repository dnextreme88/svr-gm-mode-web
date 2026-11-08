<?php

namespace App\Models;

use App\Enums\ConditionalStory;
use App\Enums\ConditionalStoryOutcome;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One conditional match story that played out (house rule, 2026-11-07).
 *
 * `tokens` holds what the pop-up shows, frozen when it happened: `title`,
 * `text` (also added to the match's news), `what_changed` and `next`.
 *
 * @property int $id
 * @property ConditionalStory $story
 * @property ConditionalStoryOutcome $outcome
 * @property int $season
 * @property int $week_number
 * @property int $match_slot_id
 * @property int|null $rivalry_id
 * @property int|null $save_faction_id
 * @property array<string, string> $tokens
 * @property Carbon|null $seen_at
 * @property-read MatchSlot $slot
 */
#[Unguarded]
class ConditionalStoryTrigger extends Model
{
    use BelongsToGame;

    /**
     * @return BelongsTo<MatchSlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(MatchSlot::class, 'match_slot_id');
    }

    protected function casts(): array
    {
        return [
            'story' => ConditionalStory::class,
            'outcome' => ConditionalStoryOutcome::class,
            'tokens' => 'array',
            'seen_at' => 'datetime',
        ];
    }
}
