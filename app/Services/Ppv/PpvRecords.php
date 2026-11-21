<?php

namespace App\Services\Ppv;

use App\Enums\FixedSlotKind;
use App\Enums\MitbStatus;
use App\Enums\ParticipantRole;
use App\Enums\ShowType;
use App\Http\Presenters\CardPresenter;
use App\Models\InterbrandSeries;
use App\Models\MatchSlot;
use App\Models\MoneyInTheBank;
use App\Models\RoyalRumbleEntry;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Calendar\CalendarService;
use Illuminate\Support\Collection;

/**
 * The Records book's PPV records (house rule, 2026-11-21): every aired PPV
 * match on both brands, as leaderboards (main events, Match 1 openers, wins
 * and losses at PPVs) and the interbrand PPVs' history (Royal Rumble,
 * Survivor Series 5 vs 5, the WrestleMania interbrand series, Money in the
 * Bank). Competitors only: managers and referees don't count.
 */
class PpvRecords
{
    public function __construct(private CalendarService $calendar) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Save $save): array
    {
        $slots = MatchSlot::with(['show.ppvEvent', 'result', 'participants.saveWrestler.wrestler', 'matchType'])
            ->whereHas('show', fn ($q) => $q->where('save_id', $save->id)->where('show_type', ShowType::Ppv))
            ->whereHas('result')
            ->get();

        // The main event is each card's last match (a cash-in never is).
        $mainEvents = $slots->reject(fn (MatchSlot $s): bool => $s->mitb_cash_in !== null)
            ->groupBy('show_id')
            ->map(fn (Collection $card): int => (int) $card->max('position'));

        $tally = ['main_events' => [], 'openers' => [], 'wins' => [], 'losses' => []];

        foreach ($slots as $slot) {
            $isMain = ($mainEvents[$slot->show_id] ?? null) === $slot->position && $slot->mitb_cash_in === null;

            foreach ($slot->participants as $p) {
                if ($p->role !== ParticipantRole::Competitor) {
                    continue;
                }

                $id = $p->save_wrestler_id;
                $won = $p->side === $slot->result?->winner_side;
                $tally[$won ? 'wins' : 'losses'][$id] = ($tally[$won ? 'wins' : 'losses'][$id] ?? 0) + 1;

                if ($isMain) {
                    $tally['main_events'][$id] = ($tally['main_events'][$id] ?? 0) + 1;
                }

                if ($slot->position === 1) {
                    $tally['openers'][$id] = ($tally['openers'][$id] ?? 0) + 1;
                }
            }
        }

        $eliminations = RoyalRumbleEntry::where('save_id', $save->id)
            ->selectRaw('save_wrestler_id, SUM(eliminations) as total')
            ->groupBy('save_wrestler_id')
            ->pluck('total', 'save_wrestler_id')
            ->map(fn ($n): int => (int) $n)
            ->filter(fn (int $n): bool => $n > 0)
            ->all();

        $wrestlers = SaveWrestler::with('wrestler')->where('save_id', $save->id)->get()->keyBy('id');

        return [
            'leaders' => [
                ['key' => 'main_events', 'label' => 'Most PPV main events', 'rows' => $this->top($tally['main_events'], $wrestlers)],
                ['key' => 'openers', 'label' => 'Most Match 1 openers', 'rows' => $this->top($tally['openers'], $wrestlers)],
                ['key' => 'wins', 'label' => 'Most wins at PPVs', 'rows' => $this->top($tally['wins'], $wrestlers)],
                ['key' => 'losses', 'label' => 'Most losses at PPVs', 'rows' => $this->top($tally['losses'], $wrestlers)],
                ['key' => 'rumble_eliminations', 'label' => 'Most Royal Rumble eliminations', 'rows' => $this->top($eliminations, $wrestlers)],
            ],
            'rumble_winners' => $this->rumbleWinners($save),
            'survivor_series' => $this->survivorSeries($slots),
            'interbrand_series' => InterbrandSeries::where('save_id', $save->id)->orderBy('season')->get()
                ->filter(fn (InterbrandSeries $s): bool => $s->winner_brand !== null)
                ->map(fn (InterbrandSeries $s): array => [
                    'season' => $s->season,
                    'raw_wins' => $s->raw_wins,
                    'smackdown_wins' => $s->smackdown_wins,
                    'winner' => $s->winner_brand?->value,
                ])->values()->all(),
            'mitb' => MoneyInTheBank::with('holder.wrestler')->where('save_id', $save->id)->orderBy('won_week')->get()
                ->map(fn (MoneyInTheBank $m): array => [
                    'season' => $this->calendar->seasonOf($m->won_week),
                    'name' => $m->holder->wrestler->name,
                    'brand' => $m->brand->value,
                    'status' => $m->status->value,
                    'cashed_in' => in_array($m->status, [MitbStatus::CashedInWon, MitbStatus::CashedInLost], true),
                ])->values()->all(),
        ];
    }

    /**
     * The top ten (ties at the cut included), most first, then by name.
     *
     * @param  array<int, int>  $counts
     * @param  Collection<int, SaveWrestler>  $wrestlers
     * @return list<array{name: string, brand: string|null, count: int}>
     */
    private function top(array $counts, Collection $wrestlers): array
    {
        $rows = [];

        foreach ($counts as $id => $count) {
            $w = $wrestlers[$id] ?? null;

            if ($w !== null) {
                $rows[] = ['name' => $w->wrestler->name, 'brand' => $w->brand?->value, 'count' => $count];
            }
        }

        usort($rows, fn (array $a, array $b): int => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);
        $limit = (int) config('gm.records.leaderboard_size');
        $cut = $rows[$limit - 1]['count'] ?? null;

        return array_values(array_filter($rows, fn (array $r, int $i): bool => $i < $limit || $r['count'] === $cut, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * @return list<array{season: int, name: string, brand: string|null, eliminations: int}>
     */
    private function rumbleWinners(Save $save): array
    {
        return array_values(RoyalRumbleEntry::with('saveWrestler.wrestler')
            ->where('save_id', $save->id)
            ->where('won', true)
            ->orderBy('season')
            ->get()
            ->map(fn (RoyalRumbleEntry $e): array => [
                'season' => $e->season,
                'name' => $e->saveWrestler->wrestler->name,
                'brand' => $e->brand?->value,
                'eliminations' => $e->eliminations,
            ])->all());
    }

    /**
     * The Survivor Series 5 vs 5: which brand won it, each season.
     *
     * @param  Collection<int, MatchSlot>  $slots
     * @return list<array{season: int, winner: string|null}>
     */
    private function survivorSeries(Collection $slots): array
    {
        return array_values($slots->filter(fn (MatchSlot $s): bool => $s->fixed_kind === FixedSlotKind::SurvivorSeriesMainEvent)
            ->sortBy(fn (MatchSlot $s): int => $s->show->week_number)
            ->map(fn (MatchSlot $s): array => [
                'season' => $this->calendar->seasonOf($s->show->week_number),
                'winner' => CardPresenter::sideBrand($s, (int) $s->result?->winner_side)?->value,
            ])->all());
    }
}
