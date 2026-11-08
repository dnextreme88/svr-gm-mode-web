<?php

namespace App\Services\Matches;

/**
 * A result a conditional story decides (house rule, 2026-11-07), for a
 * simulated match: unlike Book to Win, the match stays simulated (`booked`
 * false). The win is always clean and decisive, never a DQ or count-out.
 *
 * Give a winning side, a losing side, or both. With only a losing side, the
 * winner is drawn from the other sides. In a Gauntlet it applies to the
 * first bout the given side is in. `victimId` and `scorerId` pick who the
 * finish names.
 */
final readonly class ForcedOutcome
{
    public function __construct(
        public ?int $winnerSide = null,
        public ?int $loserSide = null,
        public ?int $victimId = null,
        public ?int $scorerId = null,
    ) {}

    /**
     * The side this outcome is about, to find its Gauntlet bout: the side
     * that has to lose, else the side that has to win.
     */
    public function side(): ?int
    {
        return $this->loserSide ?? $this->winnerSide;
    }

    /**
     * The same outcome inside one Gauntlet bout: a forced loser loses to
     * whoever they face there.
     *
     * @param  list<int>  $pair  the bout's two sides
     */
    public function forBout(array $pair): self
    {
        if ($this->loserSide !== null && in_array($this->loserSide, $pair, true)) {
            $winner = $pair[0] === $this->loserSide ? $pair[1] : $pair[0];

            return new self($winner, $this->loserSide, $this->victimId);
        }

        return new self($this->winnerSide, null, null, $this->scorerId);
    }
}
