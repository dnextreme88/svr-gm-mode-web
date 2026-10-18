<?php

namespace App\Models;

use App\Enums\Brand;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One brand inside a save: its budget and whether a human runs it.
 *
 * @property int $id
 * @property int $save_id
 * @property Brand $brand
 * @property int $budget
 * @property bool $is_human
 * @property bool $draft_done
 * @property int $deficit_weeks
 * @property string|null $deficit_choice
 * @property-read Save $game
 */
#[Unguarded]
class SaveBrand extends Model
{
    /**
     * @return BelongsTo<Save, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Save::class, 'save_id');
    }

    protected function casts(): array
    {
        return [
            'brand' => Brand::class,
            'budget' => 'integer',
            'is_human' => 'boolean',
            'draft_done' => 'boolean',
        ];
    }
}
