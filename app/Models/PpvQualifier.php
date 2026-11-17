<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\PpvKey;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A superstar who won a Qualifying Match (house rule, 2026-11-17): a spot on
 * their brand's Survivor Series team or Money in the Bank ladder.
 *
 * @property int $id
 * @property int $save_id
 * @property int $season
 * @property PpvKey $ppv
 * @property Brand $brand
 * @property int $save_wrestler_id
 * @property int $match_slot_id
 * @property int $week_number
 * @property int|null $vacated_week
 * @property string|null $vacated_reason
 * @property-read SaveWrestler $saveWrestler
 */
#[Unguarded]
class PpvQualifier extends Model
{
    /**
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function saveWrestler(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class);
    }

    protected function casts(): array
    {
        return [
            'ppv' => PpvKey::class,
            'brand' => Brand::class,
        ];
    }
}
