<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One wrestler in a trade. `out` leaves the proposer, `in` joins them.
 *
 * @property int $id
 * @property int $trade_id
 * @property int $save_wrestler_id
 * @property string $direction
 * @property-read SaveWrestler $saveWrestler
 */
#[Unguarded]
#[WithoutTimestamps]
class TradeItem extends Model
{
    /**
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function saveWrestler(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class);
    }
}
