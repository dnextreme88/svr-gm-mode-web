<?php

namespace App\Services\Titles;

use App\Enums\ChampionshipEligibility;
use App\Enums\TitleShotKind;
use App\Enums\TitleShotStatus;
use App\Models\Championship;
use App\Models\Save;
use App\Models\TitleReign;
use App\Models\TitleShot;
use App\Services\Card\Booking;
use Illuminate\Support\Collection;

/**
 * Who holds what, for the title stipulations (house rule, 2026-10-31):
 * the titles a Winner Takes All match contests, whether a contender
 * already holds a title, and the pending title shots a booking must
 * respect.
 */
class TitleContention
{
    public function __construct(private TitleService $titles) {}

    /**
     * Titles Winner Takes All can contest: singles titles, never a tag
     * title or the Women's title (the Divas' only title).
     */
    public static function contestable(Championship $championship): bool
    {
        return ! $championship->is_tag && $championship->eligibility !== ChampionshipEligibility::DivasOnly;
    }

    /**
     * Every current reign a wrestler is part of, singles or tag.
     *
     * @return Collection<int, TitleReign>
     */
    public function reignsOf(Save $save, int $wrestlerId): Collection
    {
        return $this->titles->currentReigns($save)
            ->filter(fn (TitleReign $reign): bool => in_array($wrestlerId, $reign->holderIds(), true))
            ->values();
    }

    public function holdsAnyTitle(Save $save, int $wrestlerId): bool
    {
        return $this->reignsOf($save, $wrestlerId)->isNotEmpty();
    }

    /**
     * The contestable titles a wrestler holds, in title order.
     *
     * @return Collection<int, Championship>
     */
    public function contestableHeld(Save $save, int $wrestlerId): Collection
    {
        return $this->reignsOf($save, $wrestlerId)
            ->map(fn (TitleReign $reign): Championship => $reign->championship)
            ->filter(fn (Championship $c): bool => self::contestable($c))
            ->sortBy('sort_order')
            ->values();
    }

    /**
     * The titles a Winner Takes All booking puts on the line: every
     * contestable title its competitors hold, in title order.
     *
     * @return list<int>
     */
    public function contested(Save $save, Booking $booking): array
    {
        return array_values(collect($booking->competitorIds())
            ->flatMap(fn (int $id): Collection => $this->contestableHeld($save, $id))
            ->unique('id')
            ->sortBy('sort_order')
            ->map(fn (Championship $c): int => $c->id)
            ->all());
    }

    /**
     * A title's pending #1 contendership, if it has one.
     */
    public function contendership(Save $save, int $championshipId): ?TitleShot
    {
        return $this->pending($save, $championshipId, TitleShotKind::NumberOneContender);
    }

    /**
     * A title's pending Champion vs. Contender shot, if it has one.
     */
    public function earnedShot(Save $save, int $championshipId): ?TitleShot
    {
        return $this->pending($save, $championshipId, TitleShotKind::ChampionVsContender);
    }

    /**
     * Every pending #1 contendership in the save, keyed by championship id.
     *
     * @return Collection<int, TitleShot>
     */
    public function contenderships(Save $save): Collection
    {
        return TitleShot::with('championship')
            ->where('save_id', $save->id)
            ->where('kind', TitleShotKind::NumberOneContender)
            ->where('status', TitleShotStatus::Pending)
            ->orderBy('id')
            ->get()
            ->keyBy('championship_id');
    }

    private function pending(Save $save, int $championshipId, TitleShotKind $kind): ?TitleShot
    {
        return TitleShot::where('save_id', $save->id)
            ->where('championship_id', $championshipId)
            ->where('kind', $kind)
            ->where('status', TitleShotStatus::Pending)
            ->orderBy('id')
            ->first();
    }
}
