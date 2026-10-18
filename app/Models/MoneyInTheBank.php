<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\MitbStatus;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Money in the Bank briefcase (§10).
 *
 * @property int $id
 * @property int $season
 * @property Brand $brand
 * @property int $save_wrestler_id
 * @property int $won_week
 * @property int $expires_week
 * @property MitbStatus $status
 * @property int|null $cash_in_match_id
 * @property int|null $resolved_week
 * @property string|null $resolution_note
 * @property-read SaveWrestler $holder
 */
#[Unguarded]
#[Table('money_in_the_bank')]
class MoneyInTheBank extends Model
{
    use BelongsToGame;

    /**
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class, 'save_wrestler_id');
    }

    public function isHeld(): bool
    {
        return $this->status === MitbStatus::Held;
    }

    protected function casts(): array
    {
        return [
            'brand' => Brand::class,
            'status' => MitbStatus::class,
        ];
    }
}
