<?php

namespace App\Services\Week\Steps;

use App\Enums\Brand;
use App\Enums\LedgerCategory;
use App\Services\Attendance\AttendanceNights;
use App\Services\Finance\LedgerService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Show income (house rule, 2026-10-28): once the week is done, each brand
 * earns `gm.finances.weekly_show_income` for its shows. With
 * `gm.attendance.drives_income` on (house rule, 2026-11-01), each night's
 * gate is paid instead: a PPV night's split between the brands on it.
 */
class PayShowIncome implements WeekStep
{
    public function __construct(
        private LedgerService $ledger,
        private AttendanceNights $nights,
    ) {}

    public function handle(WeekContext $context): void
    {
        if ((bool) config('gm.attendance.drives_income')) {
            $this->payGates($context);

            return;
        }

        $amount = (int) config('gm.finances.weekly_show_income');

        if ($amount === 0) {
            return;
        }

        foreach (Brand::cases() as $brand) {
            $this->ledger->post($context->save, $brand, LedgerCategory::ShowIncome, $amount, 'Weekly show income', $context->week);
        }
    }

    private function payGates(WeekContext $context): void
    {
        foreach ($this->nights->forWeek($context->save, $context->week) as $night) {
            $share = intdiv($night['gate'], max(1, count($night['brands'])));

            foreach ($night['brands'] as $brand) {
                if ($share > 0) {
                    $this->ledger->post($context->save, $brand, LedgerCategory::ShowIncome, $share, "Gate: {$night['name']}", $context->week);
                }
            }
        }
    }
}
