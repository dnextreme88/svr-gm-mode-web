<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\FactionOrigin;
use App\Enums\FactionType;
use App\Models\Concerns\BelongsToGame;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A faction inside one save (house rule). Seeded factions are active from
 * week 1 (`formed_week` 0); custom ones from the week they formed. A
 * disbanded faction keeps its name for matches before `disbanded_week`.
 *
 * @property int $id
 * @property int|null $faction_id
 * @property string $faction_name
 * @property FactionType $faction_type
 * @property FactionOrigin $origin
 * @property Brand|null $brand
 * @property int $formed_week
 * @property CarbonImmutable|null $date_formed
 * @property int $record_from_week
 * @property int|null $disbanded_week
 * @property CarbonImmutable|null $date_disbanded
 * @property-read Faction|null $faction
 * @property-read Collection<int, SaveWrestler> $members
 */
#[Unguarded]
class SaveFaction extends Model
{
    use BelongsToGame;

    /**
     * @return BelongsTo<Faction, $this>
     */
    public function faction(): BelongsTo
    {
        return $this->belongsTo(Faction::class);
    }

    /**
     * @return BelongsToMany<SaveWrestler, $this>
     */
    public function members(): BelongsToMany
    {
        // In the order they joined (the seeded order for real-life factions).
        return $this->belongsToMany(SaveWrestler::class, 'save_faction_members')->withPivot('joined_week')->orderByPivot('id');
    }

    /**
     * @return list<int>
     */
    public function memberIds(): array
    {
        return array_values(array_map('intval', $this->members->pluck('id')->all()));
    }

    public function isActive(): bool
    {
        return $this->disbanded_week === null;
    }

    /**
     * Whether the faction had its name in `$week`.
     */
    public function isActiveAt(int $week): bool
    {
        return $this->formed_week <= $week && ($this->disbanded_week === null || $week < $this->disbanded_week);
    }

    protected function casts(): array
    {
        return [
            'faction_type' => FactionType::class,
            'origin' => FactionOrigin::class,
            'brand' => Brand::class,
            'date_formed' => 'immutable_date',
            'date_disbanded' => 'immutable_date',
        ];
    }
}
