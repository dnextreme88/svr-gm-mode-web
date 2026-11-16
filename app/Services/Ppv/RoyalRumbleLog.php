<?php

namespace App\Services\Ppv;

use App\Models\MatchSlot;
use App\Models\RoyalRumbleEntry;
use App\Models\SaveWrestler;
use App\Services\Matches\ResolvedMatch;
use App\Support\Rng\Rng;

/**
 * How a Royal Rumble played out (house rule, 2026-11-15), written once its
 * result is in. The entrants come in one by one in a seeded order (entry
 * numbers 1–30). After each arrival someone may be thrown out by someone
 * else in the ring, always when the ring is crowded (`gm.ppv.rumble`).
 * Once everyone is in, the rest go one at a time until the winner, booked
 * or simulated, last eliminates the superstar the finish text names.
 */
class RoyalRumbleLog
{
    /** @var list<int> */
    private array $ring = [];

    /** @var array<int, int> eliminations by entrant */
    private array $eliminations = [];

    /** @var array<int, int> who threw each entrant out */
    private array $eliminatedBy = [];

    /** @var list<int> elimination order */
    private array $out = [];

    public function record(MatchSlot $slot, ResolvedMatch $resolved, int $season, int $week, Rng $rng): void
    {
        if (RoyalRumbleEntry::where('match_slot_id', $slot->id)->exists()) {
            return;
        }

        $entrants = [];

        foreach ($slot->sides() as $ids) {
            foreach ($ids as $id) {
                $entrants[] = (int) $id;
            }
        }

        $winner = $resolved->winners[0] ?? null;
        $last = $slot->sides()[$resolved->loserSide][0] ?? null;

        if ($winner === null || count($entrants) < 2) {
            return;
        }

        $order = $rng->shuffle($entrants);
        $cap = (int) config('gm.ppv.rumble.ring_cap');
        $chance = (float) config('gm.ppv.rumble.elimination_chance');
        $this->ring = [];
        $this->eliminations = array_fill_keys($entrants, 0);
        $this->eliminatedBy = [];
        $this->out = [];

        foreach ($order as $id) {
            $this->ring[] = $id;

            if (count($this->ring) > 2 && $rng->chance($chance)) {
                $this->throwOut($winner, $last, $rng);
            }

            while (count($this->ring) > $cap && $this->throwOut($winner, $last, $rng)) {
                // the ring is crowded
            }
        }

        while ($this->throwOut($winner, $last, $rng)) {
            // everyone else goes before the final two
        }

        if ($last !== null && $last !== $winner) {
            $this->eliminations[$winner]++;
            $this->eliminatedBy[$last] = $winner;
            $this->out[] = $last;
        }

        $brands = SaveWrestler::whereIn('id', $entrants)->pluck('brand', 'id');

        foreach ($order as $index => $id) {
            $position = array_search($id, $this->out, true);

            RoyalRumbleEntry::create([
                'save_id' => $slot->show->save_id,
                'match_slot_id' => $slot->id,
                'season' => $season,
                'week_number' => $week,
                'save_wrestler_id' => $id,
                'brand' => $brands[$id] ?? null,
                'entry_number' => $index + 1,
                'eliminations' => $this->eliminations[$id] ?? 0,
                'eliminated_by_id' => $this->eliminatedBy[$id] ?? null,
                'elimination_order' => $position === false ? null : $position + 1,
                'won' => $id === $winner,
            ]);
        }
    }

    /**
     * Someone in the ring (never the winner or the last loser, who both
     * make it to the end) is thrown out by someone else in the ring.
     */
    private function throwOut(int $winner, ?int $last, Rng $rng): bool
    {
        $victims = array_values(array_filter($this->ring, fn (int $id): bool => $id !== $winner && $id !== $last));

        if ($victims === [] || count($this->ring) < 2) {
            return false;
        }

        $victim = (int) $rng->pick($victims);
        $by = (int) $rng->pick(array_values(array_filter($this->ring, fn (int $id): bool => $id !== $victim)));
        $this->ring = array_values(array_filter($this->ring, fn (int $id): bool => $id !== $victim));
        $this->eliminations[$by] = ($this->eliminations[$by] ?? 0) + 1;
        $this->eliminatedBy[$victim] = $by;
        $this->out[] = $victim;

        return true;
    }
}
