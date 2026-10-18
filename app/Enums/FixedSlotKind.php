<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Fixed match slots that booking tools leave alone (§24).
 */
enum FixedSlotKind: string
{
    use HasValues;

    case RoyalRumble = 'royal_rumble';
    case SurvivorSeriesMainEvent = 'survivor_series_main_event';
    case WrestlemaniaMainEvent = 'wrestlemania_main_event';
    case InterbrandSeries = 'interbrand_series';
    case TitleShot = 'title_shot';
    case MitbCashIn = 'mitb_cash_in';
}
