<?php

namespace App\Support\Rng;

/**
 * Builds RNG streams from a save's stored seed (§17).
 *
 * Each (seed, week, stream) triple gets its own independent generator, so a
 * retried or replayed week reproduces exactly the same rolls, and adding a
 * new roll in one system never shifts the results of another.
 */
class RngFactory
{
    public function make(string $seed, int $week, string $stream): Rng
    {
        return new SeededRng("{$seed}|{$week}|{$stream}");
    }

    /**
     * A fresh random seed for a new save. The one place real entropy is used.
     */
    public function newSeed(): string
    {
        return bin2hex(random_bytes(16));
    }
}
