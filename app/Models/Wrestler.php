<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\MatchFamily;
use App\Enums\RosterType;
use App\Enums\WeightClass;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Seeded catalog wrestler (§9, §12, §21). Per-save state lives on SaveWrestler.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property RosterType $roster_type
 * @property WeightClass $weight_class
 * @property int|null $billed_weight_lb
 * @property Brand|null $placeholder_brand
 * @property int|null $overall
 * @property string|null $hometown
 */
#[Unguarded]
class Wrestler extends Model
{
    /**
     * @return HasMany<WrestlerSpecialty, $this>
     */
    public function specialties(): HasMany
    {
        return $this->hasMany(WrestlerSpecialty::class);
    }

    /**
     * @return list<MatchFamily>
     */
    public function specialtyFamilies(): array
    {
        return array_values($this->specialties->map(fn (WrestlerSpecialty $s): MatchFamily => $s->family)->all());
    }

    public function isDiva(): bool
    {
        return $this->roster_type === RosterType::Diva;
    }

    public function isLegend(): bool
    {
        return $this->roster_type === RosterType::Legend;
    }

    /**
     * House rule: Legends can't be taken in the initial draft. They're
     * signed from Free Agency afterwards.
     */
    public function isDraftable(): bool
    {
        return ! $this->isLegend() || (bool) config('gm.draft.legends_draftable');
    }

    protected function casts(): array
    {
        return [
            'roster_type' => RosterType::class,
            'weight_class' => WeightClass::class,
            'placeholder_brand' => Brand::class,
        ];
    }
}
