<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * Marker that a week has been resolved. Unique per (save, week) so week
 * resolution is idempotent (§2).
 *
 * @property int $id
 * @property int $week_number
 * @property int $season
 * @property array<string, mixed>|null $summary
 */
#[Unguarded]
class WeekResolution extends Model
{
    use BelongsToGame;

    protected function casts(): array
    {
        return ['summary' => 'array'];
    }
}
