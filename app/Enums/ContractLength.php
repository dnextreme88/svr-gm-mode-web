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

    /**
     * Months a contract of this length is priced as (house rule, 2026-11-15).
     */
    public function months(): int
    {
        return (int) config('gm.contracts.months.'.$this->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::FiveWeeks => '1 month',
            self::ThreeMonths => '3 months',
            self::SixMonths => '6 months',
            self::NineMonths => '9 months',
            self::OneYear => '12 months',
        };
    }
}
