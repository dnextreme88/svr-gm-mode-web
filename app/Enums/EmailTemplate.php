<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Email templates (§3, §11).
 */
enum EmailTemplate: string
{
    use HasValues;

    case Hint = 'hint';
    case ExpiringContracts = 'expiring_contracts';
    case ContractExpired = 'contract_expired';
    case Injury = 'injury';
    case MoraleComplaint = 'morale_complaint';
    case DefectionAttempt = 'defection_attempt';
    case TitleShot = 'title_shot';
    case Trade = 'trade';
    case Deficit = 'deficit';
}
