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
    // House rule (2026-11-17): Match 5 on the weekly shows before Survivor
    // Series and WrestleMania, whose winner takes a team or ladder spot.
    case QualifyingMatch = 'qualifying_match';
}
