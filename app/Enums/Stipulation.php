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

    public function label(): string
    {
        return match ($this) {
            self::ChampionVsContender => 'Champion vs. Contender',
            self::LastChance => 'Last Chance Match',
            default => Str::of($this->name)->headline()->toString(),
        };
    }
}
