<?php

namespace App\Services\Matches;

use App\Enums\FinishEvent;
use App\Enums\MatchMethod;
use App\Models\MatchParticipant;
use App\Models\MatchSlot;
use App\Models\Rivalry;
use App\Models\SaveWrestler;
use App\Models\WrestlerSpecialty;
use App\Support\Rng\Rng;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Placeholder outcome model (§20, §21): equal base weight per side, plus a
 * specialty bonus; roll the winner, then roll a finish event.
 *
 * Match rules (house rules): without ring outs the fight can't leave the ring,
 * so there are no count-outs or run-ins; rope breaks make clean submissions
 * rarer; in a Captain Fall match the losing side's captain takes the fall.
 */
class MatchResolver
{
    /**
     * @param  Collection<int, Rivalry>  $activeRivalries
     */
    public function resolve(MatchSlot $slot, Rng $rng, Collection $activeRivalries): ResolvedMatch
    {
        if ($slot->matchType === null) {
            throw new InvalidArgumentException('Slot has no match type.');
        }

        $sides = $slot->sides();

        if (count($sides) < 2) {
            throw new InvalidArgumentException('A match needs at least two sides.');
        }

        $before = $rng->steps();
        $winnerSide = (int) $rng->weightedPick($this->sideWeights($slot));
        $otherSides = array_values(array_filter(array_keys($sides), fn (int $s): bool => $s !== $winnerSide));
        $loserSide = count($otherSides) === 1 ? $otherSides[0] : (int) $rng->pick($otherSides);

        $winners = $sides[$winnerSide];
        $losers = array_merge(...array_map(fn (int $s): array => $sides[$s], $otherSides));
        $names = $this->names($slot);
        $winnerName = $this->sideName($sides[$winnerSide], $names);
        $loserName = $this->sideName($sides[$loserSide], $names);

        // The captain is the first wrestler booked on the side.
        if ($slot->matchType->captain_fall) {
            $loserName = "{$names[$sides[$loserSide][0]]}, the captain,";
        }

        $finish = null;
        $interferer = null;

        if (! $rng->chance((float) config('gm.outcomes.clean_finish_chance'))) {
            $candidates = $this->allowedFinishes($slot, $sides[$loserSide], $activeRivalries);

            if ($candidates !== []) {
                $weights = array_intersect_key((array) config('gm.outcomes.finish_event_weights'), array_flip(array_keys($candidates)));
                $finish = FinishEvent::from((string) $rng->weightedPick($weights));

                if ($finish === FinishEvent::RivalInterference) {
                    $interferer = (int) $rng->pick($candidates[$finish->value]);
                }
            }
        }

        [$method, $summary] = $finish === null
            ? $this->cleanFinish($slot, $rng, $winnerName, $loserName)
            : $this->eventFinish($slot, $finish, $winnerName, $loserName, $interferer === null ? null : ($names[$interferer] ?? $this->lookupName($interferer)));

        return new ResolvedMatch(
            $winnerSide,
            $loserSide,
            $winners,
            $losers,
            $method,
            $finish,
            $interferer,
            $summary,
            $rng->steps() - $before,
        );
    }

    /**
     * Win weight per side: base weight plus the specialty bonus, with bonuses
     * cancelling when several sides share the specialty (§21).
     *
     * @return array<int, float>
     */
    public function sideWeights(MatchSlot $slot): array
    {
        $family = $slot->matchType?->family;
        $base = (float) config('gm.outcomes.base_side_weight');
        $bonus = (float) config('gm.outcomes.specialty_bonus_weight');
        $specialists = [];

        foreach ($slot->sides() as $side => $ids) {
            $specialists[$side] = $slot->competitors()
                ->filter(fn (MatchParticipant $p): bool => $p->side === $side
                    && $p->saveWrestler->wrestler->specialties->contains(fn (WrestlerSpecialty $s): bool => $s->family === $family))
                ->count();
        }

        $shared = $specialists === [] ? 0 : min($specialists);

        return array_map(fn (int $count): float => $base + ($count - $shared) * $bonus, $specialists);
    }

    /**
     * Finish events allowed for this match, mapped to their candidate data.
     * Rival Interference maps to the ids of eligible interferers.
     *
     * @param  list<int>  $loserIds
     * @param  Collection<int, Rivalry>  $activeRivalries
     * @return array<string, list<int>>
     */
    public function allowedFinishes(MatchSlot $slot, array $loserIds, Collection $activeRivalries): array
    {
        $type = $slot->matchType;
        $allowed = [];

        if ($type === null) {
            return [];
        }

        if ($type->dq_enabled) {
            $allowed[FinishEvent::RefereeAttacked->value] = [];
        }

        $inMatch = $slot->participantIds();
        $interferers = [];

        foreach ($type->ring_out ? $activeRivalries : [] as $rivalry) {
            foreach ($loserIds as $loserId) {
                if ($rivalry->involves($loserId)) {
                    $other = $rivalry->otherThan($loserId);

                    if (! in_array($other, $inMatch, true)) {
                        $interferers[] = $other;
                    }
                }
            }
        }

        if ($interferers !== []) {
            $allowed[FinishEvent::RivalInterference->value] = array_values(array_unique($interferers));
        }

        $allowed[FinishEvent::ForeignObject->value] = [];

        if ($type->countout_enabled && $type->ring_out) {
            $allowed[FinishEvent::Countout->value] = [];
        }

        if ($this->decidedByPinfall($slot)) {
            $allowed[FinishEvent::CheapRollup->value] = [];
        }

        return $allowed;
    }

    public function decidedByPinfall(MatchSlot $slot): bool
    {
        return $slot->matchType?->win_condition === null;
    }

    /**
     * @return array{0: MatchMethod, 1: string}
     */
    private function cleanFinish(MatchSlot $slot, Rng $rng, string $winner, string $loser): array
    {
        $condition = $slot->matchType?->win_condition;

        if ($condition === 'Submission') {
            return [MatchMethod::Submission, "{$loser} taps out to {$winner}."];
        }

        if ($condition !== null) {
            return [MatchMethod::MatchSpecific, "{$winner} wins by {$this->lower($condition)}."];
        }

        $submissionChance = (float) config('gm.outcomes.submission_chance');

        if ($slot->matchType?->rope_breaks) {
            $submissionChance *= (float) config('gm.outcomes.rope_break_submission_factor');
        }

        if ($rng->chance($submissionChance)) {
            return [MatchMethod::Submission, "{$winner} makes {$loser} tap out."];
        }

        return [MatchMethod::Pinfall, "{$winner} pins {$loser} clean in the middle of the ring."];
    }

    /**
     * @return array{0: MatchMethod, 1: string}
     */
    private function eventFinish(MatchSlot $slot, FinishEvent $event, string $winner, string $loser, ?string $interferer): array
    {
        $dq = (bool) $slot->matchType?->dq_enabled;

        return match ($event) {
            FinishEvent::RefereeAttacked => [MatchMethod::Dq, "{$loser} shoves the referee to the mat. {$winner} wins by disqualification."],
            FinishEvent::RivalInterference => $dq
                ? [MatchMethod::Dq, "{$interferer} storms the ring and attacks {$loser}. The match is thrown out and {$winner} gets the win."]
                : [MatchMethod::Pinfall, "{$interferer} hits the ring and costs {$loser} the match. {$winner} covers for three."],
            FinishEvent::ForeignObject => $dq
                ? [MatchMethod::Dq, "{$loser} grabs a steel chair and gets disqualified. {$winner} wins."]
                : [MatchMethod::Pinfall, "{$winner} levels {$loser} with a steel chair and makes the cover."],
            FinishEvent::Countout => [MatchMethod::Countout, "The fight spills to the floor and {$loser} can't beat the ten count. {$winner} wins by count-out."],
            FinishEvent::CheapRollup => [MatchMethod::Pinfall, "{$winner} steals it with a roll-up and a handful of tights. The referee never sees it."],
        };
    }

    /**
     * @return array<int, string>
     */
    private function names(MatchSlot $slot): array
    {
        $names = [];

        foreach ($slot->participants as $p) {
            $names[$p->save_wrestler_id] = $p->saveWrestler->wrestler->name;
        }

        return $names;
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, string>  $names
     */
    private function sideName(array $ids, array $names): string
    {
        return implode(' & ', array_map(fn (int $id): string => $names[$id] ?? '?', $ids));
    }

    private function lookupName(int $saveWrestlerId): string
    {
        return (string) SaveWrestler::with('wrestler')->find($saveWrestlerId)?->wrestler->name;
    }

    private function lower(string $condition): string
    {
        return mb_strtolower($condition) === '10-count' ? 'the 10-count' : mb_strtolower($condition);
    }
}
