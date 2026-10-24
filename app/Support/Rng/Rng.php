<?php

namespace App\Support\Rng;

/**
 * The only source of randomness in the game (CLAUDE.md, §17).
 *
 * Implementations must be deterministic for a given seed so any week can
 * be replayed. Never call rand(), mt_rand(), random_int() or shuffle().
 */
interface Rng
{
    /**
     * Uniform integer in [min, max], inclusive.
     */
    public function int(int $min, int $max): int;

    /**
     * Uniform float in [0, 1).
     */
    public function float(): float;

    /**
     * True with probability $probability (clamped to 0–1).
     */
    public function chance(float $probability): bool;

    /**
     * One element of a non-empty list.
     *
     * @template T
     *
     * @param  array<array-key, T>  $items
     * @return T
     */
    public function pick(array $items): mixed;

    /**
     * A key chosen with probability proportional to its weight.
     *
     * @template K of array-key
     *
     * @param  array<K, int|float>  $weights
     * @return K
     */
    public function weightedPick(array $weights): int|string;

    /**
     * A shuffled copy of the list (keys are discarded).
     *
     * @template T
     *
     * @param  array<array-key, T>  $items
     * @return list<T>
     */
    public function shuffle(array $items): array;

    /**
     * Up to $count distinct elements, in random order.
     *
     * @template T
     *
     * @param  array<array-key, T>  $items
     * @return list<T>
     */
    public function sample(array $items, int $count): array;

    /**
     * How many draws this generator has made, recorded for replay/debugging.
     */
    public function steps(): int;

    /**
     * The generator's full state, so a stream can resume in a later request
     * exactly where it stopped (see `RngFactory::restore()`).
     */
    public function state(): string;
}
