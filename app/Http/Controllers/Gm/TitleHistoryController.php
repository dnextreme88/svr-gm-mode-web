<?php

namespace App\Http\Controllers\Gm;

use App\Enums\ParticipantRole;
use App\Enums\TitleHolderType;
use App\Enums\WonVia;
use App\Http\Controllers\Controller;
use App\Models\Championship;
use App\Models\MatchParticipant;
use App\Models\MatchResult;
use App\Models\MatchSlot;
use App\Models\MoneyInTheBank;
use App\Models\Save;
use App\Models\Show;
use App\Models\TitleReign;
use App\Services\Calendar\CalendarService;
use App\Services\Titles\MitbService;
use App\Services\Titles\TitleRecords;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lineage of all 8 championships, the Hall of Champions and records (§16).
 * Each reign lists the match it was won in and every match it was defended
 * in, linked to their results.
 */
class TitleHistoryController extends Controller
{
    public function __construct(private CalendarService $calendar) {}

    public function __invoke(Save $save, TitleRecords $records, MitbService $mitb): Response
    {
        $week = $save->current_week;
        $championships = Championship::orderBy('sort_order')->get();

        $reigns = TitleReign::with(['wonShow'])
            ->where('save_id', $save->id)
            ->orderBy('id')
            ->get()
            ->groupBy('championship_id');

        $wonMatches = MatchSlot::with(['matchType', 'show', 'participants.saveWrestler.wrestler'])
            ->whereIn('id', $reigns->flatten()->pluck('won_match_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $defenses = MatchResult::with(['matchSlot.matchType', 'matchSlot.show', 'matchSlot.participants.saveWrestler.wrestler'])
            ->whereIn('champion_reign_id', $reigns->flatten()->pluck('id')->all())
            ->where('title_changed', false)
            ->orderBy('id')
            ->get()
            ->groupBy('champion_reign_id');

        $titles = $championships->map(function (Championship $c) use ($save, $reigns, $week, $wonMatches, $defenses): array {
            /** @var Collection<int, TitleReign> $rows */
            $rows = $reigns->get($c->id) ?? collect();
            $previous = null;
            $lineage = collect();

            foreach ($rows as $r) {
                $lineage->push($this->row($save, $r, $rows, $previous, $week, $wonMatches, $defenses->get($r->id) ?? collect()));
                $previous = $r;
            }

            $current = $lineage->last();

            return [
                'id' => $c->id,
                'key' => $c->key->value,
                'name' => $c->name,
                'brand' => $c->brand->value,
                'is_tag' => $c->is_tag,
                'current' => $current !== null && $current['holder_type'] !== TitleHolderType::Vacant->value ? $current : null,
                'vacant' => $current === null || $current['holder_type'] === TitleHolderType::Vacant->value,
                'lineage' => $lineage->reverse()->values(),
            ];
        });

        return Inertia::render('gm/titles/history', [
            'titles' => $titles,
            'hall' => $titles->groupBy('brand')->map(fn ($group) => $group->map(fn (array $t): array => [
                'name' => $t['name'],
                'holder' => $t['current']['holder'] ?? null,
                'weeks_held' => $t['current']['weeks_held'] ?? null,
                'defenses' => $t['current']['defenses'] ?? null,
            ])->values()),
            'records' => [
                'overall' => $records->records($save),
                'per_title' => $championships->mapWithKeys(fn (Championship $c): array => [$c->id => $records->records($save, $c)]),
            ],
            'briefcases' => $mitb->held($save)->map(fn (MoneyInTheBank $b): array => [
                'holder' => $b->holder->name(),
                'brand' => $b->brand->value,
                'expires_week' => $b->expires_week,
            ])->values(),
        ]);
    }

    /**
     * @param  Collection<int, TitleReign>  $all  this title's reigns, oldest first
     * @param  Collection<int, MatchSlot>  $wonMatches
     * @param  Collection<int, MatchResult>  $defenses  oldest first
     * @return array<string, mixed>
     */
    private function row(Save $save, TitleReign $r, Collection $all, ?TitleReign $previous, int $week, Collection $wonMatches, Collection $defenses): array
    {
        $vacant = $r->isVacant();
        $wonMatch = $r->won_match_id === null ? null : $wonMatches->get($r->won_match_id);

        return [
            'id' => $r->id,
            'reign_number' => $r->reign_number,
            'holder' => $r->holderName(),
            'holder_type' => $r->holder_type->value,
            'holder_reign' => $vacant ? null : $this->holderReign($r, $all),
            'won_week' => $r->won_week,
            'won_season' => $r->season,
            'won_season_week' => $this->calendar->seasonWeek($r->won_week),
            'won_ppv' => $this->ppvName($r->wonShow),
            'won_link' => $wonMatch === null ? null : $this->link($save, $r->won_week, $r->wonShow, $wonMatch->id),
            'how' => match (true) {
                $vacant => null,
                $r->won_via === WonVia::Awarded || $wonMatch === null => 'Awarded',
                $r->won_via === WonVia::MitbCashIn => "{$wonMatch->matchType?->name} (Money in the Bank cash-in)",
                default => $wonMatch->matchType?->name,
            },
            'vacated_reason' => $vacant ? $previous?->end_reason->value : null,
            'weeks_held' => $r->weeksHeld($week),
            'defenses' => $r->defenses,
            // Newest first: the defenses, then the match the title was won in.
            'matches' => $defenses->values()
                ->map(fn (MatchResult $result, int $i): array => $this->match($save, $r, $result->matchSlot, 'defense', $i + 1))
                ->reverse()
                ->when($wonMatch !== null && ! $vacant, fn (Collection $rows) => $rows->push($this->match($save, $r, $wonMatch, 'won', null)))
                ->values(),
            'active' => $r->lost_week === null,
        ];
    }

    /**
     * The holder's Nth reign with this title (the same wrestler or the same
     * tag team members).
     *
     * @param  Collection<int, TitleReign>  $all
     */
    private function holderReign(TitleReign $reign, Collection $all): int
    {
        $key = $this->holderKey($reign);

        return $all->filter(fn (TitleReign $r): bool => $r->id <= $reign->id && ! $r->isVacant() && $this->holderKey($r) === $key)->count();
    }

    private function holderKey(TitleReign $reign): string
    {
        $ids = $reign->holderIds();
        sort($ids);

        return implode(',', $ids);
    }

    /**
     * A match under a reign: the one it was won in, or a defense (numbered).
     *
     * @param  'won'|'defense'  $kind
     * @return array<string, mixed>
     */
    private function match(Save $save, TitleReign $reign, MatchSlot $slot, string $kind, ?int $number): array
    {
        $opponents = $slot->participants
            ->filter(fn (MatchParticipant $p): bool => $p->role === ParticipantRole::Competitor && ! in_array($p->save_wrestler_id, $reign->holderIds(), true))
            ->groupBy('side')
            ->map(fn (Collection $side): string => $side->map(fn (MatchParticipant $p): string => $p->saveWrestler->wrestler->name)->implode(' & '))
            ->values()
            ->implode(', ');

        return [
            'id' => $slot->id,
            'kind' => $kind,
            'number' => $number,
            'opponents' => $opponents,
            'season' => $this->calendar->seasonOf($slot->show->week_number),
            'season_week' => $this->calendar->seasonWeek($slot->show->week_number),
            'ppv' => $this->ppvName($slot->show),
            'match_type' => $slot->matchType?->name,
            'link' => $this->link($save, $slot->show->week_number, $slot->show, $slot->id),
        ];
    }

    private function ppvName(?Show $show): ?string
    {
        return $show !== null && $show->isPpv() ? $show->name : null;
    }

    private function link(Save $save, int $week, ?Show $show, int $slotId): string
    {
        $query = ['match' => $slotId];

        if ($show?->brand !== null) {
            $query['brand'] = $show->brand->value;
        }

        return route('gm.results', [$save, $week, ...$query], false);
    }
}
