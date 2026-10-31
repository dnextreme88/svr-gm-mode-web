<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * When a Money in the Bank briefcase was cashed in (house rule, 2026-10-31):
 * before the show (its own title match on the card), during a world title
 * match (the holder joins it), or right after one (a new match follows it).
 */
enum MitbCashInTiming: string
{
    use HasValues;

    case Before = 'before';
    case During = 'during';
    case After = 'after';
}
