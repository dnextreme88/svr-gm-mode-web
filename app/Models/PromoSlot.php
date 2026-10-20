<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A promo on a weekly show (§5, §11 PromoSlot).
 *
 * @property int $id
 * @property int $show_id
 * @property int $position
 * @property int|null $before_position the match slot this promo airs before
 * @property int|null $promo_type_id
 * @property int|null $target_wrestler_id
 * @property int|null $target_wrestler_b_id
 * @property int|null $target_championship_id
 * @property int|null $cost_or_profit
 * @property bool|null $succeeded
 * @property bool $backfired
 * @property string|null $outcome_note
 * @property-read Show $show
 * @property-read PromoType|null $promoType
 * @property-read SaveWrestler|null $target
 * @property-read SaveWrestler|null $targetB
 * @property-read Championship|null $targetChampionship
 */
#[Unguarded]
class PromoSlot extends Model
{
    /**
     * @return BelongsTo<Show, $this>
     */
    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }

    /**
     * @return BelongsTo<PromoType, $this>
     */
    public function promoType(): BelongsTo
    {
        return $this->belongsTo(PromoType::class);
    }

    /**
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class, 'target_wrestler_id');
    }

    /**
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function targetB(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class, 'target_wrestler_b_id');
    }

    /**
     * @return BelongsTo<Championship, $this>
     */
    public function targetChampionship(): BelongsTo
    {
        return $this->belongsTo(Championship::class, 'target_championship_id');
    }

    /**
     * @return list<int>
     */
    public function targetIds(): array
    {
        return array_values(array_filter([$this->target_wrestler_id, $this->target_wrestler_b_id], fn (?int $id): bool => $id !== null));
    }

    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'backfired' => 'boolean',
        ];
    }
}
