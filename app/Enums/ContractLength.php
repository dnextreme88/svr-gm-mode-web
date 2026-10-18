<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Contract lengths in weeks (§2).
 */
enum ContractLength: int
{
    use HasValues;

    case FiveWeeks = 5;
    case ThreeMonths = 12;
    case SixMonths = 24;
    case NineMonths = 36;
    case OneYear = 52;

    public function label(): string
    {
        return match ($this) {
            self::FiveWeeks => '5 weeks',
            self::ThreeMonths => '3 months',
            self::SixMonths => '6 months',
            self::NineMonths => '9 months',
            self::OneYear => '1 year',
        };
    }
}
