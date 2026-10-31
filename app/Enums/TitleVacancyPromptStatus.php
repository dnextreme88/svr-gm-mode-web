<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A vacated title with a #1 contender waiting (house rule): the player
 * awards it to the contender(s) or forfeits the contendership.
 */
enum TitleVacancyPromptStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Awarded = 'awarded';
    case Forfeited = 'forfeited';
}
