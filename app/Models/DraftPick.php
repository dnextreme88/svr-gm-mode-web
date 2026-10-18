<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\DraftPhase;
use App\Enums\DraftPickSource;
use App\Enums\DraftPickType;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One draft pick (§1, §13).
 *
 * @property int $id
 * @property int $season
 * @property DraftPhase $phase
 * @property int $pick_number
 * @property Brand $brand
 * @property int|null $save_wrestler_id
 * @property DraftPickSource $source
 * @property DraftPickType $pick_type
 * @property bool $skipped
 * @property-read SaveWrestler|null $saveWrestler
 */
#[Unguarded]
class DraftPick extends Model
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
            'phase' => DraftPhase::class,
            'brand' => Brand::class,
            'source' => DraftPickSource::class,
            'pick_type' => DraftPickType::class,
            'skipped' => 'boolean',
        ];
    }
}
