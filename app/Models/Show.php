<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\ShowStatus;
use App\Enums\ShowType;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A weekly show or PPV card (§1, §4, §11). `brand` is null for the single
 * interbrand WrestleMania card (§23).
 *
 * @property int $id
 * @property int $season
 * @property int $week_number
 * @property Brand|null $brand
 * @property ShowType $show_type
 * @property int|null $ppv_event_id
 * @property string $name
 * @property ShowStatus $status
 * @property-read PpvEvent|null $ppvEvent
 */
#[Unguarded]
class Show extends Model
{
    use BelongsToGame;

    /**
     * @return BelongsTo<PpvEvent, $this>
     */
    public function ppvEvent(): BelongsTo
    {
        return $this->belongsTo(PpvEvent::class);
    }

    /**
     * @return HasMany<MatchSlot, $this>
     */
    public function matchSlots(): HasMany
    {
        return $this->hasMany(MatchSlot::class)->orderBy('position');
    }

    /**
     * @return HasMany<PromoSlot, $this>
     */
    public function promoSlots(): HasMany
    {
        return $this->hasMany(PromoSlot::class)->orderBy('position');
    }

    /**
     * A stable RNG stream key for this card. Uses game values, not the row id,
     * so the same seed replays identically in any database.
     */
    public function streamKey(): string
    {
        return "{$this->week_number}.".($this->brand->value ?? 'interbrand').".{$this->show_type->value}";
    }

    public function isPpv(): bool
    {
        return $this->show_type === ShowType::Ppv;
    }

    public function isAired(): bool
    {
        return $this->status === ShowStatus::Aired;
    }

    public function isInterbrand(): bool
    {
        return $this->brand === null;
    }

    public function matchSlotCount(): int
    {
        return (int) config($this->isPpv() ? 'gm.card.ppv_match_slots' : 'gm.card.weekly_match_slots');
    }

    public function promoSlotCount(): int
    {
        return (int) config($this->isPpv() ? 'gm.card.ppv_promo_slots' : 'gm.card.weekly_promo_slots');
    }

    protected function casts(): array
    {
        return [
            'brand' => Brand::class,
            'show_type' => ShowType::class,
            'status' => ShowStatus::class,
        ];
    }
}
