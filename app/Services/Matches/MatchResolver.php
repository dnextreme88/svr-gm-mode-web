<?php

namespace App\Services\Matches;

use App\Enums\FinishEvent;
use App\Enums\MatchFamily;
use App\Enums\MatchMethod;
use App\Enums\RosterType;
use App\Enums\Weapon;
use App\Models\MatchParticipant;
use App\Models\MatchSlot;
use App\Models\Rivalry;
use App\Models\SaveWrestler;
use App\Models\WrestlerSpecialty;
use App\Services\Factions\FactionNamer;
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
 *
 * Book to Win (house rule): the player can pick the winning side on the
 * results screen. A booked title match always ends decisively; any other
 * booked match ends by DQ or count-out only on a small roll.
 *
 * Finish text (house rule) follows the type's win condition (`FinishText`)
 * and names the one wrestler the finish happens to.
 */
class MatchResolver
{
    public function __construct(private FactionNamer $factions) {}

    /**
     * @param  Collection<int, Rivalry>  $activeRivalries
     * @param  int|null  $bookedSide  the side the player booked to win, or null to simulate
     * @param  list<int|null>  $bookedBouts  Gauntlet only: the side booked to win each bout, or null to simulate it
     */
    public function resolve(MatchSlot $slot, Rng $rng, Collection $activeRivalries, ?int $bookedSide = null, array $bookedBouts = []): ResolvedMatch
    {
        if ($slot->matchType === null) {
            throw new InvalidArgumentException('Slot has no match type.');
        }

        $sides = $slot->sides();

        if (count($sides) < 2) {
            throw new InvalidArgumentException('A match needs at least two sides.');
        }

        if ($slot->matchType->family === MatchFamily::Gauntlet) {
            return $this->resolveGauntlet($slot, $rng, $activeRivalries, $bookedBouts);
        }

        if ($bookedSide !== null && ! array_key_exists($bookedSide, $sides)) {
            throw new InvalidArgumentException("Side {$bookedSide} is not in this match.");
        }

        return $this->decide($slot, $sides, $rng, $activeRivalries, $bookedSide);
    }

    /**
     * Gauntlet (house rule): entrants 1 and 2 open, each bout's winner faces
     * the next entrant, and whoever wins the last bout wins the Gauntlet.
     * Every bout is a normal singles match.
     *
     * @param  Collection<int, Rivalry>  $activeRivalries
     * @param  list<int|null>  $bookedBouts
     */
    public function resolveGauntlet(MatchSlot $slot, Rng $rng, Collection $activeRivalries, array $bookedBouts = []): ResolvedMatch
    {
        $sides = $slot->sides();
        $order = array_keys($sides);
        $before = $rng->steps();
        $names = $this->names($slot);
        $bouts = [];
        $beaten = [];
        $holder = $order[0];
        $last = null;

        foreach (array_slice($order, 1) as $round => $challenger) {
            $pair = [$holder => $sides[$holder], $challenger => $sides[$challenger]];
            $booked = $bookedBouts[$round] ?? null;

            if ($booked !== null && ! array_key_exists($booked, $pair)) {
                throw new InvalidArgumentException('Bout '.($round + 1)." is side {$holder} vs side {$challenger}; side {$booked} isn't in it.");
            }

            $last = $this->decide($slot, $pair, $rng, $activeRivalries, $booked, MatchFamily::Single);
            $bouts[] = [
                'round' => $round + 1,
                'side_a' => $holder,
                'side_b' => $challenger,
                'winner_side' => $last->winnerSide,
                'method' => $last->method,
                'finish_event' => $last->finishEvent,
                'interferer_id' => $last->interfererId,
                'booked' => $booked !== null,
                'summary' => $last->summary,
            ];

            if ($last->winnerSide === $holder) {
                $beaten[$holder][] = $challenger;
            } else {
                $beaten[$challenger] = [$holder];
            }

            $holder = $last->winnerSide;
        }

        if ($last === null) {
            throw new InvalidArgumentException('A Gauntlet needs at least two entrants.');
        }

        $losers = [];

        foreach ($sides as $side => $ids) {
            if ($side !== $holder) {
                array_push($losers, ...$ids);
            }
        }

        $victims = array_map(fn (int $side): string => $this->sideName($slot, $sides[$side]), $beaten[$holder]);
        $summary = "{$this->sideName($slot, $sides[$holder])} wins the Gauntlet, beating {$this->listNames($victims)}.";

        return new ResolvedMatch(
            $holder,
            $last->loserSide,
            $sides[$holder],
            $losers,
            $last->method,
            $last->finishEvent,
            $last->interfererId,
            $summary,
            $rng->steps() - $before,
            $bouts,
        );
    }

    /**
     * Roll one match (or one Gauntlet bout) between the given sides.
     *
     * @param  array<int, list<int>>  $sides
     * @param  Collection<int, Rivalry>  $activeRivalries
     * @param  MatchFamily|null  $specialtyFamily  whose specialists get the bonus (default: the type's family)
     */
    private function decide(MatchSlot $slot, array $sides, Rng $rng, Collection $activeRivalries, ?int $bookedSide, ?MatchFamily $specialtyFamily = null): ResolvedMatch
    {
        $before = $rng->steps();
        $winnerSide = $bookedSide ?? (int) $rng->weightedPick($this->sideWeights($slot, $sides, $specialtyFamily));
        $otherSides = array_values(array_filter(array_keys($sides), fn (int $s): bool => $s !== $winnerSide));
        $loserSide = count($otherSides) === 1 ? $otherSides[0] : (int) $rng->pick($otherSides);

        $winners = $sides[$winnerSide];
        $losers = array_merge(...array_map(fn (int $s): array => $sides[$s], $otherSides));

        $finish = null;
        $interferer = null;

        if ($bookedSide !== null) {
            $finish = $this->bookedFinishEvent($slot, $rng);
        } elseif (! $rng->chance((float) config('gm.outcomes.clean_finish_chance'))) {
            $candidates = $this->allowedFinishes($slot, $sides[$loserSide], $activeRivalries);

            if ($candidates !== []) {
                $weights = array_intersect_key((array) config('gm.outcomes.finish_event_weights'), array_flip(array_keys($candidates)));
                $finish = FinishEvent::from((string) $rng->weightedPick($weights));

                if ($finish === FinishEvent::RivalInterference) {
                    $interferer = (int) $rng->pick($candidates[$finish->value]);
                }
            }
        }

        // A clean finish decided by pinfall rolls for a submission.
        $submission = $finish === null && $this->decidedByPinfall($slot) && $rng->chance($this->submissionChance($slot));

        $captainFall = (bool) $slot->matchType?->captain_fall;
        $weapon = $finish === FinishEvent::ForeignObject || ($captainFall && $finish === FinishEvent::RivalInterference)
            ? $rng->pick(Weapon::cases())
            : null;

        // The finish happens to one wrestler: the captain in Captain Fall,
        // else one drawn from a team (drawn last, so the rolls above stay put).
        $loserIds = $sides[$loserSide];
        $victimId = $captainFall || count($loserIds) === 1 ? $loserIds[0] : (int) $rng->pick($loserIds);

        $names = $this->names($slot);
        $text = new FinishText(
            $captainFall ? ($names[$winners[0]] ?? '?') : $this->sideName($slot, $winners),
            ! $captainFall && count($winners) > 1,
            $names[$victimId] ?? '?',
            $this->isDiva($slot, $victimId),
            ['{weeks}' => (string) config('gm.buried_alive.sit_out_weeks')],
        );

        // House rule: a Submission specialist wins a clean finish by submission.
        [$method, $summary] = $finish === null
            ? $this->cleanFinish($slot, $text, $submission || $this->hasSubmissionSpecialist($slot, $winnerSide))
            : $this->eventFinish($slot, $finish, $text, $interferer === null ? null : ($names[$interferer] ?? $this->lookupName($interferer)), $weapon);

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
     * A booked win ends decisively, except a non-title match may end by DQ or
     * count-out (when the type allows them) on a small roll.
     */
    private function bookedFinishEvent(MatchSlot $slot, Rng $rng): ?FinishEvent
    {
        $type = $slot->matchType;

        if ($type === null || $slot->title_championship_id !== null) {
            return null;
        }

        $events = array_values(array_filter([
            $type->dq_enabled ? FinishEvent::RefereeAttacked : null,
            $type->countout_enabled && $type->ring_out ? FinishEvent::Countout : null,
        ]));

        if ($events === [] || ! $rng->chance((float) config('gm.outcomes.booked_non_decisive_chance'))) {
            return null;
        }

        return $rng->pick($events);
    }

    /**
     * Win weight per side: base weight plus the specialty bonus, with bonuses
     * cancelling when several sides share the specialty (§21).
     *
     * @param  array<int, list<int>>|null  $sides  only these sides (a Gauntlet bout)
     * @return array<int, float>
     */
    public function sideWeights(MatchSlot $slot, ?array $sides = null, ?MatchFamily $family = null): array
    {
        $family ??= $slot->matchType?->family;
        $base = (float) config('gm.outcomes.base_side_weight');
        $bonus = (float) config('gm.outcomes.specialty_bonus_weight');
        $specialists = [];

        foreach ($sides ?? $slot->sides() as $side => $ids) {
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

    private function submissionChance(MatchSlot $slot): float
    {
        $chance = (float) config('gm.outcomes.submission_chance');

        if ($slot->matchType?->rope_breaks) {
            $chance *= (float) config('gm.outcomes.rope_break_submission_factor');
        }

        return $chance;
    }

    /**
     * A clean finish in the type's own terms (house rule): its win condition,
     * the last elimination, or the captain taking the fall.
     *
     * @return array{0: MatchMethod, 1: string}
     */
    private function cleanFinish(MatchSlot $slot, FinishText $text, bool $submission): array
    {
        $type = $slot->matchType;
        $condition = $type?->win_condition;

        if ($condition !== null) {
            return [$condition === 'Submission' ? MatchMethod::Submission : MatchMethod::MatchSpecific, $text->clean($condition)];
        }

        $method = $submission ? MatchMethod::Submission : MatchMethod::Pinfall;

        if ($type?->captain_fall) {
            return [$method, $text->render($submission
                ? '{W} makes {L}, the captain, tap out to eliminate {his} team.'
                : '{W} pins {L}, the captain, to eliminate {his} team.')];
        }

        if ($this->isElimination($slot)) {
            return [$method, $text->render('{W} last [eliminates|eliminate] {L} via '.($submission ? 'submission' : 'pinfall').'.')];
        }

        return [$method, $text->render($submission ? '{W} [makes|make] {L} tap out.' : '{W} [pins|pin] {L} clean in the middle of the ring.')];
    }

    /**
     * Battle Royals and elimination matches end with the last elimination.
     */
    private function isElimination(MatchSlot $slot): bool
    {
        return $slot->matchType !== null && ($slot->matchType->is_elimination || $slot->matchType->family === MatchFamily::BattleRoyal);
    }

    /**
     * Whether anyone on the side has the Submission specialty.
     */
    private function hasSubmissionSpecialist(MatchSlot $slot, int $side): bool
    {
        return $slot->competitors()->contains(fn (MatchParticipant $p): bool => $p->side === $side
            && $p->saveWrestler->wrestler->specialties->contains(fn (WrestlerSpecialty $s): bool => $s->family === MatchFamily::Submission));
    }

    /**
     * A finish event. Without DQs, a weapon shot or a run-in still has to end
     * the match the type's way: through a table, off the ladder, a 10-count…
     *
     * @return array{0: MatchMethod, 1: string}
     */
    private function eventFinish(MatchSlot $slot, FinishEvent $event, FinishText $text, ?string $interferer, ?Weapon $weapon): array
    {
        $dq = (bool) $slot->matchType?->dq_enabled;
        $condition = $slot->matchType?->win_condition;
        $weapon ??= Weapon::SteelChair;

        if ($slot->matchType?->captain_fall) {
            return $this->captainFallEvent($event, $text, $dq, $weapon);
        }

        $method = match ($condition) {
            null => MatchMethod::Pinfall,
            'Submission' => MatchMethod::Submission,
            default => MatchMethod::MatchSpecific,
        };

        return match ($event) {
            FinishEvent::RefereeAttacked => [MatchMethod::Dq, $text->render('{L} shoves the referee to the mat. {W} [wins|win] by disqualification.')],
            FinishEvent::RivalInterference => $dq
                ? [MatchMethod::Dq, $text->render("{$interferer} storms the ring and attacks {L}. The match is thrown out and {W} [gets|get] the win.")]
                : [$method, $text->render("{$interferer} hits the ring and costs {L} the match. {W} ").$text->clause($condition, interference: true).'.'],
            FinishEvent::ForeignObject => $dq
                ? [MatchMethod::Dq, $text->render("{L} grabs {$weapon->noun()} and gets disqualified. {W} [wins|win].")]
                : [$method, $text->render("{W} {$weapon->attack()} and ").$text->clause($condition).'.'],
            FinishEvent::Countout => [MatchMethod::Countout, $text->render("The fight spills to the floor and {L} can't beat the ten count. {W} [wins|win] by count-out.")],
            FinishEvent::CheapRollup => [MatchMethod::Pinfall, $text->render('{W} [steals|steal] it with a roll-up and a handful of tights. The referee never sees it.')],
        };
    }

    /**
     * Captain Fall (house rule): a DQ or count-out on the captain eliminates
     * their whole team. Here {W} is the winning captain.
     *
     * @return array{0: MatchMethod, 1: string}
     */
    private function captainFallEvent(FinishEvent $event, FinishText $text, bool $dq, Weapon $weapon): array
    {
        return match ($event) {
            FinishEvent::RefereeAttacked => [MatchMethod::Dq, $text->render("{W}'s team wins because {L}, the captain of the opposing team, shoves the referee and is disqualified.")],
            FinishEvent::RivalInterference, FinishEvent::ForeignObject => $dq
                ? [MatchMethod::Dq, $text->render("{W}'s team wins as {L}, the captain of the opposing team, causes a DQ by using {$weapon->noun()}.")]
                : [MatchMethod::Pinfall, $text->render("{W} {$weapon->attack()} and pins {him}, the captain, to eliminate {his} team.")],
            FinishEvent::Countout => [MatchMethod::Countout, $text->render("{W}'s team wins as {L}, the captain of the opposing team, deliberately gets counted-out.")],
            FinishEvent::CheapRollup => [MatchMethod::Pinfall, $text->render('{W} steals it with a roll-up on {L}, the captain, and a handful of tights.')],
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

    private function isDiva(MatchSlot $slot, int $saveWrestlerId): bool
    {
        return $slot->participants->first(fn (MatchParticipant $p): bool => $p->save_wrestler_id === $saveWrestlerId)?->saveWrestler->wrestler->roster_type === RosterType::Diva;
    }

    /**
     * A side's name, with factions named as such (house rule).
     *
     * @param  list<int>  $ids
     */
    private function sideName(MatchSlot $slot, array $ids): string
    {
        return $this->factions->name($slot->show->save_id, $ids, $slot->show->week_number);
    }

    /**
     * "A", "A and B", "A, B and C".
     *
     * @param  list<string>  $names
     */
    private function listNames(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names)." and {$last}";
    }

    private function lookupName(int $saveWrestlerId): string
    {
        return (string) SaveWrestler::with('wrestler')->find($saveWrestlerId)?->wrestler->name;
    }
}
