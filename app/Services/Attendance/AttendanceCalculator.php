<?php

namespace App\Services\Attendance;

/**
 * Fan attendance (house rule, 2026-11-01): turns what a show (or a PPV
 * night) had on it into a 0–100 draw score, then a crowd and a gate. Pure
 * scoring; `AttendanceService` gathers the facts. Points in
 * `gm.attendance.points`.
 *
 * @phpstan-type SlotDraw array{title: 'world'|'other'|null, gimmick: bool, rivals: bool}
 * @phpstan-type DrawFacts array{
 *     match_one_booked: bool,
 *     main_event_booked: bool,
 *     every_slot_booked: bool,
 *     main_event: SlotDraw|null,
 *     match_one: SlotDraw|null,
 *     hot_rivalries: int,
 *     legends: int,
 *     rival_promos: array{succeeded: int, backfired: int},
 *     other_promos: array{succeeded: int, failed: int},
 *     best_streak: int,
 *     repeats: int,
 * }
 */
class AttendanceCalculator
{
    public const KINDS = ['weekly', 'brand_ppv', 'shared_ppv'];

    /**
     * Points per factor, before noise.
     *
     * @param  DrawFacts  $facts
     * @return array{full_card: int, main_event: int, match_one: int, hot_rivalries: int, legends: int, promos: int, win_streak: int, repeats: int}
     */
    public function factors(array $facts): array
    {
        $p = (array) config('gm.attendance.points');

        return [
            'full_card' => ($facts['match_one_booked'] ? (int) $p['full_card']['match_one'] : 0)
                + ($facts['main_event_booked'] ? (int) $p['full_card']['main_event'] : 0)
                + ($facts['every_slot_booked'] ? (int) $p['full_card']['every_slot'] : 0),
            'main_event' => $this->slot($facts['main_event'], (array) $p['main_event']),
            'match_one' => $this->slot($facts['match_one'], (array) $p['match_one']),
            'hot_rivalries' => min((int) $p['hot_rivalry_max'], $facts['hot_rivalries'] * (int) $p['hot_rivalry']),
            'legends' => $this->tiered($facts['legends'], (array) $p['legends']),
            'promos' => $this->promos($facts['rival_promos'], $facts['other_promos'], $p),
            'win_streak' => $this->tiered($facts['best_streak'], (array) $p['win_streak']),
            // House rule (2026-11-08): a deduction, so 0 or less.
            'repeats' => -min((int) $p['repeat_max'], $facts['repeats'] * (int) $p['repeat_slot']),
        ];
    }

    /**
     * The draw score: the factors plus noise, kept within 0–100.
     *
     * @param  array<string, int>  $factors
     */
    public function score(array $factors, int $noise = 0): int
    {
        return max(0, min(100, array_sum($factors) + $noise));
    }

    /**
     * The crowd for a draw score: min + (max - min) × score / 100.
     */
    public function crowd(string $kind, int $score): int
    {
        [$min, $max] = array_map('intval', (array) config("gm.attendance.ranges.{$kind}"));

        return (int) round($min + ($max - $min) * $score / 100);
    }

    /**
     * Ticket money for a crowd.
     */
    public function gate(string $kind, int $crowd): int
    {
        return $crowd * (int) config("gm.attendance.ticket_prices.{$kind}");
    }

    /**
     * @param  SlotDraw|null  $draw
     * @param  array<string, mixed>  $points
     */
    private function slot(?array $draw, array $points): int
    {
        if ($draw === null) {
            return 0;
        }

        $title = match ($draw['title']) {
            'world' => (int) $points['world_title'],
            'other' => (int) $points['other_title'],
            default => 0,
        };

        return $title
            + ($draw['gimmick'] ? (int) $points['gimmick'] : 0)
            + ($draw['rivals'] ? (int) $points['rivals'] : 0);
    }

    /**
     * Points for the highest threshold reached ([5 => 5, 10 => 10]: 7 → 5).
     *
     * @param  array<int|string, mixed>  $tiers
     */
    private function tiered(int $value, array $tiers): int
    {
        $points = 0;

        foreach ($tiers as $from => $award) {
            if ($value >= (int) $from) {
                $points = max($points, (int) $award);
            }
        }

        return $points;
    }

    /**
     * Each promo on its own (house rule, 2026-11-08): a successful
     * Interference or Slander adds, a backfired one takes away; another
     * promo that could fail adds on a success and takes away on a failure.
     * Promos that always succeed score nothing.
     *
     * @param  array{succeeded: int, backfired: int}  $rival
     * @param  array{succeeded: int, failed: int}  $other
     * @param  array<string, mixed>  $points
     */
    private function promos(array $rival, array $other, array $points): int
    {
        $r = (array) $points['rival_promos'];
        $o = (array) $points['other_promos'];

        return $rival['succeeded'] * (int) $r['success']
            + $rival['backfired'] * (int) $r['backfire']
            + $other['succeeded'] * (int) $o['success']
            + $other['failed'] * (int) $o['failure'];
    }
}
