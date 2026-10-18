<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A GM Mode year, ending at WrestleMania (§11, §13).
 *
 * @property int $id
 * @property int $number
 * @property int $started_week
 * @property int|null $ended_week
 * @property int|null $starting_budget
 * @property int|null $royal_rumble_winner_id
 * @property-read SaveWrestler|null $royalRumbleWinner
 */
#[Unguarded]
class Season extends Model
{
    use BelongsToGame;

    /**
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function royalRumbleWinner(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class, 'royal_rumble_winner_id');
    }
}
