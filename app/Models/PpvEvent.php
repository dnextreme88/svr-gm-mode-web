<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\BrandScope;
use App\Enums\PpvKey;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the 14 PPVs (§13).
 *
 * @property int $id
 * @property PpvKey $key
 * @property string $name
 * @property BrandScope $brand_scope
 * @property string $month
 * @property int $season_week
 */
#[Unguarded]
class PpvEvent extends Model
{
    /**
     * @return HasMany<MatchType, $this>
     */
    public function exclusiveMatchTypes(): HasMany
    {
        return $this->hasMany(MatchType::class, 'ppv_exclusive_id');
    }

    public function isShared(): bool
    {
        return $this->brand_scope === BrandScope::Shared;
    }

    public function runsOn(Brand $brand): bool
    {
        return $this->isShared() || $this->brand_scope->value === $brand->value;
    }

    protected function casts(): array
    {
        return [
            'key' => PpvKey::class,
            'brand_scope' => BrandScope::class,
        ];
    }
}
