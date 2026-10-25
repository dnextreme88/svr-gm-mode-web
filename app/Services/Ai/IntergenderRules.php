<?php

namespace App\Services\Ai;

use App\Models\MatchType;
use App\Support\Rng\Rng;

/**
 * The only intergender shapes the rival GM books (house rule). Everything
 * else is single-gender, managers included.
 *
 *  1. 1 w/Manager vs 1 w/Manager: male competitors, both managers Divas.
 *  2. 1 vs 1 w/Manager: male competitors, the one manager a Diva.
 *  3. 2v2 (no managers): each team exactly 1 man + 1 Diva.
 *  4. 3v3 (not Table Tornado 6-Man Tag Elimination): every team has the
 *     same number of Divas, at least 1, and not all Divas.
 *  5. 2 w/Manager vs 2 w/Manager: male teams, both managers Divas.
 *  6. 2 vs 2 w/Manager: male teams, the one manager a Diva.
 *  7. 2v2v2: every team exactly 1 man + 1 Diva.
 */
class IntergenderRules
{
    public const EXCLUDED_SIX_MAN = 'Table Tornado 6-Man Tag Elimination';

    /**
     * Which shape a match type allows, or null if it never goes intergender.
     */
    public function shape(MatchType $type): ?int
    {
        if ($type->divas_only || ! $type->divas_allowed) {
            return null;
        }

        $managers = $type->manager_sides;
        sort($managers);

        return match ($type->sides) {
            [1, 1] => match ($managers) {
                [0, 1] => 1,
                [1] => 2,
                default => null,
            },
            [2, 2] => match ($managers) {
                [] => 3,
                [0, 1] => 5,
                [1] => 6,
                default => null,
            },
            [3, 3] => $managers === [] && $type->variant !== self::EXCLUDED_SIX_MAN ? 4 : null,
            [2, 2, 2] => $managers === [] ? 7 : null,
            default => null,
        };
    }

    /**
     * The fewest Divas a shape needs.
     */
    public function divasNeeded(int $shape): int
    {
        return match ($shape) {
            2, 6 => 1,
            7 => 3,
            default => 2,
        };
    }

    /**
     * The fewest men, competing Divas and managing Divas a shape needs.
     *
     * @return array{men: int, divas_competing: int, divas_managing: int}
     */
    public function needs(int $shape): array
    {
        [$men, $competing, $managing] = match ($shape) {
            1 => [2, 0, 2],
            2 => [2, 0, 1],
            3 => [2, 2, 0],
            4 => [4, 2, 0],
            5 => [4, 0, 2],
            6 => [4, 0, 1],
            default => [3, 3, 0],
        };

        return ['men' => $men, 'divas_competing' => $competing, 'divas_managing' => $managing];
    }

    /**
     * Fill a type in its intergender shape, or null if there aren't enough
     * wrestlers. Competing Divas come from `$divasCompeting` (already fit
     * enough for the match); managing Divas from `$divasManaging`.
     *
     * @param  list<int>  $men
     * @param  list<int>  $divasCompeting
     * @param  list<int>  $divasManaging
     * @return array{sides: list<list<int>>, managers: array<int, int>}|null
     */
    public function build(MatchType $type, array $men, array $divasCompeting, array $divasManaging, Rng $rng): ?array
    {
        $shape = $this->shape($type);

        if ($shape === null) {
            return null;
        }

        $men = $rng->shuffle($men);
        $divasCompeting = $rng->shuffle($divasCompeting);
        $divasManaging = $rng->shuffle($divasManaging);

        // Diva managers: one per managed side.
        if (in_array($shape, [1, 2, 5, 6], true)) {
            $size = $type->sides[0];
            $sideCount = count($type->sides);

            if (count($men) < $size * $sideCount || count($divasManaging) < count($type->manager_sides)) {
                return null;
            }

            $sides = [];

            foreach (range(0, $sideCount - 1) as $i) {
                $sides[] = array_slice($men, $i * $size, $size);
            }

            $managers = [];

            foreach ($type->manager_sides as $i => $side) {
                $managers[(int) $side] = $divasManaging[$i];
            }

            return ['sides' => $sides, 'managers' => $managers];
        }

        // Mixed teams: each side the same number of Divas.
        $teamSize = $type->sides[0];
        $sideCount = count($type->sides);
        $options = $shape === 4 ? [1, 2] : [1];
        $options = array_values(array_filter($options, fn (int $k): bool => count($divasCompeting) >= $k * $sideCount && count($men) >= ($teamSize - $k) * $sideCount));

        if ($options === []) {
            return null;
        }

        $k = $rng->pick($options);
        $sides = [];

        foreach (range(0, $sideCount - 1) as $i) {
            $sides[] = [
                ...array_slice($men, $i * ($teamSize - $k), $teamSize - $k),
                ...array_slice($divasCompeting, $i * $k, $k),
            ];
        }

        return ['sides' => $sides, 'managers' => []];
    }

    /**
     * Whether a booking is single-gender (managers included) or one of the
     * seven shapes.
     *
     * @param  list<list<int>>  $sides
     * @param  array<int, int>  $managers
     * @param  list<int>  $divaIds
     */
    public function allows(MatchType $type, array $sides, array $managers, array $divaIds): bool
    {
        $isDiva = fn (int $id): bool => in_array($id, $divaIds, true);
        $everyone = [...array_merge(...$sides), ...array_values($managers)];
        $divaCount = count(array_filter($everyone, $isDiva));

        if ($divaCount === 0 || $divaCount === count($everyone)) {
            return true;
        }

        $shape = $this->shape($type);

        if ($shape === null) {
            return false;
        }

        $teamDivas = array_map(fn (array $side): int => count(array_filter($side, $isDiva)), $sides);

        if (in_array($shape, [1, 2, 5, 6], true)) {
            return array_sum($teamDivas) === 0
                && count($managers) === count($type->manager_sides)
                && array_filter(array_values($managers), fn (int $id): bool => ! $isDiva($id)) === [];
        }

        $first = $teamDivas[0];

        return $managers === []
            && count(array_unique($teamDivas)) === 1
            && $first >= 1
            && $first < count($sides[0])
            && ($shape === 4 || $first === 1);
    }
}
