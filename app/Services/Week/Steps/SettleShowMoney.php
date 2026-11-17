<?php

namespace App\Services\Week\Steps;

use App\Services\Finance\LedgerService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * House rule (2026-11-17): the week's match prices and promo money reach
 * the budget once its shows are over and the news is in, not while the
 * results are still being decided.
 */
class SettleShowMoney implements WeekStep
{
    public function __construct(private LedgerService $ledger) {}

    public function handle(WeekContext $context): void
    {
        $this->ledger->settle($context->save, $context->week);
    }
}
