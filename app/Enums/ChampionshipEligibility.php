<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Championship eligibility rule (§11, §14).
 */
enum ChampionshipEligibility: string
{
    use HasValues;

    case Open = 'open';
    case DivasOnly = 'divas_only';
    case CruiserweightOrLightHeavyweight = 'cruiserweight_or_light_heavyweight';
}
