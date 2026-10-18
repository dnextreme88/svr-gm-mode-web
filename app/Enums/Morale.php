<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Four-tier morale (§15). Ordered best to worst.
 */
enum Morale: string
{
    use HasValues;

    case Happy = 'happy';
    case Content = 'content';
    case Unsatisfied = 'unsatisfied';
    case Upset = 'upset';
}
