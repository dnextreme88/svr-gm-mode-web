<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A feud between two wrestlers (§3 Rivalry, §11).
 *
 * @property int $id
 * @property int $wrestler_a_id
 * @property int $wrestler_b_id
 * @property int $heat
 * @property int $weeks_active
 * @property int $started_week
 * @property int $last_activity_week
 * @property int|null $ended_week
 * @property bool $active
 * @property-read SaveWrestler $wrestlerA
 * @property-read SaveWrestler $wrestlerB
 */
#[Unguarded]
class Rivalry extends Model
{
    use BelongsToGame;

    /**
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function wrestlerA(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class, 'wrestler_a_id');
    }

    /**
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function wrestlerB(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class, 'wrestler_b_id');
    }

    public function involves(int $saveWrestlerId): bool
    {
        return $this->wrestler_a_id === $saveWrestlerId || $this->wrestler_b_id === $saveWrestlerId;
    }

    public function otherThan(int $saveWrestlerId): int
    {
        return $this->wrestler_a_id === $saveWrestlerId ? $this->wrestler_b_id : $this->wrestler_a_id;
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
