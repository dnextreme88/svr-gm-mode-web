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

    public function label(): string
    {
        return match ($this) {
            self::LoserLeavesRefund => 'Loser Leaves Show refund',
            default => Str::of($this->name)->headline()->toString(),
        };
    }
}
