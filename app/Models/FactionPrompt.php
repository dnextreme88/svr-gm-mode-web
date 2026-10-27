<?php

namespace App\Models;

use App\Enums\FactionPromptStatus;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of the player's teams that won together often enough to become a
 * faction (house rule). `member_key` is the sorted member ids, so a team
 * has one row per save.
 *
 * @property int $id
 * @property string $member_key
 * @property list<int> $member_ids
 * @property int $wins
 * @property int $first_win_week
 * @property int $week_number
 * @property int|null $show_id
 * @property FactionPromptStatus $status
 * @property int|null $deferred_at_wins
 * @property-read Show|null $show
 */
#[Unguarded]
class FactionPrompt extends Model
{
    use BelongsToGame;

    /**
     * @return BelongsTo<Show, $this>
     */
    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }

    /**
     * @param  list<int>  $ids
     */
    public static function keyFor(array $ids): string
    {
        sort($ids);

        return implode('-', $ids);
    }

    protected function casts(): array
    {
        return [
            'member_ids' => 'array',
            'status' => FactionPromptStatus::class,
        ];
    }
}
