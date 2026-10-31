<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Where a title shot came from: a Champion vs. Contender win (due on the
 * next show) or a #1 Contender's Match win (house rule; no deadline, and
 * the title can only be defended against those contenders until then).
 */
enum TitleShotKind: string
{
    use HasValues;

    case ChampionVsContender = 'champion_vs_contender';
    case NumberOneContender = 'number_one_contender';
}
