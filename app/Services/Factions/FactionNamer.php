<?php

namespace App\Services\Factions;

use App\Enums\FactionType;
use App\Models\SaveFaction;
use App\Models\SaveWrestler;
use Illuminate\Database\Eloquent\Collection;

/**
 * Faction names for teams (house rule): any 2+ members of a faction active
 * that week, teaming on one side, are named as the faction. The rest keep
 * their own names: "Evolution & Kane".
 *
 * With overlapping factions, the one covering the most of the side wins,
 * then the smallest (closest fit), then a tag team over a stable, then the
 * name. Matt Hardy & Jeff Hardy are Team Xtreme; Danny & Doug Basham are
 * The Basham Brothers, not The Cabinet.
 *
 * Registered as a singleton: factions are cached per save until `forget()`.
 */
class FactionNamer
{
    /**
     * @var array<int, Collection<int, SaveFaction>>
     */
    private array $factions = [];

    /**
     * @var array<int, string>
     */
    private array $names = [];

    public function forget(int $saveId): void
    {
        unset($this->factions[$saveId]);
    }

    /**
     * One side split into faction groups and single wrestlers, in the side's
     * order (a group sits where its first member was).
     *
     * @param  array<int, int>  $ids
     * @return list<array{faction: SaveFaction|null, ids: list<int>}>
     */
    public function groups(int $saveId, array $ids, int $week): array
    {
        $ids = array_values($ids);
        $remaining = array_values(array_unique($ids));
        $claimed = [];

        while (($pick = $this->bestFaction($saveId, $remaining, $week)) !== null) {
            [$faction, $hit] = $pick;
            $claimed[] = ['faction' => $faction, 'ids' => $hit];
            $remaining = array_values(array_diff($remaining, $hit));
        }

        $groups = [];
        $emitted = [];

        foreach (array_values(array_unique($ids)) as $id) {
            $index = null;

            foreach ($claimed as $i => $group) {
                if (in_array($id, $group['ids'], true)) {
                    $index = $i;
                }
            }

            if ($index === null) {
                $groups[] = ['faction' => null, 'ids' => [$id]];
            } elseif (! isset($emitted[$index])) {
                $emitted[$index] = true;
                $groups[] = $claimed[$index];
            }
        }

        return $groups;
    }

    /**
     * The team's name: "The Brood", "Evolution & Kane", or "A & B". With
     * `$sides` (side => ids), a faction only forms from members on the same
     * side, so two Hardys on opposite sides of a Triple Threat stay apart.
     *
     * @param  array<int, int>  $ids
     * @param  array<int, list<int>>|null  $sides
     */
    public function name(int $saveId, array $ids, int $week, ?array $sides = null): string
    {
        $labels = [];

        foreach ($this->partition(array_values($ids), $sides) as $part) {
            foreach ($this->groups($saveId, $part, $week) as $group) {
                $labels[] = $group['faction']->faction_name ?? $this->wrestlerName($group['ids'][0]);
            }
        }

        return implode(' & ', $labels);
    }

    /**
     * The faction a whole team makes up, if any: every member of the side
     * belongs to that one faction.
     *
     * @param  array<int, int>  $ids
     */
    public function factionOf(int $saveId, array $ids, int $week): ?SaveFaction
    {
        $groups = $this->groups($saveId, $ids, $week);

        return count($groups) === 1 ? $groups[0]['faction'] : null;
    }

    /**
     * Every faction on a side (2+ of its members teaming).
     *
     * @param  array<int, int>  $ids
     * @return list<SaveFaction>
     */
    public function factionsOn(int $saveId, array $ids, int $week): array
    {
        $factions = [];

        foreach ($this->groups($saveId, $ids, $week) as $group) {
            if ($group['faction'] !== null) {
                $factions[] = $group['faction'];
            }
        }

        return $factions;
    }

    /**
     * @return Collection<int, SaveFaction>
     */
    public function all(int $saveId): Collection
    {
        return $this->factions[$saveId] ??= SaveFaction::with('members')->where('save_id', $saveId)->orderBy('id')->get();
    }

    /**
     * @param  list<int>  $remaining
     * @return array{0: SaveFaction, 1: list<int>}|null
     */
    private function bestFaction(int $saveId, array $remaining, int $week): ?array
    {
        $best = null;

        foreach ($this->all($saveId) as $faction) {
            if (! $faction->isActiveAt($week)) {
                continue;
            }

            $members = $faction->memberIds();
            $hit = array_values(array_filter($remaining, fn (int $id): bool => in_array($id, $members, true)));

            if (count($hit) < 2) {
                continue;
            }

            if ($best === null || $this->ranksAbove($faction, $hit, $best[0], $best[1])) {
                $best = [$faction, $hit];
            }
        }

        return $best;
    }

    /**
     * @param  list<int>  $hit
     * @param  list<int>  $bestHit
     */
    private function ranksAbove(SaveFaction $faction, array $hit, SaveFaction $best, array $bestHit): bool
    {
        $key = fn (SaveFaction $f, array $h): array => [
            -count($h),
            count($f->memberIds()),
            $f->faction_type === FactionType::TagTeam ? 0 : 1,
            mb_strtolower($f->faction_name),
        ];

        return $key($faction, $hit) < $key($best, $bestHit);
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, list<int>>|null  $sides
     * @return list<list<int>>
     */
    private function partition(array $ids, ?array $sides): array
    {
        if ($sides === null) {
            return [$ids];
        }

        $parts = [];
        $placed = [];

        foreach ($sides as $sideIds) {
            $part = array_values(array_filter($sideIds, fn (int $id): bool => in_array($id, $ids, true)));

            if ($part !== []) {
                $parts[] = $part;
                array_push($placed, ...$part);
            }
        }

        foreach ($ids as $id) {
            if (! in_array($id, $placed, true)) {
                $parts[] = [$id];
            }
        }

        return $parts;
    }

    private function wrestlerName(int $id): string
    {
        return $this->names[$id] ??= SaveWrestler::with('wrestler')->find($id)?->wrestler->name ?? '?';
    }
}
