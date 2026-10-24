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
    // Live results: the player is deciding this show's matches.
    case Airing = 'airing';
    case Aired = 'aired';
}
