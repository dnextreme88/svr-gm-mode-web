<?php

namespace App\Services\News;

use App\Enums\FinishEvent;
use App\Enums\MatchFamily;
use App\Enums\MatchMethod;
use App\Models\MatchSlot;

/**
 * How a match was won, in news-story words: "with a pinfall", "by
 * submission", "through a table"…
 */
class MatchFinish
{
    /**
     * How a gimmick match was won, for match types that have their own finish.
     *
     * @var array<string, string>
     */
    private const GIMMICK_FINISH = [
        'table' => 'through a table',
        'ladder' => 'in a Ladder match',
        'tlc' => 'in a TLC match',
        'steel_cage' => 'in a Steel Cage match',
        'hell_in_a_cell' => 'inside Hell in a Cell',
        'first_blood' => 'in a First Blood match',
        'last_man_standing' => 'in a Last Man Standing match',
        'buried_alive' => 'in a Buried Alive match',
        'iron_man' => 'in an Iron Man match',
        'hardcore' => 'in a Hardcore match',
        'backstage_brawl' => 'in a Backstage Brawl',
        'battle_royal' => 'in a Battle Royal',
        'royal_rumble' => 'in the Royal Rumble',
        'elimination_chamber' => 'in the Elimination Chamber',
        'three_stages_of_hell' => 'in a Three Stages of Hell match',
        'survivor_series_elimination' => 'in the Survivor Series elimination match',
    ];

    public static function phrase(MatchSlot $match): string
    {
        $result = $match->result;
        $family = $match->matchType?->family;

        return match (true) {
            $result === null => '',
            $result->method === MatchMethod::Dq => 'by disqualification',
            $result->method === MatchMethod::Countout => 'by count-out',
            $result->method === MatchMethod::Submission || $family === MatchFamily::Submission => 'by submission',
            $family !== null && isset(self::GIMMICK_FINISH[$family->value]) => self::GIMMICK_FINISH[$family->value],
            $result->finish_event === FinishEvent::CheapRollup => 'with a roll-up',
            default => 'with a pinfall',
        };
    }
}
