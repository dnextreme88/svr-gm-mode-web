<?php

namespace App\Services\Titles;

use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\TitleHolderType;
use App\Models\Championship;
use App\Models\Save;
use App\Models\TitleReign;
use App\Services\News\NewsRecorder;
use Illuminate\Support\Collection;

/**
 * Title records (§16): longest reign, most reigns, most combined defenses and
 * shortest reign, per title and across all titles. History never resets.
 */
class TitleRecords
{
    public function __construct(private NewsRecorder $news) {}

    /**
     * @return array{longest: array<string, mixed>|null, shortest: array<string, mixed>|null, most_reigns: array<string, mixed>|null, most_defenses: array<string, mixed>|null}
     */
    public function records(Save $save, ?Championship $championship = null): array
    {
        $reigns = $this->reigns($save, $championship);
        $week = $save->current_week;
        $closed = $reigns->whereNotNull('lost_week');

        $longest = $reigns->sortByDesc(fn (TitleReign $r): int => $r->weeksHeld($week))->first();
        $shortest = $closed->sortBy(fn (TitleReign $r): int => $r->weeksHeld($week))->first();

        $byHolder = $reigns->groupBy(fn (TitleReign $r): string => $r->holderName());
        $mostReigns = $byHolder->sortByDesc(fn (Collection $group): int => $group->count())->first();
        $mostDefenses = $byHolder->sortByDesc(fn (Collection $group): int => (int) $group->sum('defenses'))->first();

        return [
            'longest' => $longest === null ? null : $this->reignRow($longest, $week),
            'shortest' => $shortest === null ? null : $this->reignRow($shortest, $week),
            'most_reigns' => $mostReigns === null ? null : ['holder' => $mostReigns->first()->holderName(), 'holder_ids' => $mostReigns->first()->holderIds(), 'value' => $mostReigns->count()],
            'most_defenses' => $mostDefenses === null || (int) $mostDefenses->sum('defenses') === 0 ? null : ['holder' => $mostDefenses->first()->holderName(), 'holder_ids' => $mostDefenses->first()->holderIds(), 'value' => (int) $mostDefenses->sum('defenses')],
        ];
    }

    /**
     * After a reign ends, announce any per-title record it broke.
     */
    public function checkAfterReignEnded(TitleReign $ended, int $week): void
    {
        $others = $this->reigns($ended->game, $ended->championship)->where('id', '!=', $ended->id);

        if ($others->isEmpty()) {
            return;
        }

        $held = $ended->weeksHeld($week);
        $title = $ended->championship->name;

        if ($held > $others->max(fn (TitleReign $r): int => $r->weeksHeld($week))) {
            $this->announce($ended, "longest {$title} reign", $week);
        }

        if ($ended->defenses > 0 && $ended->defenses > $others->max('defenses')) {
            $this->announce($ended, "most {$title} defenses", $week);
        }
    }

    /**
     * After a new reign starts, announce a most-reigns record.
     */
    public function checkAfterReignStarted(TitleReign $started, int $week): void
    {
        $reigns = $this->reigns($started->game, $started->championship);
        $counts = $reigns->groupBy(fn (TitleReign $r): string => $r->holderName())->map->count();
        $mine = $counts->get($started->holderName(), 0);

        if ($mine > 1 && $mine > $counts->except($started->holderName())->max()) {
            $this->announce($started, "most {$started->championship->name} reigns", $week);
        }
    }

    /**
     * @return Collection<int, TitleReign>
     */
    private function reigns(Save $save, ?Championship $championship): Collection
    {
        return TitleReign::with('championship')
            ->where('save_id', $save->id)
            ->where('holder_type', '!=', TitleHolderType::Vacant)
            ->when($championship !== null, fn ($q) => $q->where('championship_id', $championship?->id))
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function reignRow(TitleReign $reign, int $week): array
    {
        return [
            'holder' => $reign->holderName(),
            'holder_ids' => $reign->holderIds(),
            'championship' => $reign->championship->name,
            'value' => $reign->weeksHeld($week),
            'won_week' => $reign->won_week,
            'active' => $reign->lost_week === null,
        ];
    }

    private function announce(TitleReign $reign, string $record, int $week): void
    {
        $this->news->record($reign->game, NewsEventType::RecordBroken, NewsTier::Headline, $reign->brand, [
            'wrestler' => $reign->holderName(),
            'record' => $record,
        ], ['championship_id' => $reign->championship_id, 'wrestler_ids' => $reign->holderIds()], priority: 65, week: $week);
    }
}
