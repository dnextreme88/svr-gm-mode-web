<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Fatigue injury zone (§6).
 */
enum InjuryZone: string
{
    use HasValues;

    case Safe = 'safe';
    case MinorRisk = 'minor_risk';
    case SeriousRisk = 'serious_risk';
}
