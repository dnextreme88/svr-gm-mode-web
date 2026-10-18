<?php

namespace App\Services\Morale;

use App\Enums\ComplaintLevel;
use App\Enums\Morale;
use App\Enums\MoraleComplaintType;
use App\Models\MoraleComplaint;
use App\Models\SaveWrestler;

/**
 * Turns active complaints into a morale state (§15): Happy with none,
 * otherwise the worst active complaint level.
 */
class MoraleCalculator
{
    public function moraleFor(SaveWrestler $wrestler): Morale
    {
        $levels = $wrestler->activeComplaints()->get()->map(fn (MoraleComplaint $c): ComplaintLevel => $c->level);

        if ($levels->isEmpty()) {
            return Morale::Happy;
        }

        $worst = $levels->sortByDesc(fn (ComplaintLevel $level): int => $this->rank($level))->first();

        return Morale::from($worst->value);
    }

    public function recalculate(SaveWrestler $wrestler): Morale
    {
        $morale = $this->moraleFor($wrestler);
        $wrestler->update(['morale' => $morale]);

        return $morale;
    }

    /**
     * Close an open complaint and recompute morale from what's left (§15).
     */
    public function resolve(SaveWrestler $wrestler, MoraleComplaintType $type, int $week): bool
    {
        $closed = $wrestler->activeComplaints()->where('type', $type)->update(['resolved_week' => $week]);
        $this->recalculate($wrestler);

        return $closed > 0;
    }

    /**
     * Reset a wrestler to Happy with no complaints (new signings and draftees, §15).
     */
    public function startHappy(SaveWrestler $wrestler, int $week): void
    {
        $wrestler->activeComplaints()->update(['resolved_week' => $week]);
        $wrestler->update(['morale' => Morale::Happy]);
    }

    public function rank(ComplaintLevel $level): int
    {
        return match ($level) {
            ComplaintLevel::Content => 1,
            ComplaintLevel::Unsatisfied => 2,
            ComplaintLevel::Upset => 3,
        };
    }
}
