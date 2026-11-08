<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Where a Rematch Clause stands (house rule, 2026-11-07).
 */
enum RematchClauseStatus: string
{
    use HasValues;

    case Pending = 'pending';
    /** The former and new champions met in a title match for it. */
    case Resolved = 'resolved';
    /** Ended early: someone left the brand or was seriously injured, or the title moved on. */
    case Voided = 'voided';
}
