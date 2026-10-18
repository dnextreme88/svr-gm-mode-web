<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Morale complaint level (§11, §15). Ordered mildest first.
 */
enum ComplaintLevel: string
{
    use HasValues;

    case Content = 'content';
    case Unsatisfied = 'unsatisfied';
    case Upset = 'upset';
}
