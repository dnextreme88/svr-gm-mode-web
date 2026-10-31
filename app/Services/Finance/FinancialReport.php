<?php

namespace App\Services\Finance;

use App\Enums\Brand;
use App\Enums\ContractStatus;
use App\Enums\LedgerCategory;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Save;

/**
 * Weekly income statements built from the ledger (§3 Financials, §7, §11 FinancialLedger).
 * The starting budget is the opening balance, not the week's income, so it
 * stays out of income, expenses and net (it still counts in the balance).
 */
class FinancialReport
{
    /**
     * @return array{week: int, lines: list<array{category: string, label: string, memo: string, amount: int}>, starting_budget: int, income: int, expenses: int, net: int}
     */
    public function forWeek(Save $save, Brand $brand, int $week): array
    {
        $entries = LedgerEntry::where('save_id', $save->id)
            ->where('brand', $brand)
            ->where('week_number', $week)
            ->orderBy('id')
            ->get();

        $starting = $entries->filter(fn (LedgerEntry $e): bool => $e->category === LedgerCategory::StartingBudget);
        $entries = $entries->reject(fn (LedgerEntry $e): bool => $e->category === LedgerCategory::StartingBudget);
        $income = (int) $entries->where('amount', '>', 0)->sum('amount');
        $expenses = (int) $entries->where('amount', '<', 0)->sum('amount');

        return [
            'week' => $week,
            'starting_budget' => (int) $starting->sum('amount'),
            'lines' => array_values($entries->map(fn (LedgerEntry $e): array => [
                'category' => $e->category->value,
                'label' => $e->category->label(),
                'memo' => $e->memo,
                'amount' => $e->amount,
            ])->all()),
            'income' => $income,
            'expenses' => $expenses,
            'net' => $income + $expenses,
        ];
    }

    /**
     * Week-by-week totals with a running balance (which includes the
     * starting budget).
     *
     * @return list<array{week_number: int, season: int, income: int, expenses: int, net: int, balance: int}>
     */
    public function history(Save $save, Brand $brand): array
    {
        $rows = LedgerEntry::where('save_id', $save->id)
            ->where('brand', $brand)
            ->selectRaw('week_number, season, SUM(CASE WHEN category = ? THEN amount ELSE 0 END) as opening_balance, SUM(CASE WHEN category != ? AND amount > 0 THEN amount ELSE 0 END) as income, SUM(CASE WHEN category != ? AND amount < 0 THEN amount ELSE 0 END) as expenses', array_fill(0, 3, LedgerCategory::StartingBudget->value))
            ->groupBy('week_number', 'season')
            ->orderBy('week_number')
            ->get();

        $balance = 0;
        $history = [];

        foreach ($rows as $row) {
            $income = (int) $row->getAttribute('income');
            $expenses = (int) $row->getAttribute('expenses');
            $balance += (int) $row->getAttribute('opening_balance') + $income + $expenses;

            $history[] = [
                'week_number' => (int) $row->getAttribute('week_number'),
                'season' => (int) $row->getAttribute('season'),
                'income' => $income,
                'expenses' => $expenses,
                'net' => $income + $expenses,
                'balance' => $balance,
            ];
        }

        return $history;
    }

    /**
     * What the brand's active contracts cost every week.
     */
    public function weeklyUpkeep(Save $save, Brand $brand): int
    {
        return (int) Contract::where('save_id', $save->id)
            ->where('brand', $brand)
            ->where('status', ContractStatus::Active)
            ->sum('weekly_cost');
    }
}
