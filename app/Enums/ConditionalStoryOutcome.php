<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Whether a conditional story's spot worked (house rule, 2026-11-07).
 */
enum ConditionalStoryOutcome: string
{
    use HasValues;

    case Success = 'success';
    case Failure = 'failure';
}
