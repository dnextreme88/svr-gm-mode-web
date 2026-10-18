<?php

namespace App\Services\Roster;

use App\Enums\Brand;
use App\Models\PendingRosterMove;
use App\Models\Save;
use App\Models\SaveWrestler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Roster membership and the 30-wrestler cap (§1).
 */
class RosterService
{
    public function cap(): int
    {
        return (int) config('gm.roster_cap');
    }

    /**
     * @return Collection<int, SaveWrestler>
     */
    public function roster(Save $save, Brand $brand): Collection
    {
        return SaveWrestler::with(['wrestler.specialties', 'activeContract'])
            ->where('save_id', $save->id)
            ->where('brand', $brand)
            ->get()
            ->sortBy(fn (SaveWrestler $w): string => $w->wrestler->name)
            ->values();
    }

    /**
     * Signed wrestlers plus Free Agency signings that start next week.
     */
    public function rosterCount(Save $save, Brand $brand): int
    {
        return SaveWrestler::where('save_id', $save->id)->where('brand', $brand)->count()
            + $this->pendingCount($save, $brand);
    }

    public function pendingCount(Save $save, Brand $brand): int
    {
        return PendingRosterMove::where('save_id', $save->id)->where('brand', $brand)->whereNull('applied_week')->count();
    }

    public function hasRoom(Save $save, Brand $brand, int $adding = 1): bool
    {
        return $this->rosterCount($save, $brand) + $adding <= $this->cap();
    }

    /**
     * Free agents available to sign this week: unsigned, not already pending,
     * and past any Loser Leaves Show re-sign lockout (§22).
     *
     * @return Builder<SaveWrestler>
     */
    public function freeAgentsQuery(Save $save, int $week): Builder
    {
        return SaveWrestler::query()
            ->where('save_id', $save->id)
            ->whereNull('brand')
            ->whereNotIn('id', PendingRosterMove::where('save_id', $save->id)->whereNull('applied_week')->select('save_wrestler_id'))
            ->where(fn (Builder $q) => $q->whereNull('unsignable_until_week')->orWhere('unsignable_until_week', '<=', $week));
    }

    public function isPending(SaveWrestler $wrestler): bool
    {
        return PendingRosterMove::where('save_wrestler_id', $wrestler->id)->whereNull('applied_week')->exists();
    }
}
