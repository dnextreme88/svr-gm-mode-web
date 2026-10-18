<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Why a reign ended (§16).
 */
enum ReignEndReason: string
{
    use HasValues;

    case Active = 'active';
    case LostMatch = 'lost_match';
    case Defected = 'defected';
    case Injured = 'injured';
    case LoserLeaves = 'loser_leaves';
    case Released = 'released';
    case ContractExpired = 'contract_expired';
}
