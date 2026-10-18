<?php

namespace App\Models;

use App\Enums\Brand;
use App\Models\Concerns\BelongsToGame;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A tag team; holds tag titles (§11, §16).
 *
 * @property int $id
 * @property string $name
 * @property Brand|null $brand
 * @property bool $active
 * @property-read Collection<int, SaveWrestler> $members
 */
#[Unguarded]
class TagTeam extends Model
{
    use BelongsToGame;

    /**
     * @return BelongsToMany<SaveWrestler, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(SaveWrestler::class, 'tag_team_members');
    }

    /**
     * @return list<int>
     */
    public function memberIds(): array
    {
        return array_values(array_map('intval', $this->members->pluck('id')->all()));
    }

    protected function casts(): array
    {
        return [
            'brand' => Brand::class,
            'active' => 'boolean',
        ];
    }
}
