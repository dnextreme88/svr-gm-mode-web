<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How a new game builds its rosters: the initial draft, or each brand
 * signs its real Nov 2005 WWE roster (the seeded placeholder brands).
 */
enum DraftMode: string
{
    use HasValues;

    case Manual = 'manual';
    case WweRoster = 'wwe_roster';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual draft pick',
            self::WweRoster => 'Use WWE roster',
        };
    }
}
