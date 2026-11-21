<?php

namespace App\Models;

use App\Enums\Brand;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One superstar's Royal Rumble (house rule, 2026-11-15): when they came in,
 * how many they threw out, who threw them out, and whether they won.
 *
 * @property int $id
 * @property int $match_slot_id
 * @property int $season
 * @property int $week_number
 * @property int $save_wrestler_id
 * @property Brand|null $brand
 * @property int $entry_number
 * @property int $eliminations
 * @property int|null $eliminated_by_id
 * @property int|null $elimination_order
 * @property bool $won
 * @property-read SaveWrestler $saveWrestler
 * @property-read SaveWrestler|null $eliminatedBy
 * @property-read MatchSlot $matchSlot
 */
#[Unguarded]
class RoyalRumbleEntry extends Model
{
    use BelongsToGame;

    /**
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function saveWrestler(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class);
    }

    /**
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function eliminatedBy(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class, 'eliminated_by_id');
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
        return [
            'brand' => Brand::class,
            'won' => 'boolean',
        ];
    }
}
