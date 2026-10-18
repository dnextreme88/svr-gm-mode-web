<?php

namespace App\Services\Week\Steps;

use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Services\Injuries\InjuryService;
use App\Services\News\NewsRecorder;
use App\Services\Rivalries\RivalryService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Announces wrestlers cleared to return next week, and cools idle rivalries.
 */
class InjuryReturns implements WeekStep
{
    public function __construct(
        private InjuryService $injuries,
        private NewsRecorder $news,
        private RivalryService $rivalries,
    ) {}

    public function handle(WeekContext $context): void
    {
        foreach ($this->injuries->returningAfter($context->save->id, $context->week) as $injury) {
            $wrestler = $injury->saveWrestler;

            $this->news->record($context->save, NewsEventType::InjuryReturn, NewsTier::Story, $wrestler->brand ?? NewsBrand::Both, [
                'wrestler' => $wrestler->name(),
            ], ['wrestler_ids' => [$wrestler->id]], priority: 25, week: $context->week);
        }

        $this->rivalries->weeklyDecay($context->save, $context->week);
    }
}
