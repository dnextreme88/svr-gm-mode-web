<?php

namespace App\Services\Week\Steps;

use App\Enums\Brand;
use App\Enums\LedgerCategory;
use App\Services\Finance\LedgerService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Show income (house rule, 2026-10-28): once the week is done, each brand
 * earns `gm.finances.weekly_show_income` for its shows. A flat amount until
 * Popularity and ratings can drive it.
 */
class PayShowIncome implements WeekStep
{
    public function __construct(private LedgerService $ledger) {}

    public function handle(WeekContext $context): void
    {
        $amount = (int) config('gm.finances.weekly_show_income');

        if ($amount === 0) {
            return;
        }

        foreach (Brand::cases() as $brand) {
            $this->ledger->post($context->save, $brand, LedgerCategory::ShowIncome, $amount, 'Weekly show income', $context->week);
        }
    }
}
