<?php

namespace Database\Seeders;

use App\Models\Wrestler;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the full roster (§9, §12) and match specialties (§21).
 */
class WrestlerSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array{wrestlers: list<array<string, mixed>>, default_managers: list<string>, specialties: array<string, list<string>>} $data */
        $data = require __DIR__.'/data/wrestlers.php';

        foreach ($data['wrestlers'] as $row) {
            $name = (string) $row['name'];

            $wrestler = Wrestler::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    ...$row,
                    'is_default_manager' => in_array($name, $data['default_managers'], true),
                ],
            );

            $families = $data['specialties'][$name] ?? [];
            $wrestler->specialties()->whereNotIn('family', $families)->delete();

            foreach ($families as $family) {
                $wrestler->specialties()->firstOrCreate(['family' => $family]);
            }
        }
    }
}
