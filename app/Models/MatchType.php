<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\MatchFamily;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Seeded match type from the §10 taxonomy.
 *
 * `sides` lists how many competitors each side has, e.g. [1,1] or [2,2].
 * `manager_sides` lists the side indexes that bring a manager (§4).
 *
 * @property int $id
 * @property string $name
 * @property MatchFamily $family
 * @property string $variant
 * @property list<int> $sides
 * @property list<int> $manager_sides
 * @property int $min_competitors
 * @property int $max_competitors
 * @property int $fatigue_cost_bars
 * @property int $cost
 * @property bool $dq_enabled
 * @property bool $countout_enabled
 * @property bool $rope_breaks
 * @property bool $ring_out
 * @property bool $captain_fall
 * @property bool $timed
 * @property bool $divas_only
 * @property bool $divas_allowed
 * @property bool $titles_allowed
 * @property bool $is_elimination
 * @property bool $ppv_only
 * @property int|null $ppv_exclusive_id
 * @property Brand|null $brand_restriction
 * @property bool $gm_bookable
 * @property string|null $win_condition
 * @property int $sort_order
 * @property-read PpvEvent|null $ppvExclusive
 */
#[Unguarded]
class MatchType extends Model
{
    public const MITB_VARIANT = 'Money in the Bank';

    /**
     * @return BelongsTo<PpvEvent, $this>
     */
    public function ppvExclusive(): BelongsTo
    {
        return $this->belongsTo(PpvEvent::class, 'ppv_exclusive_id');
    }

    public function competitorCount(): int
    {
        return (int) array_sum($this->sides);
    }

    public function sideCount(): int
    {
        return count($this->sides);
    }

    public function fatigueCost(): int
    {
        return $this->fatigue_cost_bars * (int) config('gm.fatigue.bar_size');
    }

    public function hasManagers(): bool
    {
        return $this->manager_sides !== [];
    }

    public function isOneOnOne(): bool
    {
        return $this->sides === [1, 1];
    }

    public function isTwoOnTwo(): bool
    {
        return $this->sides === [2, 2];
    }

    /**
     * Whether any side fields more than one wrestler (tag-style booking).
     */
    public function isTagStyle(): bool
    {
        return $this->sides !== [] && max($this->sides) > 1;
    }

    public function isMoneyInTheBank(): bool
    {
        return $this->family === MatchFamily::Ladder && $this->variant === self::MITB_VARIANT;
    }

    protected function casts(): array
    {
        return [
            'family' => MatchFamily::class,
            'sides' => 'array',
            'manager_sides' => 'array',
            'dq_enabled' => 'boolean',
            'countout_enabled' => 'boolean',
            'rope_breaks' => 'boolean',
            'ring_out' => 'boolean',
            'captain_fall' => 'boolean',
            'timed' => 'boolean',
            'divas_only' => 'boolean',
            'divas_allowed' => 'boolean',
            'titles_allowed' => 'boolean',
            'is_elimination' => 'boolean',
            'ppv_only' => 'boolean',
            'brand_restriction' => Brand::class,
            'gm_bookable' => 'boolean',
        ];
    }
}
