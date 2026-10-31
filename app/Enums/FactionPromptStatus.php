<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A player's team that won enough together to become a faction (house
 * rule): waiting to be named, put off until their next win, or formed.
 */
enum FactionPromptStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Deferred = 'deferred';
    case Formed = 'formed';
}
