<?php

namespace App\Services\Attendance;

use App\Enums\FixedSlotKind;
use App\Enums\MatchFamily;
use App\Enums\ShowType;
use App\Models\MatchSlot;
use App\Models\Show;
use Illuminate\Support\Collection;

/**
 * Repeat matches (house rule, 2026-11-08): on a brand's weekly show, a match
 * slot booked in the same match category (any variant) as that slot on the
 * brand's weekly show the week before. Fans tune out, so each costs
 * attendance (`gm.attendance.points.repeat_slot`). Only last week counts,
 * and PPVs and interbrand cards never do.
 */
class RepeatMatches
{
    /**
     * The repeated slots of a weekly show, in card order.
     *
     * @return list<array{position: int, family: string, label: string}>
     */
    public function forShow(Show $show): array
    {
        $lastWeek = $this->families($this->lastWeek($show));

        if ($lastWeek === []) {
            return [];
        }

        $repeats = [];

        foreach ($this->families($show) as $position => $family) {
            if (($lastWeek[$position] ?? null) === $family) {
                $repeats[] = ['position' => $position, 'family' => $family->value, 'label' => $family->label()];
            }
        }

        return $repeats;
    }

    /**
     * What each slot of a show is (booked slots only), by position. Empty
     * for anything but a brand's weekly show.
     *
     * @return array<int, MatchFamily>
     */
    public function families(?Show $show): array
    {
        if ($show === null || $show->show_type !== ShowType::Weekly || $show->brand === null) {
            return [];
        }

        return $this->slots($show)
            ->filter(fn (MatchSlot $s): bool => $s->matchType !== null)
            ->mapWithKeys(fn (MatchSlot $s): array => [(int) $s->position => $s->matchType->family])
            ->all();
    }

    /**
     * The brand's weekly show the week before, if there was one.
     */
    public function lastWeek(Show $show): ?Show
    {
        if ($show->show_type !== ShowType::Weekly || $show->brand === null) {
            return null;
        }

        return Show::where('save_id', $show->save_id)
            ->where('brand', $show->brand)
            ->where('show_type', ShowType::Weekly)
            ->where('week_number', $show->week_number - 1)
            ->first();
    }

    /**
     * @return Collection<int, MatchSlot>
     */
    private function slots(Show $show): Collection
    {
        return MatchSlot::with('matchType')
            ->where('show_id', $show->id)
            ->where(fn ($q) => $q->whereNull('fixed_kind')->orWhere('fixed_kind', '!=', FixedSlotKind::MitbCashIn))
            ->orderBy('position')
            ->get()
            ->toBase();
    }
}
