<?php

namespace App\Models;

use App\Enums\FinishEvent;
use App\Enums\MatchMethod;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bout of a Gauntlet (house rule): the last winner (`side_a`) against
 * the next entrant (`side_b`).
 *
 * @property int $id
 * @property int $match_slot_id
 * @property int $round
 * @property int $side_a
 * @property int $side_b
 * @property int $winner_side
 * @property MatchMethod $method
 * @property FinishEvent|null $finish_event
 * @property int|null $interferer_id
 * @property bool $booked
 * @property string $summary
 * @property-read MatchSlot $matchSlot
 */
#[Unguarded]
class GauntletBout extends Model
{
    /**
     * @return BelongsTo<MatchSlot, $this>
     */
    public function matchSlot(): BelongsTo
    {
        return $this->belongsTo(MatchSlot::class);
    }

    protected function casts(): array
    {
        return [
            'method' => MatchMethod::class,
            'finish_event' => FinishEvent::class,
            'booked' => 'boolean',
        ];
    }
}
