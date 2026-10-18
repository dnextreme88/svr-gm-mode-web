<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Where a draft pick came from (§11).
 */
enum DraftPickSource: string
{
    use HasValues;

    case FreeAgency = 'free_agency';
    case RivalRoster = 'rival_roster';
}
