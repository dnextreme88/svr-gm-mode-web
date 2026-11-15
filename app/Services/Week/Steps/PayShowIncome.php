<?php

namespace App\Services\Week\Steps;

use App\Enums\Brand;
use App\Enums\LedgerCategory;
use App\Services\Attendance\AttendanceNights;
use App\Services\Finance\LedgerService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Show income (house rule, 2026-11-15): each night's gate (crowd × ticket
 * price) is paid to the brands on it, a shared night's split between them.
 * Then the brand whose nights drew the bigger crowd that week earns
 * `gm.finances.attendance_bonus`; a tie pays nobody.
 */
class PayShowIncome implements WeekStep
{
    public function __construct(
        private LedgerService $ledger,
        private AttendanceNights $nights,
    ) {}

    public function handle(WeekContext $context): void
    {
        $crowds = array_fill_keys(array_map(fn (Brand $b): string => $b->value, Brand::cases()), 0);

        foreach ($this->nights->forWeek($context->save, $context->week) as $night) {
            $share = intdiv($night['gate'], max(1, count($night['brands'])));

            foreach ($night['brands'] as $brand) {
                $crowds[$brand->value] += $night['crowd'];

                if ($share > 0) {
                    $this->ledger->post($context->save, $brand, LedgerCategory::ShowIncome, $share, 'Gate: '.$night['name'].' ('.number_format($night['crowd']).' fans)', $context->week, null, $night['show_ids'][0] ?? null);
                }
            }
        }

        $this->payBonus($context, $crowds);
    }

    /**
     * @param  array<string, int>  $crowds  the week's total crowd per brand
     */
    private function payBonus(WeekContext $context, array $crowds): void
    {
        $bonus = (int) config('gm.finances.attendance_bonus');
        arsort($crowds);
        $top = array_slice($crowds, 0, 2, true);
        $leaders = array_keys($top);

        if ($bonus <= 0 || reset($top) <= 0 || (count($top) > 1 && $top[$leaders[0]] === $top[$leaders[1]])) {
            return;
        }

        $this->ledger->post($context->save, Brand::from($leaders[0]), LedgerCategory::AttendanceBonus, $bonus, 'Biggest crowd this week ('.number_format($top[$leaders[0]]).' fans)', $context->week);
    }
}
