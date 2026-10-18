<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Raw or SmackDown! (§1).
 */
enum Brand: string
{
    use HasValues;

    case Raw = 'raw';
    case Smackdown = 'smackdown';

    public function label(): string
    {
        return match ($this) {
            self::Raw => 'Raw',
            self::Smackdown => 'SmackDown!',
        };
    }
}
