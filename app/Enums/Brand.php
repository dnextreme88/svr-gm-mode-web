<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Raw or Smackdown (§1).
 */
enum Brand: string
{
    use HasValues;

    case Raw = 'raw';
    case Smackdown = 'smackdown';

    /**
     * The other brand.
     */
    public function rival(): self
    {
        return $this === self::Raw ? self::Smackdown : self::Raw;
    }

    public function label(): string
    {
        return match ($this) {
            self::Raw => 'Raw',
            self::Smackdown => 'Smackdown',
        };
    }
}
