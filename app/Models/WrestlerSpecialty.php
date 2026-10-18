<?php

namespace App\Models;

use App\Enums\MatchFamily;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A wrestler's match specialty (§21).
 *
 * @property int $id
 * @property int $wrestler_id
 * @property MatchFamily $family
 */
#[Unguarded]
#[WithoutTimestamps]
class WrestlerSpecialty extends Model
{
    /**
     * @return BelongsTo<Wrestler, $this>
     */
    public function wrestler(): BelongsTo
    {
        return $this->belongsTo(Wrestler::class);
    }

    protected function casts(): array
    {
        return ['family' => MatchFamily::class];
    }
}
