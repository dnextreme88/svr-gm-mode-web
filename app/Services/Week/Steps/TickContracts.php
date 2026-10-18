<?php

namespace App\Services\Week\Steps;

use App\Services\Contracts\ContractService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Weekly contract tick and upkeep; expired contracts go to Free Agency (§2).
 */
class TickContracts implements WeekStep
{
    public function __construct(private ContractService $contracts) {}

    public function handle(WeekContext $context): void
    {
        $context->data['expired'] = $this->contracts->tick($context->save, $context->week);
    }
}
