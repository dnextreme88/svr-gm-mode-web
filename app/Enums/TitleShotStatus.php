<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Title shot obligation status (§22).
 */
enum TitleShotStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Fulfilled = 'fulfilled';
    case Voided = 'voided';
    // A #1 contendership the player let go when the title was vacated.
    case Forfeited = 'forfeited';
}
