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
    case Welcome = 'welcome';
    case SpecialtyTip = 'specialty_tip';
    case PromoReminder = 'promo_reminder';
    case PpvReminder = 'ppv_reminder';
    case DraftReminder = 'draft_reminder';
    case LowCash = 'low_cash';
    case ContractReminder = 'contract_reminder';
    case InjuryReturning = 'injury_returning';
    case SlanderResult = 'slander_result';
    case RivalSlander = 'rival_slander';
}
