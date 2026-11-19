<?php

namespace App\Models;

use App\Enums\Brand;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

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
 * @property Brand|null $immunity_brand
 * @property list<int>|null $exemption_wrestler_ids
 * @property list<int>|null $handpick_wrestler_ids
 * @property bool $redraft_done
 */
#[Unguarded]
#[Table('interbrand_series')]
class InterbrandSeries extends Model
{
    use BelongsToGame;

    /**
     * Who the immunity tag protects (house rule, 2026-11-17: up to
     * `gm.ppv.redraft_immunity_count`).
     *
     * @return list<int>
     */
    public function exemptIds(): array
    {
        return array_map('intval', $this->exemption_wrestler_ids ?? []);
    }

    /**
     * Who the manual draft pick takes, in pick order (up to
     * `gm.ppv.redraft_manual_picks`).
     *
     * @return list<int>
     */
    public function handpickIds(): array
    {
        return array_map('intval', $this->handpick_wrestler_ids ?? []);
    }

    protected function casts(): array
    {
        return [
            'match_ids' => 'array',
            'winner_brand' => Brand::class,
            'immunity_brand' => Brand::class,
            'redraft_done' => 'boolean',
            'exemption_wrestler_ids' => 'array',
            'handpick_wrestler_ids' => 'array',
        ];
    }
}
