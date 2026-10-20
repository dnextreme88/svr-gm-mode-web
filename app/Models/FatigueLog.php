<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One wrestler's fatigue through a resolved week: going in, after the shows
 * (matches and promos), and after weekly recovery.
 *
 * @property int $id
 * @property int $save_id
 * @property int $week_number
 * @property int $save_wrestler_id
 * @property int $start
 * @property int $after_shows
 * @property int $end
 * @property int $recovered_bars
 * @property-read SaveWrestler $saveWrestler
 */
#[Unguarded]
class FatigueLog extends Model
{
    /**
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function saveWrestler(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class);
    }
}
