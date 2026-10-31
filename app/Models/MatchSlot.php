<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\FixedSlotKind;
use App\Enums\ParticipantRole;
use App\Enums\Stipulation;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One match on a card (§4, §11 MatchSlot).
 *
 * @property int $id
 * @property int $show_id
 * @property int $position
 * @property int|null $match_type_id
 * @property int|null $title_championship_id
 * @property Stipulation|null $stipulation
 * @property int|null $stipulation_championship_id
 * @property list<int>|null $contested_title_ids
 * @property int $cost
 * @property FixedSlotKind|null $fixed_kind
 * @property Brand|null $owner_brand
 * @property float|null $rating
 * @property int|null $fan_delta
 * @property-read Show $show
 * @property-read MatchType|null $matchType
 * @property-read Championship|null $titleChampionship
 * @property-read Championship|null $stipulationChampionship
 * @property-read Collection<int, MatchParticipant> $participants
 * @property-read MatchResult|null $result
 * @property-read Collection<int, GauntletBout> $gauntletBouts
 */
#[Unguarded]
class MatchSlot extends Model
{
    /**
     * @return BelongsTo<Show, $this>
     */
    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }

    /**
     * @return BelongsTo<MatchType, $this>
     */
    public function matchType(): BelongsTo
    {
        return $this->belongsTo(MatchType::class);
    }

    /**
     * @return BelongsTo<Championship, $this>
     */
    public function titleChampionship(): BelongsTo
    {
        return $this->belongsTo(Championship::class, 'title_championship_id');
    }

    /**
     * @return BelongsTo<Championship, $this>
     */
    public function stipulationChampionship(): BelongsTo
    {
        return $this->belongsTo(Championship::class, 'stipulation_championship_id');
    }

    /**
     * @return HasMany<MatchParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(MatchParticipant::class)->orderBy('side')->orderBy('id');
    }

    /**
     * @return HasOne<MatchResult, $this>
     */
    public function result(): HasOne
    {
        return $this->hasOne(MatchResult::class);
    }

    /**
     * @return HasMany<GauntletBout, $this>
     */
    public function gauntletBouts(): HasMany
    {
        return $this->hasMany(GauntletBout::class)->orderBy('round');
    }

    public function isBooked(): bool
    {
        return $this->match_type_id !== null && $this->competitors()->isNotEmpty();
    }

    /**
     * @return Collection<int, MatchParticipant>
     */
    public function competitors(): Collection
    {
        return $this->participants->where('role', ParticipantRole::Competitor)->values();
    }

    /**
     * @return Collection<int, MatchParticipant>
     */
    public function managers(): Collection
    {
        return $this->participants->where('role', ParticipantRole::Manager)->values();
    }

    /**
     * Competitor save-wrestler ids grouped by side.
     *
     * @return array<int, list<int>>
     */
    public function sides(): array
    {
        $sides = [];

        foreach ($this->competitors() as $p) {
            $sides[$p->side][] = $p->save_wrestler_id;
        }

        ksort($sides);

        return $sides;
    }

    /**
     * @return list<int>
     */
    public function competitorIds(): array
    {
        return array_values(array_map('intval', $this->competitors()->pluck('save_wrestler_id')->all()));
    }

    /**
     * @return list<int>
     */
    public function participantIds(): array
    {
        return array_values(array_map('intval', $this->participants->pluck('save_wrestler_id')->all()));
    }

    public function isFixed(): bool
    {
        return $this->fixed_kind !== null;
    }

    protected function casts(): array
    {
        return [
            'stipulation' => Stipulation::class,
            'contested_title_ids' => 'array',
            'fixed_kind' => FixedSlotKind::class,
            'owner_brand' => Brand::class,
            'rating' => 'float',
        ];
    }
}
