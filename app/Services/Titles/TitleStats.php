<?php

namespace App\Services\Titles;

use App\Enums\ReignEndReason;
use App\Enums\TitleHolderType;
use App\Models\Championship;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\TitleReign;
use Illuminate\Support\Collection;

/**
 * Title History's stats (house rule, 2026-11-21): for each title its title
 * matches on PPV, vacancies, most and least contested match types, unique
 * champions and defenses (a tag title also its most reigns by superstar and
 * by team); across all titles the superstar who has won the most different
 * titles, and the Grand Slam champions. Holders are always counted and
 * named as the superstars themselves, never a faction, so a faction
 * disbanding can't change the history.
 */
class TitleStats
{
    /** Why a reign can end without the title changing hands in a match. */
    private const VACATING = [
        ReignEndReason::Injured,
        ReignEndReason::Defected,
        ReignEndReason::Released,
        ReignEndReason::ContractExpired,
        ReignEndReason::LoserLeaves,
    ];

    /**
     * @param  Collection<int, Championship>  $championships
     * @return array<int, array<string, mixed>> by championship id
     */
    public function perTitle(Save $save, Collection $championships): array
    {
        $reigns = $this->reigns($save)->groupBy('championship_id');
        $matches = MatchSlot::with(['show', 'matchType'])
            ->whereHas('result')
            ->whereHas('show', fn ($q) => $q->where('save_id', $save->id))
            ->where(fn ($q) => $q->whereNotNull('title_championship_id')->orWhereNotNull('contested_title_ids'))
            ->orderBy('id')
            ->get();

        return $championships->mapWithKeys(function (Championship $c) use ($reigns, $matches): array {
            /** @var Collection<int, TitleReign> $rows */
            $rows = ($reigns->get($c->id) ?? collect())->reject(fn (TitleReign $r): bool => $r->isVacant())->values();
            $titleMatches = $matches->filter(fn (MatchSlot $s): bool => $s->title_championship_id === $c->id
                || in_array($c->id, $s->contested_title_ids ?? [], true));

            return [$c->id => [
                'title_matches' => $titleMatches->count(),
                'ppv_title_matches' => $titleMatches->filter(fn (MatchSlot $s): bool => $s->show->isPpv())->count(),
                ...$this->vacancies($rows),
                ...$this->matchTypes($titleMatches),
                'champions' => $rows->flatMap(fn (TitleReign $r): array => $r->holderIds())->unique()->count(),
                'teams' => $c->is_tag ? $rows->map(fn (TitleReign $r): string => $r->holderKey())->unique()->count() : null,
                'defenses' => (int) $rows->sum('defenses'),
                'most_reigns_superstar' => $c->is_tag ? $this->mostReignsBySuperstar($rows) : null,
                'most_reigns_team' => $c->is_tag ? $this->mostReignsByTeam($rows) : null,
            ]];
        })->all();
    }

    /**
     * Across every title, both brands: the superstar who has won the most
     * different titles, and the Grand Slam champions.
     *
     * @return array{most_titles: array<string, mixed>|null, grand_slams: list<array<string, mixed>>}
     */
    public function overall(Save $save): array
    {
        $reigns = $this->reigns($save)->reject(fn (TitleReign $r): bool => $r->isVacant());

        // Each superstar's first reign with each title, oldest first.
        /** @var array<int, array{name: string, firsts: array<int, TitleReign>}> $bySuperstar */
        $bySuperstar = [];

        foreach ($reigns as $r) {
            foreach ($r->holderIds() as $i => $id) {
                $bySuperstar[$id] ??= ['name' => $r->holder_snapshot['names'][$i] ?? '', 'firsts' => []];
                $bySuperstar[$id]['firsts'][$r->championship_id] ??= $r;
            }
        }

        return [
            'most_titles' => $this->mostTitles($bySuperstar),
            'grand_slams' => $this->grandSlams($bySuperstar),
        ];
    }

    /**
     * @param  array<int, array{name: string, firsts: array<int, TitleReign>}>  $bySuperstar
     * @return array<string, mixed>|null
     */
    private function mostTitles(array $bySuperstar): ?array
    {
        $best = null;

        foreach ($bySuperstar as $id => $s) {
            $count = count($s['firsts']);
            // Ties go to whoever reached that many first.
            $reached = collect($s['firsts'])->max(fn (TitleReign $r): int => $r->id);

            if ($best === null || $count > $best['value'] || ($count === $best['value'] && $reached < $best['reached'])) {
                $best = ['id' => $id, 'name' => $s['name'], 'value' => $count, 'reached' => $reached, 'firsts' => $s['firsts']];
            }
        }

        // Every champion has won 1: it only says something from 2 titles up.
        if ($best === null || $best['value'] < 2) {
            return null;
        }

        return [
            'holder' => $best['name'],
            'holder_ids' => [$best['id']],
            'value' => $best['value'],
            'titles' => collect($best['firsts'])->sortBy(fn (TitleReign $r): int => $r->championship->sort_order)
                ->map(fn (TitleReign $r): string => $r->championship->name)->values()->all(),
        ];
    }

    /**
     * Superstars who have won a world title, a secondary title and a tag
     * title (`gm.titles.grand_slam`), in the order they completed it, with
     * the first title they won from each group and when the set was done.
     *
     * @param  array<int, array{name: string, firsts: array<int, TitleReign>}>  $bySuperstar
     * @return list<array<string, mixed>>
     */
    private function grandSlams(array $bySuperstar): array
    {
        /** @var array<string, list<string>> $groups */
        $groups = config('gm.titles.grand_slam');
        $slams = [];

        foreach ($bySuperstar as $id => $s) {
            $parts = [];

            foreach ($groups as $group => $keys) {
                $first = collect($s['firsts'])
                    ->filter(fn (TitleReign $r): bool => in_array($r->championship->key->value, $keys, true))
                    ->sortBy(fn (TitleReign $r): array => [$r->won_week, $r->id])
                    ->first();

                if ($first === null) {
                    continue 2;
                }

                $parts[$group] = $first;
            }

            // Completed by the last of the three to be won.
            $last = collect($parts)->sortBy(fn (TitleReign $r): array => [$r->won_week, $r->id])->last();

            $slams[] = [
                'holder' => $s['name'],
                'holder_ids' => [$id],
                'titles' => array_map(fn (string $group, TitleReign $r): array => [
                    'group' => $group,
                    'championship' => $r->championship->name,
                    'won_week' => $r->won_week,
                    'won_ppv' => $r->wonShow?->isPpv() ? $r->wonShow->name : null,
                ], array_keys($parts), $parts),
                'attained_week' => $last->won_week,
                'attained_ppv' => $last->wonShow?->isPpv() ? $last->wonShow->name : null,
                'attained_order' => $last->id,
            ];
        }

        usort($slams, fn (array $a, array $b): int => [$a['attained_week'], $a['attained_order']] <=> [$b['attained_week'], $b['attained_order']]);

        return array_map(function (array $slam): array {
            unset($slam['attained_order']);

            return $slam;
        }, $slams);
    }

    /**
     * How many times the title was vacated (injury, a superstar leaving the
     * brand or the company…), and why.
     *
     * @param  Collection<int, TitleReign>  $rows
     * @return array{vacated: int, vacated_reasons: array<string, int>}
     */
    private function vacancies(Collection $rows): array
    {
        $vacated = $rows->filter(fn (TitleReign $r): bool => in_array($r->end_reason, self::VACATING, true));

        return [
            'vacated' => $vacated->count(),
            'vacated_reasons' => $vacated->countBy(fn (TitleReign $r): string => $r->end_reason->value)->all(),
        ];
    }

    /**
     * The match types the title was most and least often on the line in
     * ("Hardcore — Single"), ties listed together. Least only when there's
     * more than one type.
     *
     * @param  Collection<int, MatchSlot>  $matches
     * @return array{most_type: array{types: list<string>, value: int}|null, least_type: array{types: list<string>, value: int}|null}
     */
    private function matchTypes(Collection $matches): array
    {
        $counts = $matches->countBy(fn (MatchSlot $s): string => $s->matchType->name ?? '—');

        if ($counts->isEmpty()) {
            return ['most_type' => null, 'least_type' => null];
        }

        $pick = fn (int $value): array => [
            // Keys are match type names; a name that looks numeric would come
            // back as an int key, so they're read back as strings.
            'types' => array_values($counts->filter(fn (int $n): bool => $n === $value)->keys()
                ->map(fn (int|string $name): string => (string) $name)->sort()->all()),
            'value' => $value,
        ];

        return [
            'most_type' => $pick((int) $counts->max()),
            'least_type' => $counts->count() > 1 ? $pick((int) $counts->min()) : null,
        ];
    }

    /**
     * Each superstar's tag reigns, whoever their partner was: Matt Hardy
     * with Edge, Jeff Hardy and The Great Khali is 3.
     *
     * @param  Collection<int, TitleReign>  $rows  oldest first
     * @return array<string, mixed>|null
     */
    private function mostReignsBySuperstar(Collection $rows): ?array
    {
        $counts = [];
        $names = [];

        foreach ($rows as $r) {
            foreach ($r->holderIds() as $i => $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
                $names[$id] ??= $r->holder_snapshot['names'][$i] ?? '';
            }
        }

        if ($counts === []) {
            return null;
        }

        // A tie lists everyone on it, in the order they first held it.
        $max = max($counts);
        $ids = array_keys(array_filter($counts, fn (int $n): bool => $n === $max));

        return [
            'holder' => implode(', ', array_map(fn (int $id): string => $names[$id], $ids)),
            'holder_ids' => $ids,
            'value' => $max,
            'tied' => count($ids) > 1,
        ];
    }

    /**
     * The same partners together, named individually ("Edge & Christian").
     *
     * @param  Collection<int, TitleReign>  $rows  oldest first
     * @return array<string, mixed>|null
     */
    private function mostReignsByTeam(Collection $rows): ?array
    {
        $teams = $rows->groupBy(fn (TitleReign $r): string => $r->holderKey());
        $best = $teams->sortByDesc(fn (Collection $group): int => $group->count())->first();

        return $best === null ? null : [
            'holder' => $best->first()->memberNames(),
            'holder_ids' => $best->first()->holderIds(),
            'value' => $best->count(),
        ];
    }

    /**
     * @return Collection<int, TitleReign>
     */
    private function reigns(Save $save): Collection
    {
        return TitleReign::with(['championship', 'wonShow'])
            ->where('save_id', $save->id)
            ->where('holder_type', '!=', TitleHolderType::Vacant)
            ->orderBy('id')
            ->get();
    }
}
