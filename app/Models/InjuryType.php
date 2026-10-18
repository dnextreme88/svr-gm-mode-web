<?php

namespace App\Models;

use App\Enums\InjurySeverity;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Seeded injury label (§6).
 *
 * @property int $id
 * @property string $label
 * @property InjurySeverity $severity
 * @property int $min_weeks
 * @property int $max_weeks
 * @property int|null $escalates_to_id
 * @property-read InjuryType|null $escalatesTo
 */
#[Unguarded]
class InjuryType extends Model
{
    /**
     * @return BelongsTo<InjuryType, $this>
     */
    public function escalatesTo(): BelongsTo
    {
        return $this->belongsTo(InjuryType::class, 'escalates_to_id');
    }

    protected function casts(): array
    {
        return ['severity' => InjurySeverity::class];
    }
}
