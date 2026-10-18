<?php

namespace App\Services\Finance;

use App\Enums\Brand;
use App\Models\LedgerEntry;
use App\Models\Save;

/**
 * Weekly income statements built from the ledger (§3 Financials, §7, §11 FinancialLedger).
 */
class FinancialReport
{
    /**
     * @return array{week: int, lines: list<array{category: string, label: string, memo: string, amount: int}>, income: int, expenses: int, net: int}
     */
    public function forWeek(Save $save, Brand $brand, int $week): array
    {
        $entries = LedgerEntry::where('save_id', $save->id)
            ->where('brand', $brand)
            ->where('week_number', $week)
            ->orderBy('id')
            ->get();

        $income = (int) $entries->where('amount', '>', 0)->sum('amount');
        $expenses = (int) $entries->where('amount', '<', 0)->sum('amount');

        return [
            'week' => $week,
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
     * Week-by-week totals with a running balance.
     *
     * @return list<array{week_number: int, season: int, income: int, expenses: int, net: int, balance: int}>
     */
    public function history(Save $save, Brand $brand): array
    {
        $rows = LedgerEntry::where('save_id', $save->id)
            ->where('brand', $brand)
            ->selectRaw('week_number, season, SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as income, SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END) as expenses')
            ->groupBy('week_number', 'season')
            ->orderBy('week_number')
            ->get();

        $balance = 0;
        $history = [];

        foreach ($rows as $row) {
            $income = (int) $row->getAttribute('income');
            $expenses = (int) $row->getAttribute('expenses');
            $balance += $income + $expenses;

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
}
