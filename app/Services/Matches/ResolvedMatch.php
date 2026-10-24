<?php

namespace App\Services\Matches;

use App\Enums\FinishEvent;
use App\Enums\MatchMethod;
use App\Models\MatchType;

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

    /**
     * How it was won, for the news: "pinfall", "submission",
     * "disqualification", "count-out", or the type's own win condition
     * ("briefcase retrieval", "10-count").
     */
    public function via(?MatchType $type): string
    {
        $label = $this->method === MatchMethod::MatchSpecific && $type?->win_condition !== null
            ? $type->win_condition
            : $this->method->label();

        return mb_strtolower($label);
    }
}
