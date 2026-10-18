<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Six-tier weight class (§12).
 */
enum WeightClass: string
{
    use HasValues;

    case Featherweight = 'featherweight';
    case Cruiserweight = 'cruiserweight';
    case LightHeavyweight = 'light_heavyweight';
    case Heavyweight = 'heavyweight';
    case SuperHeavyweight = 'super_heavyweight';
    case UltraHeavyweight = 'ultra_heavyweight';
}
