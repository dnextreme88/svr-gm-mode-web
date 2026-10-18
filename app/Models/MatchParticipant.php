<?php

namespace App\Models;

use App\Enums\ParticipantRole;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A competitor or manager in a match (§4).
 *
 * @property int $id
 * @property int $match_slot_id
 * @property int $save_wrestler_id
 * @property int $side
 * @property ParticipantRole $role
 * @property string|null $result
 * @property int|null $fatigue_before
 * @property-read SaveWrestler $saveWrestler
 * @property-read MatchSlot $matchSlot
 */
#[Unguarded]
class MatchParticipant extends Model
{
    /**
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function saveWrestler(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class);
    }

    /**
     * @return BelongsTo<MatchSlot, $this>
     */
    public function matchSlot(): BelongsTo
    {
        return $this->belongsTo(MatchSlot::class);
    }

    protected function casts(): array
    {
        return ['role' => ParticipantRole::class];
    }
}
