<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\ReignEndReason;
use App\Enums\TitleHolderType;
use App\Enums\WonVia;
use App\Models\Concerns\BelongsToGame;
use App\Services\Factions\FactionNamer;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a title lineage, including vacant periods (§16).
 *
 * @property int $id
 * @property int $championship_id
 * @property Brand $brand
 * @property int|null $reign_number
 * @property TitleHolderType $holder_type
 * @property int|null $save_wrestler_id
 * @property int|null $tag_team_id
 * @property array{names: list<string>, ids: list<int>} $holder_snapshot
 * @property int $season
 * @property int $won_week
 * @property int|null $won_show_id
 * @property int|null $won_match_id
 * @property WonVia|null $won_via
 * @property int|null $lost_week
 * @property int|null $lost_show_id
 * @property int|null $lost_match_id
 * @property ReignEndReason $end_reason
 * @property int $defenses
 * @property-read Championship $championship
 * @property-read SaveWrestler|null $saveWrestler
 * @property-read TagTeam|null $tagTeam
 * @property-read Show|null $wonShow
 * @property-read Show|null $lostShow
 */
#[Unguarded]
class TitleReign extends Model
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
     * @return BelongsTo<SaveWrestler, $this>
     */
    public function saveWrestler(): BelongsTo
    {
        return $this->belongsTo(SaveWrestler::class);
    }

    /**
     * @return BelongsTo<TagTeam, $this>
     */
    public function tagTeam(): BelongsTo
    {
        return $this->belongsTo(TagTeam::class);
    }

    /**
     * @return BelongsTo<Show, $this>
     */
    public function wonShow(): BelongsTo
    {
        return $this->belongsTo(Show::class, 'won_show_id');
    }

    /**
     * @return BelongsTo<Show, $this>
     */
    public function lostShow(): BelongsTo
    {
        return $this->belongsTo(Show::class, 'lost_show_id');
    }

    public function isActive(): bool
    {
        return $this->end_reason === ReignEndReason::Active;
    }

    public function isVacant(): bool
    {
        return $this->holder_type === TitleHolderType::Vacant;
    }

    /**
     * Save-wrestler ids currently holding this reign (1 for singles, 2–3 for tag).
     *
     * @return list<int>
     */
    public function holderIds(): array
    {
        return $this->holder_snapshot['ids'];
    }

    /**
     * The holder's name; tag champions who were a faction when they won it
     * go by the faction (house rule).
     */
    public function holderName(): string
    {
        if ($this->isVacant()) {
            return 'Vacant';
        }

        $ids = $this->holderIds();

        return count($ids) > 1
            ? app(FactionNamer::class)->name($this->save_id, $ids, $this->won_week)
            : implode(' & ', $this->holder_snapshot['names']);
    }

    /**
     * Weeks held, counting to $currentWeek for an active reign.
     */
    public function weeksHeld(int $currentWeek): int
    {
        return ($this->lost_week ?? $currentWeek) - $this->won_week;
    }

    protected function casts(): array
    {
        return [
            'brand' => Brand::class,
            'holder_type' => TitleHolderType::class,
            'holder_snapshot' => 'array',
            'won_via' => WonVia::class,
            'end_reason' => ReignEndReason::class,
        ];
    }
}
