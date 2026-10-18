<?php

namespace App\Models;

use App\Enums\ComplaintLevel;
use App\Enums\MoraleComplaintType;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An open or resolved morale complaint (§11, §15).
 *
 * @property int $id
 * @property int $save_wrestler_id
 * @property MoraleComplaintType $type
 * @property ComplaintLevel $level
 * @property int $opened_week
 * @property int|null $resolved_week
 * @property-read SaveWrestler $saveWrestler
 */
#[Unguarded]
class MoraleComplaint extends Model
{
    use BelongsToGame;

    /**
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function saveWrestler(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class);
    }

    protected function casts(): array
    {
        return [
            'type' => MoraleComplaintType::class,
            'level' => ComplaintLevel::class,
        ];
    }
}
