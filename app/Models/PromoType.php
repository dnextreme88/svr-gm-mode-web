<?php

namespace App\Models;

use App\Enums\PromoTypeKey;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * Seeded promo type (§5). Success rates are read from config/gm.php.
 *
 * @property int $id
 * @property PromoTypeKey $key
 * @property string $name
 * @property int $base_amount
 * @property bool $targets_rival
 * @property bool $variable_amount
 * @property bool $needs_wrestler
 * @property bool $needs_rival_pair
 * @property string $effect
 * @property int $sort_order
 */
#[Unguarded]
class PromoType extends Model
{
    public function successRate(): float
    {
        return (float) config('gm.promos.success_rates.'.$this->key->value);
    }

    protected function casts(): array
    {
        return [
            'key' => PromoTypeKey::class,
            'targets_rival' => 'boolean',
            'variable_amount' => 'boolean',
            'needs_wrestler' => 'boolean',
            'needs_rival_pair' => 'boolean',
        ];
    }
}
