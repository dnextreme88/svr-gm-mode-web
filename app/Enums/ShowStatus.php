<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Show booking status.
 */
enum ShowStatus: string
{
    use HasValues;

    case Booking = 'booking';
    case Locked = 'locked';
    case Aired = 'aired';
}
