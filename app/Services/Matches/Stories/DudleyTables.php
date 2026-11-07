<?php

namespace App\Services\Matches\Stories;

use App\Enums\ConditionalStory;
use App\Support\Rng\Rng;

/**
 * Dudley Boyz: get the tables! (house rule, 2026-11-07). Two or three of
 * Bubba Ray, D-Von and Spike on one side: a random one pulls a table from
 * under the ring, and a random one puts an opponent through it.
 */
final class DudleyTables extends TeamSpot
{
    public function story(): ConditionalStory
    {
        return ConditionalStory::DudleyTables;
    }

    protected function configKey(): string
    {
        return 'tables';
    }

    protected function minMembers(): int
    {
        return 2;
    }

    protected function teamName(): string
    {
        return 'The Dudleys';
    }

    protected function roles(StoryMatch $match, Rng $rng, array $members): array
    {
        $thrower = (int) $rng->pick($members);

        return ['setter' => (int) $rng->pick($members), 'thrower' => $thrower, 'scorer' => $thrower];
    }

    protected function tokens(StoryMatch $match, array $data): array
    {
        return [
            'setter' => $match->name((int) $data['setter']),
            'thrower' => $match->name((int) $data['thrower']),
            ...$match->pronouns('setter', (int) $data['setter']),
        ];
    }
}
