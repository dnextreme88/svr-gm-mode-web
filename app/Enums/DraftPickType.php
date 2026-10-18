<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Manual or random pick (§11).
 */
enum DraftPickType: string
{
    use HasValues;

    case Manual = 'manual';
    case Random = 'random';
}
