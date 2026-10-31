<?php

namespace App\Models;

use App\Enums\TitleVacancyPromptStatus;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vacated title whose #1 contender(s) the player can award it to, or let
 * the contendership go (house rule).
 *
 * @property int $id
 * @property int $save_id
 * @property int $championship_id
 * @property int $title_shot_id
 * @property int $week_number
 * @property TitleVacancyPromptStatus $status
 */
#[Unguarded]
class TitleVacancyPrompt extends Model
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
     * @return BelongsTo<TitleShot, $this>
     */
    public function titleShot(): BelongsTo
    {
        return $this->belongsTo(TitleShot::class);
    }

    protected function casts(): array
    {
        return [
            'status' => TitleVacancyPromptStatus::class,
        ];
    }
}
