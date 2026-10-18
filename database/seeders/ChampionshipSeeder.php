<?php

namespace Database\Seeders;

use App\Enums\Brand;
use App\Enums\ChampionshipEligibility;
use App\Enums\ChampionshipKey;
use App\Models\Championship;
use Illuminate\Database\Seeder;

/**
 * Seeds the 8 GM Mode championships, 4 per brand (§14).
 */
class ChampionshipSeeder extends Seeder
{
    public function run(): void
    {
        $titles = [
            [ChampionshipKey::Wwe, Brand::Raw, ChampionshipEligibility::Open, false, true],
            [ChampionshipKey::Intercontinental, Brand::Raw, ChampionshipEligibility::Open, false, false],
            [ChampionshipKey::WorldTagTeam, Brand::Raw, ChampionshipEligibility::Open, true, false],
            [ChampionshipKey::Womens, Brand::Raw, ChampionshipEligibility::DivasOnly, false, false],
            [ChampionshipKey::WorldHeavyweight, Brand::Smackdown, ChampionshipEligibility::Open, false, true],
            [ChampionshipKey::UnitedStates, Brand::Smackdown, ChampionshipEligibility::Open, false, false],
            [ChampionshipKey::WweTagTeam, Brand::Smackdown, ChampionshipEligibility::Open, true, false],
            [ChampionshipKey::Cruiserweight, Brand::Smackdown, ChampionshipEligibility::CruiserweightOrLightHeavyweight, false, false],
        ];

        foreach ($titles as $index => [$key, $brand, $eligibility, $isTag, $isWorld]) {
            Championship::updateOrCreate(
                ['key' => $key],
                [
                    'name' => $key->label(),
                    'brand' => $brand,
                    'eligibility' => $eligibility,
                    'is_tag' => $isTag,
                    'is_world' => $isWorld,
                    'sort_order' => $index + 1,
                ],
            );
        }
    }
}
