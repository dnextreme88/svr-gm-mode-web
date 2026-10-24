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
 * @property bool $needs_championship
 * @property bool $needs_diva_pair
 * @property int $fatigue_bars
 * @property string $effect
 * @property string|null $requirement
 * @property int $sort_order
 */
#[Unguarded]
class PromoType extends Model
{
    /**
     * Whether the promo targets two different wrestlers.
     */
    public function needsPair(): bool
    {
        return $this->needs_rival_pair || $this->needs_diva_pair;
    }

    public function successRate(): float
    {
        return (float) config('gm.promos.success_rates.'.$this->key->value);
    }

    /**
     * Lowest and highest success odds when they depend on a rolled tier
     * (Merchandise Sponsorship), else null.
     *
     * @return array{0: float, 1: float}|null
     */
    public function successRange(): ?array
    {
        if ($this->key !== PromoTypeKey::MerchandiseSponsorship) {
            return null;
        }

        $chances = array_map(fn (array $tier): float => (float) $tier['chance'], (array) config('gm.promos.merchandise_tiers'));

        return $chances === [] ? null : [min($chances), max($chances)];
    }

    protected function casts(): array
    {
        return [
            'key' => PromoTypeKey::class,
            'targets_rival' => 'boolean',
            'variable_amount' => 'boolean',
            'needs_wrestler' => 'boolean',
            'needs_rival_pair' => 'boolean',
            'needs_championship' => 'boolean',
            'needs_diva_pair' => 'boolean',
            'fatigue_bars' => 'integer',
        ];
    }
}
