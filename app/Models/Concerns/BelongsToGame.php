<?php

namespace App\Models\Concerns;

use App\Models\Save;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relation to the owning save. Named `game` because Model::save() is taken.
 *
 * @property int $save_id
 * @property-read Save $game
 */
trait BelongsToGame
{
    /**
     * @return BelongsTo<Save, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Save::class, 'save_id');
    }
}
