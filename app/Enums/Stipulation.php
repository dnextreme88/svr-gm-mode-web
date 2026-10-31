<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use Illuminate\Support\Str;

/**
 * Special stipulation (§22).
 */
enum Stipulation: string
{
    use HasValues;

    case ChampionVsContender = 'champion_vs_contender';
    case LoserLeavesShow = 'loser_leaves_show';
    case LastChance = 'last_chance';
    // House rules (2026-10-31).
    case WinnerTakesAll = 'winner_takes_all';
    case NumberOneContender = 'number_one_contender';

    public function label(): string
    {
        return match ($this) {
            self::ChampionVsContender => 'Champion vs. Contender',
            self::LastChance => 'Last Chance Match',
            self::NumberOneContender => '#1 Contender\'s Match',
            default => Str::of($this->name)->headline()->toString(),
        };
    }
}
