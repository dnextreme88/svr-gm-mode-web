<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\LedgerCategory;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of a brand's weekly income statement (§7). Positive = income.
 *
 * @property int $id
 * @property Brand $brand
 * @property int $season
 * @property int $week_number
 * @property LedgerCategory $category
 * @property int $amount
 * @property string $memo
 */
#[Unguarded]
class LedgerEntry extends Model
{
    use BelongsToGame;

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return [
            'brand' => Brand::class,
            'category' => LedgerCategory::class,
            'amount' => 'integer',
        ];
    }
}
