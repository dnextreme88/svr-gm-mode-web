<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * News tier (§19). Ordered highest first.
 */
enum NewsTier: string
{
    use HasValues;

    case Headline = 'headline';
    case Story = 'story';
    case Ticker = 'ticker';
}
