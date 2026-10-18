<?php

namespace Tests\Support;

use App\Enums\MatchMethod;
use App\Models\MatchSlot;
use App\Services\Matches\MatchResolver;
use App\Services\Matches\ResolvedMatch;
use App\Support\Rng\Rng;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A MatchResolver that returns scripted winners, so tests can assert exact
 * title and stipulation consequences.
 */
class FixedOutcomes extends MatchResolver
{
    /** @var list<array{winner: string, method: MatchMethod}> */
    private array $queue = [];

    /**
     * Queue the next results in card order: the winner's name (any member of
     * the winning side) and the method.
     */
    public static function queue(string $winner, MatchMethod $method = MatchMethod::Pinfall): self
    {
        $fake = app()->bound(MatchResolver::class) && app(MatchResolver::class) instanceof self
            ? app(MatchResolver::class)
            : new self;

        $fake->queue[] = ['winner' => $winner, 'method' => $method];
        app()->instance(MatchResolver::class, $fake);

        return $fake;
    }

    public function resolve(MatchSlot $slot, Rng $rng, Collection $activeRivalries): ResolvedMatch
    {
        $next = array_shift($this->queue) ?? throw new RuntimeException("No scripted result for match {$slot->position}.");
        $sides = $slot->sides();
        $winnerSide = null;

        foreach ($slot->competitors() as $p) {
            if ($p->saveWrestler->wrestler->name === $next['winner']) {
                $winnerSide = $p->side;
            }
        }

        if ($winnerSide === null) {
            throw new RuntimeException("{$next['winner']} isn't in match {$slot->position}.");
        }

        $others = array_values(array_diff(array_keys($sides), [$winnerSide]));

        return new ResolvedMatch(
            $winnerSide,
            $others[0],
            $sides[$winnerSide],
            array_merge(...array_map(fn (int $s): array => $sides[$s], $others)),
            $next['method'],
            null,
            null,
            "{$next['winner']} wins.",
            0,
        );
    }
}
