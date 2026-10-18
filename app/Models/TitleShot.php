<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\TitleShotStatus;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A title match a contender earned in a Champion vs. Contender match (§22).
 *
 * @property int $id
 * @property int $championship_id
 * @property Brand $brand
 * @property list<int> $contender_ids
 * @property list<int> $champion_ids
 * @property int $earned_match_id
 * @property int $earned_week
 * @property int $due_week
 * @property int|null $fulfilled_match_id
 * @property TitleShotStatus $status
 * @property int|null $resolved_week
 * @property-read Championship $championship
 */
#[Unguarded]
class TitleShot extends Model
{
    use BelongsToGame;

    /**
     * @return BelongsTo<Championship, $this>
     */
    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class);
    }

    protected function casts(): array
    {
        return [
            'brand' => Brand::class,
            'contender_ids' => 'array',
            'champion_ids' => 'array',
            'status' => TitleShotStatus::class,
        ];
    }
}
