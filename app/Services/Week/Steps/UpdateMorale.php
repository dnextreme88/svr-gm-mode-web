<?php

namespace App\Services\Week\Steps;

use App\Services\Morale\DefectionService;
use App\Services\Morale\MoraleService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Morale clocks and complaints (§15), then defection rolls (§8).
 */
class UpdateMorale implements WeekStep
{
    public function __construct(
        private MoraleService $morale,
        private DefectionService $defections,
    ) {}

    public function handle(WeekContext $context): void
    {
        $this->morale->weekly(
            $context->save,
            $context->week,
            $context->data['used'] ?? [],
            $context->data['title_match'] ?? [],
        );

        $context->data['defections'] = $this->defections->weekly($context->save, $context->week, $context->rng('defection'));
    }
}
