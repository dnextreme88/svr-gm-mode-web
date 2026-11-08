<?php

namespace App\Services\Matches\Stories;

use App\Enums\ConditionalStoryOutcome;
use App\Enums\ParticipantRole;
use App\Models\MatchParticipant;
use App\Services\Matches\ForcedOutcome;
use App\Services\Matches\ResolvedMatch;
use App\Support\Rng\Rng;

/**
 * The Dudley Boyz' tables and Team Xtreme's ladder (house rule,
 * 2026-11-07): members of an active faction teaming on one side go for
 * their spot in every simulated match outside the cooldown.
 *
 * It lands at `land_chance`. Then they win at `win_chance` (always in the
 * `win_family` match), else the match simulates as normal. A spot that
 * doesn't land changes nothing, and they try again next match. The cooldown
 * starts only when it lands.
 */
abstract class TeamSpot extends StoryRule
{
    /** The `gm.stories.*` key. */
    abstract protected function configKey(): string;

    /** How many of the members must team on one side. */
    abstract protected function minMembers(): int;

    /** "The Dudleys", "Team Xtreme". */
    abstract protected function teamName(): string;

    /**
     * Who does what, chosen from the members on the side.
     *
     * @param  list<int>  $members
     * @return array<string, int|string|bool>
     */
    abstract protected function roles(StoryMatch $match, Rng $rng, array $members): array;

    /**
     * The tokens for the text, from the roles.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    abstract protected function tokens(StoryMatch $match, array $data): array;

    /**
     * The template key's suffix ("_lita" for Team Xtreme with Lita).
     *
     * @param  array<string, mixed>  $data
     */
    protected function variant(array $data): string
    {
        return '';
    }

    public function before(StoryMatch $match): ?StoryPlan
    {
        $save = $match->save();
        $config = (array) config('gm.stories.'.$this->configKey());
        $faction = $this->factionActive($save, (string) $config['faction'], $match->week());

        if ($faction === null || $this->excluded($match)
            || $this->coolingDown($this->lastTrigger($save, ConditionalStoryOutcome::Success), (int) $config['cooldown_weeks'], $match->week())) {
            return null;
        }

        $memberIds = array_values(array_filter(array_map(
            fn (string $slug): ?int => $this->wrestler($save, $slug)?->id,
            (array) $config['members'],
        )));

        foreach ($match->sides() as $side => $ids) {
            $members = array_values(array_intersect($memberIds, $ids));

            if (count($members) < $this->minMembers()) {
                continue;
            }

            $opponents = array_merge(...array_values(array_diff_key($match->sides(), [$side => true])));

            if ($opponents === []) {
                return null;
            }

            $rng = $match->rng($this->story()->value);
            $lands = $rng->chance((float) $config['land_chance']);
            $victim = (int) $rng->pick($opponents);
            $data = ['side' => $side, 'members' => $members, 'victim' => $victim, ...$this->roles($match, $rng, $members)];
            $forced = null;

            if ($lands) {
                $wins = $match->family()?->value === $config['win_family'] || $rng->chance((float) $config['win_chance']);
                $data['opening'] = $this->refereeOpening($match, $rng, 'the chaos outside the ring');

                if ($wins) {
                    $forced = new ForcedOutcome(winnerSide: $side, loserSide: $match->sideOf($victim), victimId: $victim, scorerId: (int) ($data['scorer'] ?? $members[0]));
                }
            }

            return new StoryPlan(
                $this->story(),
                $lands ? ConditionalStoryOutcome::Success : ConditionalStoryOutcome::Failure,
                $forced,
                $data,
                factionId: $faction->id,
            );
        }

        return null;
    }

    public function text(StoryMatch $match, ResolvedMatch $resolved, StoryPlan $plan): StoryText
    {
        $data = $plan->data;
        $side = (int) $data['side'];
        $won = $resolved->winnerSide === $side;
        $team = $this->teamName();
        // "The Dudleys" starts a sentence; "the Dudleys" goes after a colon.
        $teamMid = str_starts_with($team, 'The ') ? 'the'.substr($team, 3) : $team;
        $victim = $match->name((int) $data['victim']);
        $success = $plan->outcome === ConditionalStoryOutcome::Success;
        $cooldown = (int) config('gm.stories.'.$this->configKey().'.cooldown_weeks');
        $key = $this->key($plan, $this->variant($data));

        $tokens = [
            'victim' => $victim,
            'opening' => (string) ($data['opening'] ?? ''),
            'outcome' => match (true) {
                $plan->forced !== null => "{$team} made it count and took the win.",
                $won => "{$team} went on to win the match.",
                default => "Even so, {$victim} recovered in time to help win the match.",
            },
            ...$match->pronouns('victim', (int) $data['victim']),
            ...$this->tokens($match, $data),
        ];

        return new StoryText(
            ConditionalStoryTexts::title($key),
            ConditionalStoryTexts::render($key, $tokens),
            match (true) {
                $plan->forced !== null => "The spot decided the match: {$teamMid} won.",
                $success && ! $won => "The spot landed, but {$teamMid} still lost.",
                $success => "The spot landed, and {$teamMid} won as simulated.",
                default => 'Nothing: the match went on as normal.',
            },
            'Next chance',
            $success
                ? "{$this->weekLabel($match->week() + $cooldown, $match->week())} at the earliest. It can't happen again for {$cooldown} weeks."
                : 'Their next match together.',
        );
    }

    /**
     * Whether someone is on the side as a competitor or its manager.
     */
    protected function onSide(StoryMatch $match, int $id, int $side): bool
    {
        return $match->slot->participants->contains(fn (MatchParticipant $p): bool => (int) $p->save_wrestler_id === $id
            && $p->side === $side
            && in_array($p->role, [ParticipantRole::Competitor, ParticipantRole::Manager], true));
    }
}
