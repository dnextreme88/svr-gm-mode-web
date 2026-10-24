<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * News card slot (§19).
 */
enum NewsSlot: string
{
    use HasValues;

    case Raw = 'raw';
    case Smackdown = 'smackdown';
    case Neutral = 'neutral';

    public function label(): string
    {
        return match ($this) {
            self::Raw => 'Raw',
            self::Smackdown => 'Smackdown',
            self::Neutral => 'Neutral',
        };
    }
}
