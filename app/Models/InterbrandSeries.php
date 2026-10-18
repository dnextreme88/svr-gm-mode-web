<?php

namespace App\Models;

use App\Enums\Brand;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The three-match WrestleMania brand battle (§23, §11 InterbrandSeries).
 *
 * @property int $id
 * @property int $season
 * @property int $week_number
 * @property list<int> $match_ids
 * @property int $raw_wins
 * @property int $smackdown_wins
 * @property Brand|null $winner_brand
 * @property int|null $exemption_wrestler_id
 * @property int|null $handpick_wrestler_id
 * @property bool $redraft_done
 * @property-read SaveWrestler|null $exemption
 * @property-read SaveWrestler|null $handpick
 */
#[Unguarded]
#[Table('interbrand_series')]
class InterbrandSeries extends Model
{
    use BelongsToGame;

    /**
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function exemption(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class, 'exemption_wrestler_id');
    }

    /**
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function handpick(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class, 'handpick_wrestler_id');
    }

    protected function casts(): array
    {
        return [
            'match_ids' => 'array',
            'winner_brand' => Brand::class,
            'redraft_done' => 'boolean',
        ];
    }
}
