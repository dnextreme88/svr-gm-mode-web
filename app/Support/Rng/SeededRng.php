<?php

namespace App\Support\Rng;

use InvalidArgumentException;
use Random\Engine\Xoshiro256StarStar;
use Random\IntervalBoundary;
use Random\Randomizer;

/**
 * Deterministic RNG backed by PHP's Xoshiro256** engine.
 */
class SeededRng implements Rng
{
    private Randomizer $randomizer;

    private int $steps = 0;

    public function __construct(string $seed)
    {
        $this->randomizer = new Randomizer(new Xoshiro256StarStar(hash('sha256', $seed, true)));
    }

    public function int(int $min, int $max): int
    {
        if ($min > $max) {
            throw new InvalidArgumentException("Invalid range [{$min}, {$max}].");
        }

        $this->steps++;

        return $min === $max ? $min : $this->randomizer->getInt($min, $max);
    }

    public function float(): float
    {
        $this->steps++;

        return $this->randomizer->getFloat(0.0, 1.0, IntervalBoundary::ClosedOpen);
    }

    public function chance(float $probability): bool
    {
        if ($probability <= 0.0) {
            return false;
        }

        if ($probability >= 1.0) {
            return true;
        }

        return $this->float() < $probability;
    }

    public function pick(array $items): mixed
    {
        if ($items === []) {
            throw new InvalidArgumentException('Cannot pick from an empty list.');
        }

        $values = array_values($items);

        return $values[$this->int(0, count($values) - 1)];
    }

    public function weightedPick(array $weights): int|string
    {
        $weights = array_filter($weights, fn (int|float $weight): bool => $weight > 0);

        if ($weights === []) {
            throw new InvalidArgumentException('Cannot pick from empty or zero weights.');
        }

        $roll = $this->float() * array_sum($weights);
        $running = 0.0;

        foreach ($weights as $key => $weight) {
            $running += $weight;

            if ($roll < $running) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    public function shuffle(array $items): array
    {
        $values = array_values($items);

        // Fisher–Yates using our own draws so the step count stays exact.
        for ($i = count($values) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
        }

        return array_values($values);
    }

    public function sample(array $items, int $count): array
    {
        return array_slice($this->shuffle($items), 0, max(0, $count));
    }

    public function steps(): int
    {
        return $this->steps;
    }
}
