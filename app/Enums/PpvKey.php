<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use Illuminate\Support\Str;

/**
 * The 14 PPVs (§13).
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
    case TabooTuesday = 'taboo_tuesday';
    case SurvivorSeries = 'survivor_series';
    case Armageddon = 'armageddon';

    public function label(): string
    {
        return match ($this) {
            self::NewYearsRevolution => 'New Year\'s Revolution',
            self::Wrestlemania => 'WrestleMania',
            self::GreatAmericanBash => 'The Great American Bash',
            self::Summerslam => 'SummerSlam',
            default => Str::of($this->name)->headline()->toString(),
        };
    }
}
