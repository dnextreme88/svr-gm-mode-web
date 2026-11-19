<?php

namespace App\Models;

use App\Enums\SaveStage;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * A career's last save (house rule, 2026-11-17). See `CareerCheckpoint`.
 *
 * @property int $id
 * @property int $save_id
 * @property int $current_week
 * @property SaveStage $stage
 * @property int|null $budget
 * @property string $fingerprint
 * @property string $payload
 */
#[Unguarded]
class SaveCheckpoint extends Model
{
    protected function casts(): array
    {
        return [
            'stage' => SaveStage::class,
        ];
    }
}
