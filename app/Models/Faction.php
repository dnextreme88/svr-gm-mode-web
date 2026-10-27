<?php

namespace App\Models;

use App\Enums\FactionType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Seeded catalog faction (house rule): a real-life tag team or stable. The
 * dates are real-life history, shown on the Factions page only. Per-save
 * state lives on SaveFaction.
 *
 * @property int $id
 * @property string $faction_name
 * @property FactionType $faction_type
 * @property CarbonImmutable $date_formed
 * @property CarbonImmutable|null $date_disbanded
 * @property-read Collection<int, Wrestler> $members
 */
#[Unguarded]
class Faction extends Model
{
    /**
     * @return BelongsToMany<Wrestler, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Wrestler::class, 'faction_members')->orderByPivot('id');
    }

    protected function casts(): array
    {
        return [
            'faction_type' => FactionType::class,
            'date_formed' => 'immutable_date',
            'date_disbanded' => 'immutable_date',
        ];
    }
}
