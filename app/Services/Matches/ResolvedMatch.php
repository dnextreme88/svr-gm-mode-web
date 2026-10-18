<?php

namespace App\Services\Matches;

use App\Enums\FinishEvent;
use App\Enums\MatchMethod;

/**
 * The rolled outcome of one match, before it's applied.
 */
final readonly class ResolvedMatch
{
    /**
     * @param  list<int>  $winners
     * @param  list<int>  $losers
     */
    public function __construct(
        public int $winnerSide,
        public int $loserSide,
        public array $winners,
        public array $losers,
        public MatchMethod $method,
        public ?FinishEvent $finishEvent,
        public ?int $interfererId,
        public string $summary,
        public int $rngSteps,
    ) {}

    public function isDecisive(): bool
    {
        return ! in_array($this->method, [MatchMethod::Dq, MatchMethod::Countout], true);
    }
}
