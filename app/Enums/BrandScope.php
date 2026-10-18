<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * PPV brand scope (§13).
 */
enum BrandScope: string
{
    use HasValues;

    case Raw = 'raw';
    case Smackdown = 'smackdown';
    case Shared = 'shared';

    public function label(): string
    {
        return match ($this) {
            self::Raw => 'Raw',
            self::Smackdown => 'SmackDown!',
            self::Shared => 'Big Four',
        };
    }
}
