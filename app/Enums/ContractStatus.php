<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Contract status (§2).
 */
enum ContractStatus: string
{
    use HasValues;

    case Active = 'active';
    case Expired = 'expired';
    case Released = 'released';
    case Superseded = 'superseded';
}
