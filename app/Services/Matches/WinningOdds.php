<?php

namespace App\Services\Matches;

use App\Enums\InjurySeverity;
use App\Enums\MatchFamily;
use App\Enums\PromoTypeKey;
use App\Enums\WeightClass;
use App\Models\Injury;
use App\Models\MatchSlot;
use App\Models\PromoSlot;
use App\Models\SaveWrestler;
use App\Models\WrestlerSpecialty;
use App\Services\Injuries\InjuryStatus;
use Illuminate\Support\Str;

/**
 * Winning odds (house rule, 2026-11-21; numbers in `gm.outcomes.winning_odds`).
 *
 * Every side starts with an equal share (50% each in a 1v1). Each side's
 * modifiers are percentage points: a side gains its own points, and every
 * other side's points come off it, split evenly. So a specialist in a 1v1
 * has 60% to 40%, and two specialists cancel out at 50% each. A side never
 * drops below `min_chance`; the shares are then scaled back to 100%.
 *
 * Modifiers: the match is a competitor's specialty (+10 per side), the
 * side's best win streak (5+, 10+, 20+), an Interview Space that aired for
 * a competitor this week (+5 succeeded, -5 failed), each competitor with a
 * minor injury (-10), and the weight class gap when every side is one class
 * (+5 for every class above the lightest side).
 *
 * The simulation rolls the winner on these chances; Book to Win ignores them.
 */
class WinningOdds
{
    public function __construct(private InjuryStatus $injuryStatus) {}

    /**
     * @param  array<int, list<int>>|null  $sides  only these sides (a Gauntlet bout)
     * @param  MatchFamily|null  $family  whose specialists count (default: the type's family)
     * @return array{sides: array<int, array{chance: float, points: int, factors: list<array{label: string, points: int}>}>}
     */
    public function forSlot(MatchSlot $slot, ?array $sides = null, ?MatchFamily $family = null): array
    {
        $sides ??= $slot->sides();
        $family ??= $slot->matchType?->family;
        $config = (array) config('gm.outcomes.winning_odds');
        $show = $slot->show;
        $week = (int) $show->week_number;

        /** @var array<int, SaveWrestler> $wrestlers */
        $wrestlers = [];

        foreach ($slot->competitors() as $p) {
            $wrestlers[$p->save_wrestler_id] = $p->saveWrestler;
        }

        $injuries = $this->injuryStatus->activeByWrestler((int) $show->save_id, $week);
        $interviews = $this->interviews((int) $show->save_id, $week, array_keys($wrestlers));
        $weights = $this->weightBonuses($sides, $wrestlers, (int) $config['weight_class_step']);

        $factors = [];

        foreach ($sides as $side => $ids) {
            $list = [];
            $members = array_values(array_filter(array_map(fn (int $id): ?SaveWrestler => $wrestlers[$id] ?? null, $ids)));

            if ($family !== null && $this->hasSpecialist($members, $family)) {
                $list[] = ['label' => $this->familyLabel($family).' specialist', 'points' => (int) $config['specialty']];
            }

            $streak = max([0, ...array_map(fn (SaveWrestler $w): int => (int) $w->win_streak, $members)]);
            $streakPoints = 0;
            $threshold = 0;

            foreach ((array) $config['streaks'] as $wins => $points) {
                if ($streak >= (int) $wins) {
                    $streakPoints = (int) $points;
                    $threshold = (int) $wins;
                }
            }

            if ($streakPoints > 0) {
                $list[] = ['label' => "{$threshold}+ wins in a row", 'points' => $streakPoints];
            }

            foreach ($members as $w) {
                foreach ($interviews[$w->id] ?? [] as $succeeded) {
                    $list[] = [
                        'label' => 'Interview Space '.($succeeded ? 'succeeded' : 'failed').": {$w->wrestler->name}",
                        'points' => $succeeded ? (int) $config['interview_space'] : -(int) $config['interview_space'],
                    ];
                }

                $injury = $injuries[$w->id] ?? null;

                if ($injury instanceof Injury && $injury->severity === InjurySeverity::Minor) {
                    $list[] = ['label' => "Minor injury: {$w->wrestler->name}", 'points' => (int) $config['minor_injury']];
                }
            }

            if (($weights[$side] ?? null) !== null) {
                $list[] = $weights[$side];
            }

            $factors[$side] = $list;
        }

        return ['sides' => $this->shares($factors, (float) $config['min_chance'])];
    }

    /**
     * Just the chances, for the roll.
     *
     * @param  array<int, list<int>>|null  $sides
     * @return array<int, float>
     */
    public function chances(MatchSlot $slot, ?array $sides = null, ?MatchFamily $family = null): array
    {
        return array_map(fn (array $s): float => $s['chance'], $this->forSlot($slot, $sides, $family)['sides']);
    }

    /**
     * Shares from points: each side's own points in, the others' points out
     * (split evenly), floored at the minimum and scaled back to 100%.
     *
     * @param  array<int, list<array{label: string, points: int}>>  $factors
     * @return array<int, array{chance: float, points: int, factors: list<array{label: string, points: int}>}>
     */
    private function shares(array $factors, float $min): array
    {
        $count = count($factors);
        $base = 100 / max(1, $count);
        $points = array_map(fn (array $list): int => (int) array_sum(array_column($list, 'points')), $factors);
        $total = array_sum($points);
        $raw = [];

        foreach ($points as $side => $own) {
            $others = $count > 1 ? ($total - $own) / ($count - 1) : 0;
            $raw[$side] = max($min, $base + $own - $others);
        }

        $sum = array_sum($raw);
        $result = [];

        foreach ($raw as $side => $share) {
            $result[$side] = [
                'chance' => round($share * 100 / $sum, 1),
                'points' => $points[$side],
                'factors' => $factors[$side],
            ];
        }

        return $result;
    }

    /**
     * The weight class gap (house rule): only when every side is one class.
     * Each side gets a step for every class it's above the lightest side.
     *
     * @param  array<int, list<int>>  $sides
     * @param  array<int, SaveWrestler>  $wrestlers
     * @return array<int, array{label: string, points: int}|null>
     */
    private function weightBonuses(array $sides, array $wrestlers, int $step): array
    {
        $order = array_flip(array_map(fn (WeightClass $c): string => $c->value, WeightClass::cases()));
        $classes = [];

        foreach ($sides as $side => $ids) {
            $sideClasses = array_values(array_unique(array_map(
                fn (int $id): ?string => isset($wrestlers[$id]) ? $wrestlers[$id]->wrestler->weight_class->value : null,
                $ids,
            )));

            if (count($sideClasses) !== 1 || $sideClasses[0] === null) {
                return [];
            }

            $classes[$side] = $sideClasses[0];
        }

        if ($classes === []) {
            return [];
        }

        $lightest = min(array_map(fn (string $c): int => $order[$c], $classes));
        $lightestLabel = $this->classLabel(WeightClass::cases()[$lightest]);
        $bonuses = [];

        foreach ($classes as $side => $class) {
            $gap = $order[$class] - $lightest;
            $bonuses[$side] = $gap > 0
                ? ['label' => $this->classLabel(WeightClass::from($class))." vs {$lightestLabel}", 'points' => $gap * $step]
                : null;
        }

        return $bonuses;
    }

    /**
     * Interview Space promos that have aired this week, by interviewee:
     * true for a success, false for a failure.
     *
     * @param  list<int>  $ids
     * @return array<int, list<bool>>
     */
    private function interviews(int $saveId, int $week, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $promos = PromoSlot::query()
            ->whereHas('show', fn ($q) => $q->where('save_id', $saveId)->where('week_number', $week))
            ->whereHas('promoType', fn ($q) => $q->where('key', PromoTypeKey::InterviewSpace->value))
            ->whereIn('target_wrestler_id', $ids)
            ->whereNotNull('succeeded')
            ->orderBy('id')
            ->get();

        $byWrestler = [];

        foreach ($promos as $promo) {
            $byWrestler[(int) $promo->target_wrestler_id][] = (bool) $promo->succeeded;
        }

        return $byWrestler;
    }

    /**
     * @param  list<SaveWrestler>  $members
     */
    private function hasSpecialist(array $members, MatchFamily $family): bool
    {
        foreach ($members as $w) {
            if ($w->wrestler->specialties->contains(fn (WrestlerSpecialty $s): bool => $s->family === $family)) {
                return true;
            }
        }

        return false;
    }

    private function familyLabel(MatchFamily $family): string
    {
        return match ($family) {
            MatchFamily::Single => 'Singles',
            default => $family->label(),
        };
    }

    private function classLabel(WeightClass $class): string
    {
        return Str::of($class->name)->headline()->toString();
    }
}
