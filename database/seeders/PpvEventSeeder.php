<?php

namespace Database\Seeders;

use App\Enums\BrandScope;
use App\Enums\PpvKey;
use App\Models\PpvEvent;
use Illuminate\Database\Seeder;

/**
 * Seeds the 14-PPV calendar (§13). Season weeks come from config('gm.calendar').
 */
class PpvEventSeeder extends Seeder
{
    /**
     * @var array<string, array{0: BrandScope, 1: string}>
     */
    private const PPVS = [
        'new_years_revolution' => [BrandScope::Raw, 'January'],
        'royal_rumble' => [BrandScope::Shared, 'January'],
        'no_way_out' => [BrandScope::Smackdown, 'February'],
        'wrestlemania' => [BrandScope::Shared, 'March'],
        'backlash' => [BrandScope::Raw, 'April'],
        'judgment_day' => [BrandScope::Smackdown, 'May'],
        'vengeance' => [BrandScope::Raw, 'June'],
        'great_american_bash' => [BrandScope::Smackdown, 'July'],
        'summerslam' => [BrandScope::Shared, 'August'],
        'unforgiven' => [BrandScope::Raw, 'September'],
        'no_mercy' => [BrandScope::Smackdown, 'October'],
        'cyber_sunday' => [BrandScope::Raw, 'November'],
        'survivor_series' => [BrandScope::Shared, 'November'],
        'armageddon' => [BrandScope::Smackdown, 'December'],
        'tribute_to_the_troops' => [BrandScope::Raw, 'December'],
    ];

    public function run(): void
    {
        foreach (self::PPVS as $key => [$scope, $month]) {
            PpvEvent::updateOrCreate(
                ['key' => $key],
                [
                    'name' => PpvKey::from($key)->label(),
                    'brand_scope' => $scope,
                    'month' => $month,
                    'season_week' => (int) config("gm.calendar.ppv_weeks.{$key}"),
                ],
            );
        }
    }
}
