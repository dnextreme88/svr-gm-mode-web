<?php

namespace App\Services\Titles;

use App\Enums\Brand;
use App\Enums\RematchClauseStatus;
use App\Models\Championship;
use App\Models\MatchSlot;
use App\Models\RematchClause;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\TitleReign;
use Illuminate\Support\Collection;

/**
 * Rematch Clause (house rule, 2026-11-07). When a title changes hands in a
 * match, the former champion(s) are owed a rematch: a title match for that
 * title against the new champion(s), 1v1 (singles titles) or 2v2 (tag
 * titles), managers allowed, within `gm.titles.rematch_clause_weeks`.
 *
 * Any result resolves it. It ends early when someone in it leaves the brand
 * or is seriously injured, or when the title moves on (another change or a
 * vacancy). It never blocks a card: it's an Active Effect only.
 */
class RematchClauseService
{
    /**
     * A title just changed hands in a match: the former holders are owed a
     * rematch against the new ones. Any older clause on the title ends.
     */
    public function create(Save $save, Championship $championship, TitleReign $former, TitleReign $new, int $week): ?RematchClause
    {
        $this->voidForTitle($save, $championship, $week);

        $formerIds = $former->holderIds();
        $championIds = $new->holderIds();

        if ($formerIds === [] || $championIds === []) {
            return null;
        }

        return RematchClause::create([
            'save_id' => $save->id,
            'championship_id' => $championship->id,
            'brand' => $championship->brand,
            'title_reign_id' => $new->id,
            'former_ids' => $formerIds,
            'champion_ids' => $championIds,
            'created_week' => $week,
            'expires_week' => $week + (int) config('gm.titles.rematch_clause_weeks'),
            'status' => RematchClauseStatus::Pending,
        ]);
    }

    /**
     * Pending clauses still within their weeks, oldest first.
     *
     * @return Collection<int, RematchClause>
     */
    public function active(Save $save, int $week, ?Brand $brand = null): Collection
    {
        return RematchClause::with('championship')
            ->where('save_id', $save->id)
            ->where('status', RematchClauseStatus::Pending)
            ->where('expires_week', '>=', $week)
            ->when($brand !== null, fn ($q) => $q->where('brand', $brand))
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    /**
     * A title match for the clause's title with the former champion(s) on
     * one side and the new champion(s) on the other, and nobody else,
     * resolves it, whoever wins.
     */
    public function resolve(Save $save, MatchSlot $slot, int $week): void
    {
        if ($slot->title_championship_id === null) {
            return;
        }

        foreach ($this->active($save, $week) as $clause) {
            if ($clause->championship_id === $slot->title_championship_id && $this->fits($clause, $slot)) {
                $clause->update([
                    'status' => RematchClauseStatus::Resolved,
                    'resolved_match_id' => $slot->id,
                    'resolved_week' => $week,
                ]);
            }
        }
    }

    /**
     * Whether a slot's sides are exactly the former vs the new champions.
     */
    public function fits(RematchClause $clause, MatchSlot $slot): bool
    {
        $sides = array_values($slot->sides());

        if (count($sides) !== 2) {
            return false;
        }

        $sorted = fn (array $ids): array => collect($ids)->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $former = $sorted($clause->former_ids);
        $champions = $sorted($clause->champion_ids);
        [$a, $b] = [$sorted($sides[0]), $sorted($sides[1])];

        return ($a === $former && $b === $champions) || ($a === $champions && $b === $former);
    }

    /**
     * The title moved on (changed hands again or was vacated).
     */
    public function voidForTitle(Save $save, Championship $championship, int $week): void
    {
        RematchClause::where('save_id', $save->id)
            ->where('championship_id', $championship->id)
            ->where('status', RematchClauseStatus::Pending)
            ->update(['status' => RematchClauseStatus::Voided, 'resolved_week' => $week]);
    }

    /**
     * Someone in a clause left the brand or was seriously injured.
     */
    public function voidFor(SaveWrestler $wrestler, int $week): void
    {
        RematchClause::where('save_id', $wrestler->save_id)
            ->where('status', RematchClauseStatus::Pending)
            ->get()
            ->filter(fn (RematchClause $c): bool => in_array($wrestler->id, [...$c->former_ids, ...$c->champion_ids], true))
            ->each(fn (RematchClause $c) => $c->update(['status' => RematchClauseStatus::Voided, 'resolved_week' => $week]));
    }
}
