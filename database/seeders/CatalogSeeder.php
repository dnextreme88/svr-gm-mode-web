<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds every lookup table that saves share (§9–14, §21).
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            WrestlerSeeder::class,
            InjuryTypeSeeder::class,
            PpvEventSeeder::class,
            MatchTypeSeeder::class,
            ChampionshipSeeder::class,
            PromoTypeSeeder::class,
            FactionSeeder::class,
        ]);
    }
}
