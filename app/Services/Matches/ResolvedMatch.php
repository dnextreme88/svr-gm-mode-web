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
     * The match a win condition belongs to, for "in a Tables match": a type
     * with its own way to win is named by its match, not its finish.
     *
     * @var array<string, string>
     */
    private const MATCH_NAMES = [
        'Put through a table' => 'Tables',
        'Briefcase retrieval' => 'Ladder',
        '10-count' => 'Last Man Standing',
        'Cage escape' => 'Steel Cage',
        'Over the top rope' => 'Battle Royal',
        'Last one in the ring' => 'Royal Rumble',
        'Buried alive' => 'Buried Alive',
        'First blood' => 'First Blood',
        'Bra & Panties' => 'Bra & Panties',
        'Most falls' => 'Iron Man',
        'Submission' => 'Submission',
    ];

    /**
     * @param  list<int>  $winners
     * @param  list<int>  $losers
     * @param  list<array{round: int, side_a: int, side_b: int, winner_side: int, method: MatchMethod, finish_event: FinishEvent|null, interferer_id: int|null, booked: bool, summary: string}>  $bouts  a Gauntlet's bouts, in order
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
        public array $bouts = [],
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

    /**
     * How it was won, as a phrase for the news (house rule, 2026-10-28):
     * "via pinfall", "via disqualification"… but a win by the type's own
     * condition names the match instead: "in a Tables match", "in a Ladder
     * match", "in an Iron Man match".
     */
    public function how(?MatchType $type): string
    {
        return $type !== null && $this->wonByOwnCondition($type)
            ? self::inA($this->matchName($type))
            : 'via '.$this->via($type);
    }

    /**
     * Won the one way the type allows: its own condition, or a tap-out in a
     * Submission match (recorded as a plain submission).
     */
    public function wonByOwnCondition(MatchType $type): bool
    {
        return $type->win_condition !== null && match ($this->method) {
            MatchMethod::MatchSpecific => true,
            MatchMethod::Submission => $type->win_condition === 'Submission',
            default => false,
        };
    }

    /**
     * The match's name for a win by its own condition: "Tables", "Ladder".
     */
    public function matchName(MatchType $type): string
    {
        return self::MATCH_NAMES[(string) $type->win_condition] ?? $type->family->label();
    }

    /**
     * "in a Tables match", "in an Iron Man match".
     */
    public static function inA(string $match): string
    {
        $article = preg_match('/^[AEIOU]/i', $match) === 1 ? 'an' : 'a';

        return "in {$article} {$match} match";
    }
}
