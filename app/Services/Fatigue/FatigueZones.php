<?php

namespace App\Services\Fatigue;

use App\Enums\InjuryZone;

/**
 * Fatigue bars and injury zones (§6). Fatigue is 0–100 in steps of 10;
 * the zone is read from fatigue going into a match.
 */
class FatigueZones
{
    public static function zoneFor(int $fatigue): InjuryZone
    {
        if ($fatigue >= (int) config('gm.fatigue.serious_at')) {
            return InjuryZone::SeriousRisk;
        }

        if ($fatigue > (int) config('gm.fatigue.safe_max')) {
            return InjuryZone::MinorRisk;
        }

        return InjuryZone::Safe;
    }

    public static function bars(int $fatigue): int
    {
        return intdiv($fatigue, (int) config('gm.fatigue.bar_size'));
    }

    public static function cap(int $fatigue): int
    {
        return max(0, min((int) config('gm.fatigue.max'), $fatigue));
    }
}
