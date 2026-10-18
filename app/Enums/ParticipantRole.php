<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Match participant role (§4).
 */
enum ParticipantRole: string
{
    use HasValues;

    case Competitor = 'competitor';
    case Manager = 'manager';
}
