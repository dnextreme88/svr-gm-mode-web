<?php

namespace App\Services\Matches\Stories;

use App\Enums\ConditionalStory;
use App\Support\Rng\Rng;

/**
 * Team Xtreme's ladder spot (house rule, 2026-11-07). Jeff and Matt Hardy on
 * one side, Lita optional (competing or managing): a random brother climbs
 * the ladder, Jeff for a Swanton Bomb, Matt for a Leg Drop.
 */
final class HardyLadder extends TeamSpot
{
    public function story(): ConditionalStory
    {
        return ConditionalStory::HardyLadder;
    }

    protected function configKey(): string
    {
        return 'ladder';
    }

    protected function minMembers(): int
    {
        return 2;
    }

    protected function teamName(): string
    {
        return 'Team Xtreme';
    }

    protected function roles(StoryMatch $match, Rng $rng, array $members): array
    {
        $climber = (int) $rng->pick($members);
        $partner = (int) (array_values(array_diff($members, [$climber]))[0] ?? $climber);
        $jeff = $this->wrestler($match->save(), 'jeff-hardy')?->id;
        $lita = $this->wrestler($match->save(), (string) config('gm.stories.ladder.lita'));
        $side = (int) $match->sideOf($climber);

        return [
            'climber' => $climber,
            'partner' => $partner,
            'scorer' => $climber,
            'move' => $climber === $jeff ? 'Swanton Bomb' : 'Leg Drop',
            'lita' => $lita !== null && $this->onSide($match, $lita->id, $side),
        ];
    }

    protected function tokens(StoryMatch $match, array $data): array
    {
        return [
            'climber' => $match->name((int) $data['climber']),
            'partner' => $match->name((int) $data['partner']),
            'move' => (string) $data['move'],
            ...$match->pronouns('climber', (int) $data['climber']),
        ];
    }

    protected function variant(array $data): string
    {
        return ($data['lita'] ?? false) ? '_lita' : '';
    }
}
