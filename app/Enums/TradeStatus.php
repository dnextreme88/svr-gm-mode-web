<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Trade status (§11).
 */
enum TradeStatus: string
{
    use HasValues;

    case Proposed = 'proposed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Invalid = 'invalid';
    // House rule (2026-11-21): a rival GM offer the player never answered.
    case Expired = 'expired';
}
