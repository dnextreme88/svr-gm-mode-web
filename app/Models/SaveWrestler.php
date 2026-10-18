<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\ContractStatus;
use App\Enums\Morale;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A wrestler's state inside one save (§11 Wrestler). `brand` null = Free Agency.
 *
 * @property int $id
 * @property int $wrestler_id
 * @property Brand|null $brand
 * @property Morale $morale
 * @property int $fatigue
 * @property int $wins
 * @property int $losses
 * @property int $win_streak
 * @property int $loss_streak
 * @property int $weeks_unused
 * @property int $weeks_since_title_match
 * @property int|null $unbookable_until_week
 * @property int|null $unsignable_until_week
 * @property int|null $joined_brand_week
 * @property int|null $debuted_week
 * @property int|null $popularity
 * @property-read Wrestler $wrestler
 * @property-read Contract|null $activeContract
 */
#[Unguarded]
class SaveWrestler extends Model
{
    use BelongsToGame;

    /**
     * @return BelongsTo<Wrestler, $this>
     */
    public function wrestler(): BelongsTo
    {
        return $this->belongsTo(Wrestler::class);
    }

    /**
     * @return HasMany<Contract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * @return HasOne<Contract, $this>
     */
    public function activeContract(): HasOne
    {
        return $this->hasOne(Contract::class)->where('status', ContractStatus::Active)->latestOfMany();
    }

    /**
     * @return HasMany<MoraleComplaint, $this>
     */
    public function complaints(): HasMany
    {
        return $this->hasMany(MoraleComplaint::class);
    }

    /**
     * @return HasMany<MoraleComplaint, $this>
     */
    public function activeComplaints(): HasMany
    {
        return $this->complaints()->whereNull('resolved_week');
    }

    /**
     * @return HasMany<TitleReign, $this>
     */
    public function titleReigns(): HasMany
    {
        return $this->hasMany(TitleReign::class);
    }

    /**
     * @return BelongsToMany<TagTeam, $this>
     */
    public function tagTeams(): BelongsToMany
    {
        return $this->belongsToMany(TagTeam::class, 'tag_team_members');
    }

    public function name(): string
    {
        return $this->wrestler->name;
    }

    public function isSigned(): bool
    {
        return $this->brand !== null;
    }

    public function isSittingOut(int $week): bool
    {
        return $this->unbookable_until_week !== null && $week < $this->unbookable_until_week;
    }

    protected function casts(): array
    {
        return [
            'brand' => Brand::class,
            'morale' => Morale::class,
        ];
    }
}
