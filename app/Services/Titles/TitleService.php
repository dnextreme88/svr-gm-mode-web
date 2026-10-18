<?php

namespace App\Services\Titles;

use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\ReignEndReason;
use App\Enums\TitleHolderType;
use App\Enums\WonVia;
use App\Models\Championship;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Models\TagTeam;
use App\Models\TitleReign;
use App\Services\Calendar\CalendarService;
use App\Services\News\NewsRecorder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Title lineage: reigns, vacancies and current champions (§16).
 *
 * A lineage row is current while `lost_week` is null. Vacant rows carry the
 * reason the title became vacant in `end_reason`.
 */
class TitleService
{
    public function __construct(
        private CalendarService $calendar,
        private NewsRecorder $news,
    ) {}

    public function currentRow(Save $save, Championship $championship): ?TitleReign
    {
        return TitleReign::where('save_id', $save->id)
            ->where('championship_id', $championship->id)
            ->whereNull('lost_week')
            ->latest('id')
            ->first();
    }

    /**
     * The current holder reign, or null when vacant or never assigned.
     */
    public function currentReign(Save $save, Championship $championship): ?TitleReign
    {
        $row = $this->currentRow($save, $championship);

        return $row !== null && ! $row->isVacant() ? $row : null;
    }

    /**
     * Every current (non-vacant) reign in the save, keyed by championship id.
     *
     * @return Collection<int, TitleReign>
     */
    public function currentReigns(Save $save): Collection
    {
        return TitleReign::with('championship')
            ->where('save_id', $save->id)
            ->whereNull('lost_week')
            ->where('holder_type', '!=', TitleHolderType::Vacant)
            ->get()
            ->keyBy('championship_id');
    }

    /**
     * Current reigns this wrestler is part of (singles or as a tag team member).
     *
     * @return Collection<int, TitleReign>
     */
    public function reignsHeldBy(SaveWrestler $wrestler): Collection
    {
        return TitleReign::with('championship')
            ->where('save_id', $wrestler->save_id)
            ->whereNull('lost_week')
            ->where('holder_type', '!=', TitleHolderType::Vacant)
            ->get()
            ->filter(fn (TitleReign $reign): bool => in_array($wrestler->id, $reign->holderIds(), true))
            ->values();
    }

    public function isChampion(SaveWrestler $wrestler): bool
    {
        return $this->reignsHeldBy($wrestler)->isNotEmpty();
    }

    /**
     * @return list<string>
     */
    public function titlesHeldBy(SaveWrestler $wrestler): array
    {
        return array_values($this->reignsHeldBy($wrestler)->map(fn (TitleReign $r): string => $r->championship->name)->all());
    }

    /**
     * Start a new reign on a vacant (or never-assigned) title.
     */
    public function award(
        Save $save,
        Championship $championship,
        SaveWrestler|TagTeam $holder,
        int $week,
        WonVia $via = WonVia::Awarded,
        ?Show $show = null,
        ?int $matchId = null,
    ): TitleReign {
        $current = $this->currentRow($save, $championship);

        if ($current !== null && ! $current->isVacant()) {
            throw new InvalidArgumentException("{$championship->name} is not vacant.");
        }

        if ($current !== null) {
            $current->update(['lost_week' => $week, 'lost_show_id' => $show?->id, 'lost_match_id' => $matchId]);
        }

        return $this->openReign($save, $championship, $holder, $week, $via, $show, $matchId);
    }

    /**
     * End the current reign in a match and start the winner's (§16).
     */
    public function changeHands(Save $save, Championship $championship, SaveWrestler|TagTeam $newHolder, int $week, WonVia $via, ?Show $show, ?int $matchId): TitleReign
    {
        $current = $this->currentRow($save, $championship);

        if ($current !== null) {
            $current->update([
                'lost_week' => $week,
                'lost_show_id' => $show?->id,
                'lost_match_id' => $matchId,
                'end_reason' => $current->isVacant() ? $current->end_reason : ReignEndReason::LostMatch,
            ]);
        }

        if ($current?->isVacant() && $via === WonVia::Match) {
            $via = WonVia::VacancyMatch;
        }

        return $this->openReign($save, $championship, $newHolder, $week, $via, $show, $matchId);
    }

    /**
     * Close the current reign with a reason and open a vacant lineage row (§16).
     */
    public function vacate(Save $save, Championship $championship, ReignEndReason $reason, int $week, ?Show $show = null): ?TitleReign
    {
        $current = $this->currentReign($save, $championship);

        if ($current === null) {
            return null;
        }

        $current->update([
            'lost_week' => $week,
            'lost_show_id' => $show?->id,
            'end_reason' => $reason,
        ]);

        $vacant = TitleReign::create([
            'save_id' => $save->id,
            'championship_id' => $championship->id,
            'brand' => $championship->brand,
            'reign_number' => null,
            'holder_type' => TitleHolderType::Vacant,
            'holder_snapshot' => ['names' => [], 'ids' => []],
            'season' => $this->calendar->seasonOf($week),
            'won_week' => $week,
            'won_via' => null,
            'end_reason' => $reason,
        ]);

        $this->news->record($save, NewsEventType::TitleVacated, NewsTier::Headline, $championship->brand, [
            'title' => $championship->name,
            'reason' => $this->reasonText($reason, $current->holderName()),
        ], ['championship_id' => $championship->id, 'wrestler_ids' => $current->holderIds()], priority: 80, week: $week);

        return $vacant;
    }

    /**
     * Vacate every title a wrestler holds, including tag titles (§16).
     *
     * @return list<string> names of vacated titles
     */
    public function vacateFor(SaveWrestler $wrestler, ReignEndReason $reason, int $week): array
    {
        $vacated = [];
        $save = $wrestler->game;

        foreach ($this->reignsHeldBy($wrestler) as $reign) {
            $this->vacate($save, $reign->championship, $reason, $week);
            $vacated[] = $reign->championship->name;
        }

        return $vacated;
    }

    /**
     * Record a vacant row for a title nobody was assigned (e.g. no eligible wrestler).
     */
    public function leaveVacant(Save $save, Championship $championship, int $week): TitleReign
    {
        return TitleReign::create([
            'save_id' => $save->id,
            'championship_id' => $championship->id,
            'brand' => $championship->brand,
            'reign_number' => null,
            'holder_type' => TitleHolderType::Vacant,
            'holder_snapshot' => ['names' => [], 'ids' => []],
            'season' => $this->calendar->seasonOf($week),
            'won_week' => $week,
            'won_via' => null,
            'end_reason' => ReignEndReason::Active,
        ]);
    }

    /**
     * @return array{names: list<string>, ids: list<int>}
     */
    public function snapshot(SaveWrestler|TagTeam $holder): array
    {
        if ($holder instanceof SaveWrestler) {
            return ['names' => [$holder->name()], 'ids' => [$holder->id]];
        }

        $members = $holder->members()->with('wrestler')->get();

        return [
            'names' => array_values($members->map(fn (SaveWrestler $m): string => $m->name())->all()),
            'ids' => array_values(array_map('intval', $members->pluck('id')->all())),
        ];
    }

    public function reasonText(ReignEndReason $reason, string $holder): string
    {
        return match ($reason) {
            ReignEndReason::Defected => "{$holder} defected",
            ReignEndReason::Injured => "{$holder} is injured",
            ReignEndReason::LoserLeaves => "{$holder} lost a Loser Leaves Show match",
            ReignEndReason::Released => "{$holder} was released",
            ReignEndReason::ContractExpired => "{$holder}'s contract expired",
            ReignEndReason::LostMatch, ReignEndReason::Active => $holder,
        };
    }

    private function openReign(Save $save, Championship $championship, SaveWrestler|TagTeam $holder, int $week, WonVia $via, ?Show $show, ?int $matchId): TitleReign
    {
        $number = (int) TitleReign::where('save_id', $save->id)
            ->where('championship_id', $championship->id)
            ->where('holder_type', '!=', TitleHolderType::Vacant)
            ->max('reign_number') + 1;

        return TitleReign::create([
            'save_id' => $save->id,
            'championship_id' => $championship->id,
            'brand' => $championship->brand,
            'reign_number' => $number,
            'holder_type' => $holder instanceof TagTeam ? TitleHolderType::TagTeam : TitleHolderType::Wrestler,
            'save_wrestler_id' => $holder instanceof SaveWrestler ? $holder->id : null,
            'tag_team_id' => $holder instanceof TagTeam ? $holder->id : null,
            'holder_snapshot' => $this->snapshot($holder),
            'season' => $this->calendar->seasonOf($week),
            'won_week' => $week,
            'won_show_id' => $show?->id,
            'won_match_id' => $matchId,
            'won_via' => $via,
            'end_reason' => ReignEndReason::Active,
            'defenses' => 0,
        ]);
    }
}
