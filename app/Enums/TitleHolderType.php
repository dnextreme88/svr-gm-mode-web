<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Title reign holder type (§16).
 */
enum TitleHolderType: string
{
    use HasValues;

    case Wrestler = 'wrestler';
    case TagTeam = 'tag_team';
    case Vacant = 'vacant';
}
