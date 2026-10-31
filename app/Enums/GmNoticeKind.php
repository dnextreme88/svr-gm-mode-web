<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * One-off notices shown to the GM in a modal (house rule).
 */
enum GmNoticeKind: string
{
    use HasValues;

    case ContendershipVoided = 'contendership_voided';
}
