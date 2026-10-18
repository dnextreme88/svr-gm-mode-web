<?php

namespace App\Services\Titles;

use App\Enums\Brand;
use App\Enums\TitleShotStatus;
use App\Models\Championship;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\TitleShot;
use App\Services\Injuries\InjuryStatus;
use Illuminate\Database\Eloquent\Collection;

/**
 * Champion vs. Contender title-shot obligations (§22).
 */
class TitleShotService
{
    public function __construct(
        private InjuryStatus $injuries,
        private TitleService $titles,
    ) {}

    /**
     * ASSUMPTION: "the next weekly show or PPV, whichever comes first" is the
     * first card after the result is known, i.e. next week's weekly show.
     *
     * @param  list<int>  $contenderIds
     * @param  list<int>  $championIds
     */
    public function create(Save $save, Championship $championship, array $contenderIds, array $championIds, MatchSlot $slot, int $week): TitleShot
    {
        return TitleShot::create([
            'save_id' => $save->id,
            'championship_id' => $championship->id,
            'brand' => $championship->brand,
            'contender_ids' => $contenderIds,
            'champion_ids' => $championIds,
            'earned_match_id' => $slot->id,
            'earned_week' => $week,
            'due_week' => $week + 1,
            'status' => TitleShotStatus::Pending,
        ]);
    }

    /**
     * Pending shots for a brand that are due by this week. A contender who is
     * seriously injured pushes the shot to the first show after they recover;
     * shots on titles that became vacant are voided.
     *
     * @return Collection<int, TitleShot>
     */
    public function due(Save $save, Brand $brand, int $week): Collection
    {
        $due = new Collection;

        foreach (TitleShot::with('championship')->where('save_id', $save->id)->where('brand', $brand)->where('status', TitleShotStatus::Pending)->orderBy('id')->get() as $shot) {
            if ($this->titles->currentReign($save, $shot->championship) === null) {
                $shot->update(['status' => TitleShotStatus::Voided, 'resolved_week' => $week]);

                continue;
            }

            if ($shot->due_week > $week) {
                continue;
            }

            $delayUntil = $this->injuryDelay($shot, $week);

            if ($delayUntil !== null) {
                $shot->update(['due_week' => $delayUntil]);

                continue;
            }

            $due->push($shot);
        }

        return $due;
    }

    /**
     * Void pending shots on a title that just became vacant (§22, to confirm).
     */
    public function voidFor(Championship $championship, Save $save, int $week): void
    {
        TitleShot::where('save_id', $save->id)
            ->where('championship_id', $championship->id)
            ->where('status', TitleShotStatus::Pending)
            ->update(['status' => TitleShotStatus::Voided, 'resolved_week' => $week]);
    }

    /**
     * Mark shots fulfilled when the title match airs with the contender(s) in it.
     */
    public function fulfil(MatchSlot $slot, int $week): void
    {
        if ($slot->title_championship_id === null) {
            return;
        }

        $competitors = $slot->competitorIds();

        TitleShot::where('championship_id', $slot->title_championship_id)
            ->where('status', TitleShotStatus::Pending)
            ->whereHas('game', fn ($q) => $q->whereKey($slot->show->save_id))
            ->get()
            ->filter(fn (TitleShot $shot): bool => array_diff($shot->contender_ids, $competitors) === [])
            ->each(fn (TitleShot $shot) => $shot->update([
                'status' => TitleShotStatus::Fulfilled,
                'fulfilled_match_id' => $slot->id,
                'resolved_week' => $week,
            ]));
    }

    /**
     * Whether a card already books this shot as a title match.
     *
     * @param  Collection<int, MatchSlot>  $slots
     */
    public function isBooked(TitleShot $shot, Collection $slots): bool
    {
        return $slots->contains(fn (MatchSlot $s): bool => $s->title_championship_id === $shot->championship_id
            && array_diff($shot->contender_ids, $s->competitorIds()) === []);
    }

    private function injuryDelay(TitleShot $shot, int $week): ?int
    {
        $latest = null;

        foreach (SaveWrestler::whereIn('id', $shot->contender_ids)->get() as $contender) {
            $injury = $this->injuries->active($contender, $week);

            if ($injury !== null && $this->injuries->isSeriouslyInjured($contender, $week)) {
                $latest = max($latest ?? 0, $injury->ends_week);
            }
        }

        return $latest;
    }
}
