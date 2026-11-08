<?php

namespace App\Services\Matches\Stories;

use App\Enums\ConditionalStory;
use App\Support\Rng\Rng;

/**
 * Kane's pyro (house rule, 2026-11-07): it goes off from the ramp or the
 * ring turnbuckles (50/50) and distracts his rival.
 */
final class KanePyro extends RivalryInterference
{
    public function story(): ConditionalStory
    {
        return ConditionalStory::KanePyro;
    }

    protected function configKey(): string
    {
        return 'pyro';
    }

    protected function picks(StoryMatch $match, Rng $rng, int $winnerId): array
    {
        return ['location' => $rng->chance(0.5) ? 'entrance ramp' : 'ring turnbuckles'];
    }
}
