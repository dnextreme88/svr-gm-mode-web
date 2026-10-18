<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Money in the Bank briefcase status (§11).
 */
enum MitbStatus: string
{
    use HasValues;

    case Held = 'held';
    case CashedInWon = 'cashed_in_won';
    case CashedInLost = 'cashed_in_lost';
    case Expired = 'expired';
    case Voided = 'voided';
}
