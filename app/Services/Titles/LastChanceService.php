<?php

namespace App\Services\Titles;

use App\Enums\ShowStatus;
use App\Models\LastChanceLockout;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\TitleReign;

/**
 * Last Chance availability and lockouts (§22). Eligibility is computed from
 * the current reign's title matches and their participants.
 */
class LastChanceService
{
    /**
     * Title matches in the current reign with exactly these two sides. Within
     * a reign every title match is a champion win (a decisive contender win
     * would have ended it; DQ and count-out wins count as champion wins).
     *
     * @param  list<int>  $championIds
     * @param  list<int>  $contenderIds
     */
    public function qualifyingMeetings(TitleReign $reign, array $championIds, array $contenderIds): int
    {
        $champions = $this->sorted($championIds);
        $contenders = $this->sorted($contenderIds);

        return MatchSlot::with('participants')
            ->where('title_championship_id', $reign->championship_id)
            ->whereHas('result')
            ->whereHas('show', fn ($q) => $q->where('save_id', $reign->save_id)
                ->where('status', ShowStatus::Aired)
                ->where('week_number', '>=', $reign->won_week))
            ->when($reign->won_match_id !== null, fn ($q) => $q->where('id', '!=', $reign->won_match_id))
            ->get()
            ->filter(function (MatchSlot $slot) use ($champions, $contenders): bool {
                $sides = array_values(array_map(fn (array $ids): array => $this->sorted($ids), $slot->sides()));

                if (count($sides) !== 2) {
                    return false;
                }

                return ($sides[0] === $champions && $sides[1] === $contenders)
                    || ($sides[1] === $champions && $sides[0] === $contenders);
            })
            ->count();
    }

    /**
     * @param  list<int>  $contenderIds
     */
    public function isAvailable(TitleReign $reign, array $contenderIds): bool
    {
        return $this->qualifyingMeetings($reign, $reign->holderIds(), $contenderIds) >= (int) config('gm.stipulations.last_chance_min_meetings');
    }

    /**
     * Every contender (or contending team) that can be booked in a Last Chance match now.
     *
     * @return list<array{championship_id: int, reign_id: int, champion_ids: list<int>, contender_ids: list<int>}>
     */
    public function availableFor(Save $save, TitleReign $reign): array
    {
        $groups = [];

        $slots = MatchSlot::with('participants')
            ->where('title_championship_id', $reign->championship_id)
            ->whereHas('result')
            ->whereHas('show', fn ($q) => $q->where('save_id', $save->id)->where('week_number', '>=', $reign->won_week))
            ->get();

        $champions = $this->sorted($reign->holderIds());

        foreach ($slots as $slot) {
            $sides = array_values(array_map(fn (array $ids): array => $this->sorted($ids), $slot->sides()));

            if (count($sides) !== 2) {
                continue;
            }

            $contenders = $sides[0] === $champions ? $sides[1] : ($sides[1] === $champions ? $sides[0] : null);

            if ($contenders !== null) {
                $groups[implode(',', $contenders)] = $contenders;
            }
        }

        $available = [];

        foreach ($groups as $contenders) {
            if ($this->isAvailable($reign, $contenders) && ! $this->isLockedOut($reign, $contenders)) {
                $available[] = [
                    'championship_id' => $reign->championship_id,
                    'reign_id' => $reign->id,
                    'champion_ids' => $champions,
                    'contender_ids' => $contenders,
                ];
            }
        }

        return $available;
    }

    /**
     * @param  list<int>  $wrestlerIds
     */
    public function lockOut(TitleReign $reign, array $wrestlerIds, int $week, ?int $slotId = null): LastChanceLockout
    {
        return LastChanceLockout::create([
            'save_id' => $reign->save_id,
            'championship_id' => $reign->championship_id,
            'title_reign_id' => $reign->id,
            'wrestler_ids' => $this->sorted($wrestlerIds),
            'match_slot_id' => $slotId,
            'created_week' => $week,
        ]);
    }

    /**
     * Whether any of these wrestlers is locked out of this reign's title matches.
     * Lockouts lapse automatically once the reign ends.
     *
     * @param  list<int>  $wrestlerIds
     */
    public function isLockedOut(TitleReign $reign, array $wrestlerIds): bool
    {
        if ($reign->lost_week !== null) {
            return false;
        }

        return LastChanceLockout::where('title_reign_id', $reign->id)
            ->get()
            ->contains(fn (LastChanceLockout $l): bool => array_intersect($l->wrestler_ids, $wrestlerIds) !== []);
    }

    /**
     * Locked-out wrestler ids per championship for the current reigns.
     *
     * @return array<int, list<int>>
     */
    public function lockedIds(Save $save): array
    {
        $locked = [];

        foreach (LastChanceLockout::with('reign')->where('save_id', $save->id)->get() as $lockout) {
            if ($lockout->reign->lost_week === null) {
                $locked[$lockout->championship_id] = array_values(array_unique([...($locked[$lockout->championship_id] ?? []), ...$lockout->wrestler_ids]));
            }
        }

        return $locked;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
