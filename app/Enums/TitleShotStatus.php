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
}
