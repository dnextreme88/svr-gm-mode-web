<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use Illuminate\Support\Str;

/**
 * The 15 PPVs (§13): 6 Raw, 5 SmackDown, 4 shared.
 */
enum PpvKey: string
{
    use HasValues;

    case NewYearsRevolution = 'new_years_revolution';
    case RoyalRumble = 'royal_rumble';
    case NoWayOut = 'no_way_out';
    case Wrestlemania = 'wrestlemania';
    case Backlash = 'backlash';
    case JudgmentDay = 'judgment_day';
    case Vengeance = 'vengeance';
    case GreatAmericanBash = 'great_american_bash';
    case Summerslam = 'summerslam';
    case Unforgiven = 'unforgiven';
    case NoMercy = 'no_mercy';
    case CyberSunday = 'cyber_sunday';
    case SurvivorSeries = 'survivor_series';
    case Armageddon = 'armageddon';
    case TributeToTheTroops = 'tribute_to_the_troops';

    public function label(): string
    {
        return match ($this) {
            self::NewYearsRevolution => 'New Year\'s Revolution',
            self::Wrestlemania => 'WrestleMania',
            self::GreatAmericanBash => 'The Great American Bash',
            self::Summerslam => 'SummerSlam',
            self::TributeToTheTroops => 'Tribute to the Troops',
            default => Str::of($this->name)->headline()->toString(),
        };
    }
}
