<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Blocks contenders who lost a Last Chance match from title matches against
 * the same champion(s). It lapses automatically when the reign ends (§22).
 *
 * @property int $id
 * @property int $championship_id
 * @property int $title_reign_id
 * @property list<int> $wrestler_ids
 * @property int|null $match_slot_id
 * @property int $created_week
 * @property-read TitleReign $reign
 */
#[Unguarded]
class LastChanceLockout extends Model
{
    use BelongsToGame;

    /**
     * @return BelongsTo<TitleReign, $this>
     */
    public function reign(): BelongsTo
    {
        return $this->belongsTo(TitleReign::class, 'title_reign_id');
    }

    protected function casts(): array
    {
        return ['wrestler_ids' => 'array'];
    }
}
