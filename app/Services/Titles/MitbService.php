<?php

namespace App\Services\Titles;

use App\Enums\MitbStatus;
use App\Models\MoneyInTheBank;
use App\Models\Save;
use App\Models\SaveWrestler;
use Illuminate\Database\Eloquent\Collection;

/**
 * Money in the Bank briefcases (§10).
 */
class MitbService
{
    public function heldBy(SaveWrestler $wrestler): ?MoneyInTheBank
    {
        return MoneyInTheBank::where('save_wrestler_id', $wrestler->id)
            ->where('status', MitbStatus::Held)
            ->first();
    }

    /**
     * @return Collection<int, MoneyInTheBank>
     */
    public function held(Save $save): Collection
    {
        return MoneyInTheBank::with('holder.wrestler')
            ->where('save_id', $save->id)
            ->where('status', MitbStatus::Held)
            ->get();
    }

    /**
     * Void a briefcase when its holder leaves the brand (trade, defection,
     * re-draft, Loser Leaves Show, release or expiry).
     */
    public function voidFor(SaveWrestler $wrestler, string $reason, int $week): ?MoneyInTheBank
    {
        $briefcase = $this->heldBy($wrestler);

        $briefcase?->update([
            'status' => MitbStatus::Voided,
            'resolved_week' => $week,
            'resolution_note' => $reason,
        ]);

        return $briefcase;
    }
}
