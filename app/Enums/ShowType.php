<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Weekly show or PPV (§1).
 */
enum ShowType: string
{
    use HasValues;

    case Weekly = 'weekly';
    case Ppv = 'ppv';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly Show',
            self::Ppv => 'PPV',
        };
    }
}
