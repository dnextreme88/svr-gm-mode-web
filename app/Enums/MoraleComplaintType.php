<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Morale complaint condition (§15).
 */
enum MoraleComplaintType: string
{
    use HasValues;

    case LosingStreak = 'losing_streak';
    case ContractExtension = 'contract_extension';
    case TitleMatch = 'title_match';
    case Inactivity = 'inactivity';
}
