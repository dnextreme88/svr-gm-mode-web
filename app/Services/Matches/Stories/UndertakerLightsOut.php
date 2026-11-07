<?php

namespace App\Services\Matches\Stories;

use App\Enums\ConditionalStory;
use App\Enums\MatchFamily;
use App\Support\Rng\Rng;

/**
 * Undertaker's lights-out (house rule, 2026-11-07): the arena goes dark for
 * about 10 seconds and Undertaker appears behind his rival with a Chokeslam
 * or a Tombstone Piledriver (50/50).
 */
final class UndertakerLightsOut extends RivalryInterference
{
    public function story(): ConditionalStory
    {
        return ConditionalStory::UndertakerLightsOut;
    }

    protected function configKey(): string
    {
        return 'lights_out';
    }

    protected function picks(StoryMatch $match, Rng $rng, int $winnerId): array
    {
        return [
            'move' => $rng->chance(0.5) ? 'Chokeslam' : 'Tombstone Piledriver',
            'opening' => $this->refereeOpening(
                $match,
                $rng,
                $match->family() === MatchFamily::Gauntlet ? 'the other entrants' : $match->name($winnerId),
                'Midway through the '.$match->matchWord().',',
            ),
        ];
    }
}
