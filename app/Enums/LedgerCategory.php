<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use Illuminate\Support\Str;

/**
 * Financial ledger line items (§7).
 */
enum LedgerCategory: string
{
    use HasValues;

    case StartingBudget = 'starting_budget';
    case SigningFee = 'signing_fee';
    case ContractUpkeep = 'contract_upkeep';
    case PromoCost = 'promo_cost';
    case PromoIncome = 'promo_income';
    case MatchCost = 'match_cost';
    case LoserLeavesRefund = 'loser_leaves_refund';
    case ShowIncome = 'show_income';
    // House rule (2026-11-15).
    case ShowProduction = 'show_production';
    case MedicalRehab = 'medical_rehab';
    case ReleaseFee = 'release_fee';
    case AttendanceBonus = 'attendance_bonus';
    case InterferenceResult = 'interference_result';
    // House rule (2026-11-17): contracts are paid in full when signed;
    // releasing early or renegotiating gives back the unused weeks.
    case ContractRefund = 'contract_refund';

    public function label(): string
    {
        return match ($this) {
            self::LoserLeavesRefund => 'Loser Leaves Show refund',
            self::ContractUpkeep => 'Superstar salaries',
            self::SigningFee => 'Contract signing',
            self::ContractRefund => 'Contract refund',
            self::ShowIncome => 'Fan attendance gate',
            self::ShowProduction => 'Show production',
            self::AttendanceBonus => 'Fan attendance bonus',
            default => Str::of($this->name)->headline()->toString(),
        };
    }
}
