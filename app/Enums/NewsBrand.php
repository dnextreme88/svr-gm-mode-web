<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * News event brand scope (§19).
 */
enum NewsBrand: string
{
    use HasValues;

    case Raw = 'raw';
    case Smackdown = 'smackdown';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Raw => 'Raw',
            self::Smackdown => 'Smackdown',
            self::Both => 'Both',
        };
    }
}
