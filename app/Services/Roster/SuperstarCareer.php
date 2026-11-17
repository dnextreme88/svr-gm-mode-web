<?php

namespace App\Services\Roster;

use App\Enums\DraftPhase;
use App\Enums\DraftPickType;
use App\Enums\MatchFamily;
use App\Enums\MitbStatus;
use App\Enums\ParticipantRole;
use App\Models\DraftPick;
use App\Models\Injury;
use App\Models\InterbrandSeries;
use App\Models\MatchParticipant;
use App\Models\MatchSlot;
use App\Models\MoneyInTheBank;
use App\Models\RoyalRumbleEntry;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Calendar\CalendarService;

/**
 * A superstar's career on their profile (house rule, 2026-11-15): every
 * injury they've had, and the special moments (Money in the Bank, Survivor
 * Series, the WrestleMania interbrand series, the re-draft's tags).
 *
 * @phpstan-type InjuryRow array{id: int, week: string, absolute_week: int, ppv: string|null, severity: string, escalated: bool, name: string, ended: string, active: bool, weeks_left: int|null}
 * @phpstan-type Moment array{kind: string, title: string, detail: string, week: string, absolute_week: int, outcome: string|null, url: string|null}
 */
class SuperstarCareer
{
    public function __construct(private CalendarService $calendar) {}

    /**
     * Every injury, newest first.
     *
     * @return list<InjuryRow>
     */
    public function injuries(Save $save, SaveWrestler $wrestler): array
    {
        $week = $save->current_week;

        return array_values(Injury::with(['type', 'saveWrestler'])
            ->where('save_id', $save->id)
            ->where('save_wrestler_id', $wrestler->id)
            ->orderByDesc('started_week')
            ->orderByDesc('id')
            ->get()
            ->map(function (Injury $injury) use ($week): array {
                $active = $injury->healed_week === null && $injury->ends_week > $week;
                $escalation = Injury::where('escalated_from_id', $injury->id)->value('started_week');

                $ended = match (true) {
                    $escalation !== null => 'Became serious '.$this->label((int) $escalation),
                    $active => 'Out, '.$this->weeks($injury->ends_week - $week).' left',
                    default => $this->label($injury->healed_week ?? $injury->ends_week),
                };

                return [
                    'id' => $injury->id,
                    'week' => $this->label($injury->started_week),
                    'absolute_week' => $injury->started_week,
                    'ppv' => $this->ppvOf($injury),
                    'severity' => $injury->severity->value,
                    'escalated' => $injury->escalated_from_minor || $injury->escalated_from_id !== null,
                    'name' => $injury->type->label,
                    'ended' => $ended,
                    'active' => $active && $escalation === null,
                    'weeks_left' => $active ? $injury->ends_week - $week : null,
                ];
            })
            ->all());
    }

    /**
     * Special moments, newest first.
     *
     * @return list<Moment>
     */
    public function moments(Save $save, SaveWrestler $wrestler): array
    {
        $moments = [
            ...$this->briefcases($save, $wrestler),
            ...$this->survivorSeries($save, $wrestler),
            ...$this->interbrandSeries($save, $wrestler),
        ];

        usort($moments, fn (array $a, array $b): int => $b['absolute_week'] <=> $a['absolute_week']);

        return $moments;
    }

    /**
     * @return list<Moment>
     */
    private function briefcases(Save $save, SaveWrestler $wrestler): array
    {
        $moments = [];

        foreach (MoneyInTheBank::where('save_id', $save->id)->where('save_wrestler_id', $wrestler->id)->orderBy('id')->get() as $case) {
            $moments[] = [
                'kind' => 'mitb',
                'title' => 'Won Money in the Bank',
                'detail' => 'The briefcase is a world title shot, good until week '.$this->calendar->seasonWeek($case->expires_week).' of season '.$this->calendar->seasonOf($case->expires_week).'.',
                'week' => $this->label($case->won_week),
                'absolute_week' => $case->won_week,
                'outcome' => null,
                'url' => null,
            ];

            if ($case->status === MitbStatus::Held) {
                continue;
            }

            $resolved = $case->resolved_week ?? $case->won_week;

            if (in_array($case->status, [MitbStatus::CashedInWon, MitbStatus::CashedInLost], true)) {
                $slot = $case->cash_in_match_id === null ? null : MatchSlot::with(['show', 'result.championReign.championship', 'titleChampionship'])->find($case->cash_in_match_id);
                $reign = $slot?->result?->championReign;
                $title = $reign !== null ? $reign->championship->name : ($slot?->titleChampionship->name ?? 'the world title');
                $victim = $reign?->holderName();

                $moments[] = [
                    'kind' => 'mitb_cash_in',
                    'title' => 'Cashed in Money in the Bank',
                    'detail' => $victim !== null ? "Cashed in on {$victim} for the {$title}." : "Cashed in for the {$title}.",
                    'week' => $this->label($resolved),
                    'absolute_week' => $resolved,
                    'outcome' => $case->status === MitbStatus::CashedInWon ? 'Won the title' : 'Lost',
                    'url' => $slot === null ? null : $this->link($save, $slot),
                ];

                continue;
            }

            $moments[] = [
                'kind' => 'mitb_lost',
                'title' => $case->status === MitbStatus::Expired ? 'Money in the Bank expired' : 'Money in the Bank voided',
                'detail' => $case->resolution_note !== null ? ucfirst(str_replace('_', ' ', $case->resolution_note)).'.' : 'The briefcase was never cashed in.',
                'week' => $this->label($resolved),
                'absolute_week' => $resolved,
                'outcome' => null,
                'url' => null,
            ];
        }

        return $moments;
    }

    /**
     * @return list<Moment>
     */
    private function survivorSeries(Save $save, SaveWrestler $wrestler): array
    {
        $moments = [];
        $entries = MatchParticipant::with(['matchSlot.show', 'matchSlot.result', 'matchSlot.matchType'])
            ->where('save_wrestler_id', $wrestler->id)
            ->where('role', ParticipantRole::Competitor)
            ->whereHas('matchSlot.matchType', fn ($q) => $q->where('family', MatchFamily::SurvivorSeriesElimination))
            ->get();

        foreach ($entries as $entry) {
            $slot = $entry->matchSlot;
            $team = $slot->show->brand === null
                ? ($entry->side === 0 ? 'Team Raw' : 'Team Smackdown')
                : 'Team '.$slot->show->brand->label();

            $moments[] = [
                'kind' => 'survivor_series',
                'title' => 'Survivor Series',
                'detail' => "{$team} in the Traditional 5 vs 5 Elimination match.",
                'week' => $this->label($slot->show->week_number),
                'absolute_week' => $slot->show->week_number,
                'outcome' => $slot->result === null ? null : ($slot->result->winner_side === $entry->side ? 'Won' : 'Lost'),
                'url' => $this->link($save, $slot),
            ];
        }

        return $moments;
    }

    /**
     * @return list<Moment>
     */
    private function interbrandSeries(Save $save, SaveWrestler $wrestler): array
    {
        $moments = [];

        foreach (InterbrandSeries::where('save_id', $save->id)->orderBy('season')->get() as $series) {
            $entries = MatchParticipant::with(['matchSlot.show', 'matchSlot.result', 'matchSlot.matchType'])
                ->where('save_wrestler_id', $wrestler->id)
                ->where('role', ParticipantRole::Competitor)
                ->whereIn('match_slot_id', $series->match_ids)
                ->get();

            foreach ($entries as $entry) {
                $slot = $entry->matchSlot;

                $moments[] = [
                    'kind' => 'interbrand_series',
                    'title' => 'WrestleMania interbrand series',
                    'detail' => ($slot->matchType->name ?? 'A match').' for '.($wrestler->brand?->label() ?? 'their brand').' in the series that decides the re-draft perks.',
                    'week' => $this->label($slot->show->week_number),
                    'absolute_week' => $slot->show->week_number,
                    'outcome' => $slot->result === null ? null : ($slot->result->winner_side === $entry->side ? 'Won' : 'Lost'),
                    'url' => $this->link($save, $slot),
                ];
            }

            if (in_array($wrestler->id, $series->exemptIds(), true)) {
                $moments[] = [
                    'kind' => 'immunity_tag',
                    'title' => 'Immunity tag',
                    'detail' => ($series->immunity_brand?->label() ?? $series->winner_brand?->label() ?? 'Their brand').' protected them from the re-draft with the immunity tag.',
                    'week' => $this->label($series->week_number),
                    'absolute_week' => $series->week_number,
                    'outcome' => null,
                    'url' => null,
                ];
            }

            if (in_array($wrestler->id, $series->handpickIds(), true)) {
                $moments[] = [
                    'kind' => 'manual_pick_tag',
                    'title' => 'Manual draft pick tag',
                    'detail' => ($series->winner_brand?->label() ?? 'The series winner').' hand-picked them in the re-draft.',
                    'week' => $this->label($series->week_number),
                    'absolute_week' => $series->week_number,
                    'outcome' => null,
                    'url' => null,
                ];
            }
        }

        return $moments;
    }

    /**
     * Every re-draft that moved them (house rule, 2026-11-16), newest first:
     * the brand they left, the brand that drafted them, the season, and
     * which of that brand's picks it was ("3/5"). The season is the one
     * whose WrestleMania it followed.
     *
     * @return list<array{season: int, from: string, to: string, pick: int, picks: int, manual: bool}>
     */
    public function redrafts(Save $save, SaveWrestler $wrestler): array
    {
        $perBrand = (int) config('gm.ppv.redraft_picks_per_brand');

        return array_values(DraftPick::where('save_id', $save->id)
            ->where('save_wrestler_id', $wrestler->id)
            ->where('phase', DraftPhase::WrestlemaniaReset)
            ->where('skipped', false)
            ->orderByDesc('season')
            ->get()
            ->map(fn (DraftPick $pick): array => [
                // Picks are stamped with the season they lead into; the
                // re-draft follows the previous season's WrestleMania.
                'season' => max(1, $pick->season - 1),
                'from' => $pick->brand->rival()->value,
                'to' => $pick->brand->value,
                // The brands alternate, so a brand's Nth pick is overall pick 2N-1 or 2N.
                'pick' => intdiv($pick->pick_number + 1, 2),
                'picks' => $perBrand,
                'manual' => $pick->pick_type === DraftPickType::Manual,
            ])
            ->all());
    }

    private function ppvOf(Injury $injury): ?string
    {
        if ($injury->match_slot_id === null) {
            return null;
        }

        $show = Show::with('ppvEvent')->whereKey(MatchSlot::whereKey($injury->match_slot_id)->value('show_id'))->first();

        return $show !== null && $show->isPpv() ? ($show->ppvEvent->name ?? $show->name) : null;
    }

    /**
     * Every Royal Rumble they entered (house rule, 2026-11-15), newest
     * first: when, their entry number, how many they eliminated and who
     * eliminated them, and whether they won.
     *
     * @return list<array{season: int, week: string, ppv: string, entry_number: int, entrants: int, eliminations: int, eliminated_by: string|null, won: bool, url: string}>
     */
    public function rumbles(Save $save, SaveWrestler $wrestler): array
    {
        return array_values(RoyalRumbleEntry::with(['eliminatedBy.wrestler', 'matchSlot.show'])
            ->where('save_id', $save->id)
            ->where('save_wrestler_id', $wrestler->id)
            ->orderByDesc('week_number')
            ->get()
            ->map(fn (RoyalRumbleEntry $e): array => [
                'season' => $e->season,
                'week' => $this->label($e->week_number),
                'ppv' => 'Royal Rumble',
                'entry_number' => $e->entry_number,
                'entrants' => RoyalRumbleEntry::where('match_slot_id', $e->match_slot_id)->count(),
                'eliminations' => $e->eliminations,
                'eliminated_by' => $e->eliminatedBy?->name(),
                'won' => $e->won,
                'url' => $this->link($save, $e->matchSlot),
            ])
            ->all());
    }

    private function label(int $week): string
    {
        return 'S'.$this->calendar->seasonOf($week).' · W'.$this->calendar->seasonWeek($week);
    }

    private function weeks(int $n): string
    {
        return $n.' '.($n === 1 ? 'week' : 'weeks');
    }

    private function link(Save $save, MatchSlot $slot): string
    {
        $query = ['match' => $slot->id];

        if ($slot->show->brand !== null) {
            $query['brand'] = $slot->show->brand->value;
        }

        return route('gm.results', [$save, $slot->show->week_number, ...$query], false);
    }
}
