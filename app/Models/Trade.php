<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\TradeStatus;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A trade offer between brands (§8, §11).
 *
 * @property int $id
 * @property int $season
 * @property int $week_number
 * @property Brand $proposer_brand
 * @property TradeStatus $status
 * @property string|null $note
 * @property-read Collection<int, TradeItem> $items
 */
#[Unguarded]
class Trade extends Model
{
    use BelongsToGame;

    /**
     * @return HasMany<TradeItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TradeItem::class);
    }

    protected function casts(): array
    {
        return [
            'proposer_brand' => Brand::class,
            'status' => TradeStatus::class,
        ];
    }
}
