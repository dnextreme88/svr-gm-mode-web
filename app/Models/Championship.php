<?php

namespace App\Models;

use App\Enums\Brand;
use App\Enums\ChampionshipEligibility;
use App\Enums\ChampionshipKey;
use App\Enums\RosterType;
use App\Enums\WeightClass;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * One of the 8 GM Mode championships (§14).
 *
 * @property int $id
 * @property ChampionshipKey $key
 * @property string $name
 * @property Brand $brand
 * @property ChampionshipEligibility $eligibility
 * @property bool $is_tag
 * @property bool $is_world
 * @property int $sort_order
 */
#[Unguarded]
class Championship extends Model
{
    /**
     * Whether a catalog wrestler meets this title's eligibility rule.
     */
    public function allowsWrestler(Wrestler $wrestler): bool
    {
        return match ($this->eligibility) {
            // House rule: Divas can only hold the Women's Championship.
            ChampionshipEligibility::Open => $wrestler->roster_type !== RosterType::Diva,
            ChampionshipEligibility::DivasOnly => $wrestler->roster_type === RosterType::Diva,
            ChampionshipEligibility::CruiserweightOrLightHeavyweight => $wrestler->roster_type !== RosterType::Diva
                && in_array($wrestler->weight_class, [WeightClass::Cruiserweight, WeightClass::LightHeavyweight], true),
        };
    }

    protected function casts(): array
    {
        return [
            'key' => ChampionshipKey::class,
            'brand' => Brand::class,
            'eligibility' => ChampionshipEligibility::class,
            'is_tag' => 'boolean',
            'is_world' => 'boolean',
        ];
    }
}
