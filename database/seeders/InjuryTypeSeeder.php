<?php

namespace Database\Seeders;

use App\Enums\InjurySeverity;
use App\Models\InjuryType;
use Illuminate\Database\Seeder;

/**
 * Seeds the named injury tables (§6).
 */
class InjuryTypeSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array{minor: list<array{0: string, 1: int, 2: int, 3: string|null}>, serious: list<array{0: string, 1: int, 2: int}>} $data */
        $data = require __DIR__.'/data/injury_types.php';

        foreach ($data['serious'] as [$label, $min, $max]) {
            InjuryType::updateOrCreate(
                ['label' => $label],
                ['severity' => InjurySeverity::Serious, 'min_weeks' => $min, 'max_weeks' => $max, 'escalates_to_id' => null],
            );
        }

        foreach ($data['minor'] as [$label, $min, $max, $escalatesTo]) {
            InjuryType::updateOrCreate(
                ['label' => $label],
                [
                    'severity' => InjurySeverity::Minor,
                    'min_weeks' => $min,
                    'max_weeks' => $max,
                    'escalates_to_id' => $escalatesTo === null ? null : InjuryType::where('label', $escalatesTo)->value('id'),
                ],
            );
        }
    }
}
