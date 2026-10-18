<?php

namespace App\Services\Week\Steps;

use App\Services\Contracts\ContractService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Free Agency signings join their roster for the following week (§2).
 */
class ApplyPendingMoves implements WeekStep
{
    public function __construct(private ContractService $contracts) {}

    public function handle(WeekContext $context): void
    {
        $context->data['joined'] = $this->contracts->applyPendingMoves($context->save, $context->week + 1);
    }
}
