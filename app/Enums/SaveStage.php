<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Where a save is in its lifecycle.
 */
enum SaveStage: string
{
    use HasValues;

    case Drafting = 'drafting';
    case AssigningTitles = 'assigning_titles';
    case InSeason = 'in_season';
    case Redrafting = 'redrafting';
}
