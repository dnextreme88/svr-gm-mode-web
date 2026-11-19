<?php

namespace App\Services\Finance;

use App\Enums\Brand;
use App\Enums\ContractStatus;
use App\Enums\LedgerCategory;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Save;
use App\Services\Attendance\AttendanceNights;
use App\Services\Calendar\CalendarService;

/**
 * Weekly income statements built from the ledger (§3 Financials, §7, §11
 * FinancialLedger). A statement covers the whole week (house rule,
 * 2026-11-15): a PPV week holds the PPV night and the weekly show, each
 * night's lines grouped under it, then the week-wide lines (salaries,
 * medical, releases, the attendance bonus). The starting budget is the
 * opening balance, not the week's income, so it stays out of income,
 * expenses and net (it still counts in the balance).
 *
 * @phpstan-type Line array{category: string, group: string, label: string, memo: string, amount: int}
 * @phpstan-type Group array{key: string, name: string, ppv: bool, crowd: int|null, lines: list<Line>, total: int}
 */
class FinancialReport
{
    /**
     * Ledger categories shown together as one "Promos" line per night.
     */
    private const PROMO_CATEGORIES = [LedgerCategory::PromoCost, LedgerCategory::PromoIncome, LedgerCategory::InterferenceResult];

    public function __construct(
        private AttendanceNights $nights,
        private CalendarService $calendar,
    ) {}

    /**
     * @return array{week: int, season: int, season_week: int, title: string, groups: list<Group>, totals: list<array{group: string, label: string, amount: int}>, starting_budget: int, income: int, expenses: int, net: int}
     */
    public function forWeek(Save $save, Brand $brand, int $week): array
    {
        // Only settled lines: a show's match and promo money counts once its
        // news is in, as it does on the budget (house rule, 2026-11-17).
        $entries = LedgerEntry::where('save_id', $save->id)->where('settled', true)
            ->where('brand', $brand)
            ->where('week_number', $week)
            ->orderBy('id')
            ->get();

        $starting = $entries->filter(fn (LedgerEntry $e): bool => $e->category === LedgerCategory::StartingBudget);
        $entries = $entries->reject(fn (LedgerEntry $e): bool => $e->category === LedgerCategory::StartingBudget);
        $income = (int) $entries->where('amount', '>', 0)->sum('amount');
        $expenses = (int) $entries->where('amount', '<', 0)->sum('amount');

        // The brand's nights this week, in air order; a line belongs to the
        // night of its show, anything else is week-wide.
        $nights = array_values(array_filter(
            $this->nights->forWeek($save, $week),
            fn (array $n): bool => in_array($brand, $n['brands'], true),
        ));
        // In air order: a PPV airs on the week's Sunday, before the weekly shows.
        usort($nights, fn (array $a, array $b): int => (int) $b['ppv'] <=> (int) $a['ppv']);
        $meta = [];
        $nightOf = [];

        foreach ($nights as $night) {
            $meta[$night['key']] = ['name' => $night['name'], 'ppv' => $night['ppv'], 'crowd' => $night['crowd']];

            foreach ($night['show_ids'] as $id) {
                $nightOf[$id] = $night['key'];
            }
        }

        $meta['week'] = ['name' => 'Week-wide', 'ppv' => false, 'crowd' => null];
        $linesOf = [];

        foreach ($entries as $entry) {
            $key = $entry->show_id !== null && isset($nightOf[$entry->show_id]) ? $nightOf[$entry->show_id] : 'week';
            $linesOf[$key][] = $this->line($entry);
        }

        $groups = [];

        foreach ($meta as $key => $group) {
            if (($linesOf[$key] ?? []) === []) {
                continue;
            }

            $groups[] = [
                'key' => (string) $key,
                ...$group,
                'lines' => $linesOf[$key],
                'total' => array_sum(array_column($linesOf[$key], 'amount')),
            ];
        }

        return [
            'week' => $week,
            'season' => $this->calendar->seasonOf($week),
            'season_week' => $this->calendar->seasonWeek($week),
            'title' => $nights === [] ? 'Week '.$this->calendar->seasonWeek($week) : implode(' + ', array_map(fn (array $n): string => $n['name'], $nights)),
            'groups' => $groups,
            'totals' => $this->totals(array_values($entries->all())),
            'starting_budget' => (int) $starting->sum('amount'),
            'income' => $income,
            'expenses' => $expenses,
            'net' => $income + $expenses,
        ];
    }

    /**
     * Week-by-week totals with a running balance (which includes the
     * starting budget), and the PPV the brand ran that week, if any.
     *
     * @return list<array{week_number: int, season: int, season_week: int, ppv: string|null, income: int, expenses: int, net: int, balance: int}>
     */
    public function history(Save $save, Brand $brand): array
    {
        $rows = LedgerEntry::where('save_id', $save->id)->where('settled', true)
            ->where('brand', $brand)
            ->selectRaw('week_number, season, SUM(CASE WHEN category = ? THEN amount ELSE 0 END) as opening_balance, SUM(CASE WHEN category != ? AND amount > 0 THEN amount ELSE 0 END) as income, SUM(CASE WHEN category != ? AND amount < 0 THEN amount ELSE 0 END) as expenses', array_fill(0, 3, LedgerCategory::StartingBudget->value))
            ->groupBy('week_number', 'season')
            ->orderBy('week_number')
            ->get();

        $balance = 0;
        $history = [];

        foreach ($rows as $row) {
            $week = (int) $row->getAttribute('week_number');
            $income = (int) $row->getAttribute('income');
            $expenses = (int) $row->getAttribute('expenses');
            $balance += (int) $row->getAttribute('opening_balance') + $income + $expenses;

            $history[] = [
                'week_number' => $week,
                'season' => (int) $row->getAttribute('season'),
                'season_week' => $this->calendar->seasonWeek($week),
                'ppv' => $this->calendar->ppvForWeek($week, $brand)?->name,
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

    /**
     * @return Line
     */
    private function line(LedgerEntry $entry): array
    {
        $promo = in_array($entry->category, self::PROMO_CATEGORIES, true);

        return [
            'category' => $entry->category->value,
            // Promo costs, payouts and Interference results add up to one
            // "Promos" line per night.
            'group' => $promo ? 'promos' : $entry->category->value,
            'label' => $promo ? 'Promos' : $entry->category->label(),
            'memo' => $entry->memo,
            'amount' => $entry->amount,
        ];
    }

    /**
     * The week's totals per line group, for the income and cost bars.
     *
     * @param  list<LedgerEntry>  $entries
     * @return list<array{group: string, label: string, amount: int}>
     */
    private function totals(array $entries): array
    {
        $totals = [];

        foreach ($entries as $entry) {
            $line = $this->line($entry);
            $totals[$line['group']] ??= ['group' => $line['group'], 'label' => $line['label'], 'amount' => 0];
            $totals[$line['group']]['amount'] += $entry->amount;
        }

        return array_values($totals);
    }
}
