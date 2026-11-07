<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\RematchClauseStatus;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rematch Clause (house rule, 2026-11-07): the former champion(s) of a title
 * are owed a title match against the new champion(s) until `expires_week`.
 * It's an Active Effect, not a stipulation, and never blocks a card.
 *
 * @property int $id
 * @property int $championship_id
 * @property Brand $brand
 * @property int $title_reign_id
 * @property list<int> $former_ids
 * @property list<int> $champion_ids
 * @property int $created_week
 * @property int $expires_week
 * @property RematchClauseStatus $status
 * @property int|null $resolved_match_id
 * @property int|null $resolved_week
 * @property-read Championship $championship
 */
#[Unguarded]
class RematchClause extends Model
{
    use BelongsToGame;

    /**
     * @return BelongsTo<Championship, $this>
     */
    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class);
    }

    /**
     * Weeks left to book it, counting this one.
     */
    public function weeksLeft(int $week): int
    {
        return max(0, $this->expires_week - $week + 1);
    }

    protected function casts(): array
    {
        return [
            'brand' => Brand::class,
            'status' => RematchClauseStatus::class,
            'former_ids' => 'array',
            'champion_ids' => 'array',
        ];
    }
}
