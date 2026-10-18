<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Roster type (§11).
 */
enum RosterType: string
{
    use HasValues;

    case Superstar = 'superstar';
    case Diva = 'diva';
    case Legend = 'legend';
}
