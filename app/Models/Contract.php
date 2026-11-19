<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\ContractStatus;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A wrestler contract (§2, §11).
 *
 * @property int $id
 * @property int $save_wrestler_id
 * @property Brand $brand
 * @property int $length_weeks
 * @property int $upfront_fee
 * @property int $weekly_cost
 * @property int $signed_week
 * @property int $starts_week
 * @property int $weeks_remaining
 * @property int|null $last_ticked_week
 * @property ContractStatus $status
 * @property int|null $ended_week
 * @property-read SaveWrestler $saveWrestler
 */
#[Unguarded]
class Contract extends Model
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
     * The unused part of the contract (house rule, 2026-11-17): what was
     * paid for it, pro rata for the weeks left. Refunded on an early release,
     * a renegotiation and Loser Leaves Show. A contract from before the
     * up-front payments (none paid) falls back to its weekly salary.
     */
    public function remainingValue(): int
    {
        if ($this->upfront_fee > 0 && $this->length_weeks > 0) {
            return (int) round($this->upfront_fee * $this->weeks_remaining / $this->length_weeks);
        }

        return $this->weeks_remaining * $this->weekly_cost;
    }

    protected function casts(): array
    {
        return [
            'brand' => Brand::class,
            'status' => ContractStatus::class,
        ];
    }
}
