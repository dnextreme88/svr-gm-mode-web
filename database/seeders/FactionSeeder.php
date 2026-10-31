<?php

namespace Database\Seeders;

use App\Models\Faction;
use App\Models\Wrestler;
use Illuminate\Database\Seeder;
use InvalidArgumentException;

/**
 * Seeds the real-life factions (house rule). Every member must be in the
 * roster, and a faction has 2–5 members.
 */
class FactionSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array{0: string, 1: string, 2: string, 3: string|null, 4: list<string>}> $rows */
        $rows = require __DIR__.'/data/factions.php';
        $min = (int) config('gm.factions.min_members');
        $max = (int) config('gm.factions.max_members');

        foreach ($rows as [$name, $type, $formed, $disbanded, $members]) {
            if (count($members) < $min || count($members) > $max) {
                throw new InvalidArgumentException("{$name} needs {$min}–{$max} members.");
            }

            $byName = Wrestler::whereIn('name', $members)->pluck('id', 'name');

            if ($byName->count() !== count($members)) {
                throw new InvalidArgumentException("{$name} has a member who isn't in the roster.");
            }

            // Kept in the listed order, which the Factions page shows.
            $ids = array_map(fn (string $member): int => (int) $byName[$member], $members);

            $faction = Faction::updateOrCreate(['faction_name' => $name], [
                'faction_type' => $type,
                'date_formed' => $formed,
                'date_disbanded' => $disbanded,
            ]);

            $faction->members()->sync($ids);
        }
    }
}
