<?php

namespace App\Services\Week\Steps;

use App\Services\Ai\AiTradeOffers;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * The rival GM's trade offer (house rule, 2026-11-21): every few season
 * weeks it emails the player an offer, and unanswered offers expire.
 */
class RivalTradeOffer implements WeekStep
{
    public function __construct(private AiTradeOffers $offers) {}

    public function handle(WeekContext $context): void
    {
        $this->offers->handleWeek($context->save, $context->week);
    }
}
