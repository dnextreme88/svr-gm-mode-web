<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Injury severity (§6).
 */
enum InjurySeverity: string
{
    use HasValues;

    case Minor = 'minor';
    case Serious = 'serious';
}
