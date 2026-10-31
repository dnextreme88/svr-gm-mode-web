<?php

namespace App\Services\Week\Steps;

use App\Enums\InjurySeverity;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Services\Injuries\InjuryService;
use App\Services\Mail\EmailService;
use App\Services\News\NewsRecorder;
use App\Services\Rivalries\RivalryService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Announces wrestlers cleared to return next week (the Trainer emails about
 * serious injuries), and cools idle rivalries.
 */
class InjuryReturns implements WeekStep
{
    public function __construct(
        private InjuryService $injuries,
        private NewsRecorder $news,
        private RivalryService $rivalries,
        private EmailService $emails,
    ) {}

    public function handle(WeekContext $context): void
    {
        foreach ($this->injuries->returningAfter($context->save->id, $context->week) as $injury) {
            $wrestler = $injury->saveWrestler;

            $this->news->record($context->save, NewsEventType::InjuryReturn, NewsTier::Story, $wrestler->brand ?? NewsBrand::Both, [
                'wrestler' => $wrestler->name(),
            ], ['wrestler_ids' => [$wrestler->id]], priority: 25, week: $context->week);

            if ($injury->severity === InjurySeverity::Serious && $wrestler->brand !== null) {
                $this->emails->injuryCleared($context->save, $wrestler->brand, $wrestler, $injury->type->label, $context->week);
            }
        }

        $this->rivalries->weeklyDecay($context->save, $context->week);
    }
}
