<?php

namespace App\Models;

use App\Enums\Brand;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Free Agency signing waiting for next week (§2: FA roster changes take
 * effect the following week).
 *
 * @property int $id
 * @property int $save_wrestler_id
 * @property int $contract_id
 * @property Brand $brand
 * @property int $effective_week
 * @property int|null $applied_week
 * @property-read SaveWrestler $saveWrestler
 * @property-read Contract $contract
 */
#[Unguarded]
class PendingRosterMove extends Model
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
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    protected function casts(): array
    {
        return ['brand' => Brand::class];
    }
}
