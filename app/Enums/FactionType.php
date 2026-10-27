<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Faction type (house rule): a tag team or a stable.
 */
enum FactionType: string
{
    use HasValues;

    case TagTeam = 'tag_team';
    case Stable = 'stable';
}
