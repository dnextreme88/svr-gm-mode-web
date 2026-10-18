<?php

namespace App\Services\Injuries;

use App\Enums\InjurySeverity;
use App\Models\Injury;
use App\Models\SaveWrestler;

/**
 * Read-side injury checks used by booking, titles and morale (§6).
 */
class InjuryStatus
{
    public function active(SaveWrestler $wrestler, int $week): ?Injury
    {
        return Injury::with('type')
            ->where('save_wrestler_id', $wrestler->id)
            ->whereNull('healed_week')
            ->where('started_week', '<=', $week)
            ->where('ends_week', '>', $week)
            ->latest('id')
            ->first();
    }

    public function isSeriouslyInjured(SaveWrestler $wrestler, int $week): bool
    {
        return $this->active($wrestler, $week)?->severity === InjurySeverity::Serious;
    }

    public function isMinorInjured(SaveWrestler $wrestler, int $week): bool
    {
        return $this->active($wrestler, $week)?->severity === InjurySeverity::Minor;
    }

    /**
     * Ids of seriously injured wrestlers in a save, for bulk booking checks.
     *
     * @return list<int>
     */
    public function seriouslyInjuredIds(int $saveId, int $week): array
    {
        return array_values(array_map('intval', Injury::where('save_id', $saveId)
            ->whereNull('healed_week')
            ->where('severity', InjurySeverity::Serious)
            ->where('started_week', '<=', $week)
            ->where('ends_week', '>', $week)
            ->pluck('save_wrestler_id')
            ->unique()
            ->all()));
    }

    /**
     * Active injuries keyed by save_wrestler_id.
     *
     * @return array<int, Injury>
     */
    public function activeByWrestler(int $saveId, int $week): array
    {
        return Injury::with('type')
            ->where('save_id', $saveId)
            ->whereNull('healed_week')
            ->where('started_week', '<=', $week)
            ->where('ends_week', '>', $week)
            ->orderBy('id')
            ->get()
            ->keyBy('save_wrestler_id')
            ->all();
    }
}
