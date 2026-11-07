<?php

namespace App\Services\Matches\Stories;

use App\Enums\ConditionalStoryOutcome;
use App\Enums\ParticipantRole;
use App\Enums\PromoTypeKey;
use App\Models\MatchParticipant;
use App\Models\PromoSlot;
use App\Models\Rivalry;
use App\Models\Show;
use App\Services\Matches\ForcedOutcome;
use App\Services\Matches\ResolvedMatch;
use App\Support\Rng\Rng;

/**
 * Undertaker's lights-out and Kane's pyro (house rule, 2026-11-07): a
 * helper interferes in their rival's match without being in it.
 *
 * It happens in a simulated match the rival competes in when the rivalry is
 * at `min_heat`+, a Continue Rivalry promo for it is booked on any of the
 * brand's cards this week, the two don't wrestle each other this week, and
 * the story hasn't succeeded in this rivalry yet. The rival loses at
 * `win_chance` (success); else they win anyway (failure), and the next
 * eligible match tries again. After a success, never again in that rivalry.
 */
abstract class RivalryInterference extends StoryRule
{
    /** The `gm.stories.*` key. */
    abstract protected function configKey(): string;

    /**
     * The picks the text needs (a move, a location…).
     *
     * @return array<string, string>
     */
    abstract protected function picks(StoryMatch $match, Rng $rng, int $winnerId): array;

    public function before(StoryMatch $match): ?StoryPlan
    {
        $save = $match->save();
        $config = (array) config('gm.stories.'.$this->configKey());
        $helper = $this->wrestler($save, (string) $config['helper']);

        if ($helper === null || $this->excluded($match)) {
            return null;
        }

        foreach ($match->rivalries as $rivalry) {
            if (! $rivalry->involves($helper->id) || $rivalry->heat < (int) $config['min_heat']) {
                continue;
            }

            $rivalId = $rivalry->otherThan($helper->id);
            $rivalSide = $match->sideOf($rivalId);

            if ($rivalSide === null
                || ! $this->available($helper, $match->brandOf($rivalId), $match)
                || $this->lastTrigger($save, ConditionalStoryOutcome::Success, $rivalry->id) !== null
                || ! $this->continuedThisWeek($match, $rivalry)
                || $this->meetThisWeek($match, $helper->id, $rivalId)) {
                continue;
            }

            return $this->plan($match, $rivalry, $helper->id, $rivalId, $rivalSide, (float) $config['win_chance']);
        }

        return null;
    }

    public function text(StoryMatch $match, ResolvedMatch $resolved, StoryPlan $plan): StoryText
    {
        $rivalId = (int) $plan->data['rival'];
        $rival = $match->name($rivalId);
        $helper = $match->name((int) $plan->data['helper']);
        $key = $this->key($plan);
        $scorer = (int) ($plan->data['scorer'] ?? $resolved->winners[0]);
        $bout = null;

        // A Gauntlet: it played out in the rival's first bout.
        foreach ($resolved->bouts as $b) {
            if (in_array($match->sideOf($rivalId), [$b['side_a'], $b['side_b']], true)) {
                $bout = $b['round'];
                $scorer = $match->sides()[$b['winner_side']][0];

                break;
            }
        }

        $tokens = [
            'rival' => $rival,
            'scorer' => $match->name($scorer),
            'match_word' => $match->matchWord(),
            ...$match->pronouns('rival', $rivalId),
            ...array_map('strval', (array) ($plan->data['picks'] ?? [])),
        ];
        $success = $plan->outcome === ConditionalStoryOutcome::Success;

        return new StoryText(
            ConditionalStoryTexts::title($key),
            ConditionalStoryTexts::render($key, $tokens),
            $success
                ? "{$helper} decided this {$match->matchWord()}: {$rival} ".($bout === null ? 'lost' : "was eliminated in bout {$bout}").". {$rival} vows revenge to settle things with {$helper}."
                : "Nothing: {$rival} won the {$match->matchWord()} anyway.",
            $success ? 'Rivalry' : 'Next chance',
            $success
                ? "This won't happen again between {$helper} and {$rival}."
                : "{$rival}'s next eligible match, while the rivalry stays at ".config('gm.stories.'.$this->configKey().'.min_heat').'+ heat.',
        );
    }

    private function plan(StoryMatch $match, Rivalry $rivalry, int $helperId, int $rivalId, int $rivalSide, float $winChance): StoryPlan
    {
        $rng = $match->rng($this->story()->value);
        $success = $rng->chance($winChance);

        // The winner: the rival on a failure; a random other side on a success.
        $others = array_values(array_filter(array_keys($match->sides()), fn (int $s): bool => $s !== $rivalSide));
        $winnerSide = $success ? (int) $rng->pick($others) : $rivalSide;
        $winners = $match->sides()[$winnerSide];
        $scorer = count($winners) === 1 ? $winners[0] : (int) $rng->pick($winners);

        return new StoryPlan(
            $this->story(),
            $success ? ConditionalStoryOutcome::Success : ConditionalStoryOutcome::Failure,
            $success
                ? new ForcedOutcome(winnerSide: $winnerSide, loserSide: $rivalSide, victimId: $rivalId, scorerId: $scorer)
                : new ForcedOutcome(winnerSide: $rivalSide, scorerId: $rivalId),
            ['helper' => $helperId, 'rival' => $rivalId, 'scorer' => $scorer, 'picks' => $this->picks($match, $rng, $scorer)],
            rivalryId: $rivalry->id,
        );
    }

    /**
     * A Continue Rivalry promo for this pair is booked on any of the
     * brand's cards this week.
     */
    private function continuedThisWeek(StoryMatch $match, Rivalry $rivalry): bool
    {
        $pair = [$rivalry->wrestler_a_id, $rivalry->wrestler_b_id];
        sort($pair);

        return PromoSlot::with('promoType')
            ->whereIn('show_id', $this->weekShowIds($match))
            ->get()
            ->contains(function (PromoSlot $p) use ($pair): bool {
                $ids = [(int) $p->target_wrestler_id, (int) $p->target_wrestler_b_id];
                sort($ids);

                return $p->promoType?->key === PromoTypeKey::ContinueRivalry && $ids === $pair;
            });
    }

    /**
     * The two wrestle each other in a match this week.
     */
    private function meetThisWeek(StoryMatch $match, int $helperId, int $rivalId): bool
    {
        return MatchParticipant::query()
            ->join('match_slots', 'match_slots.id', '=', 'match_participants.match_slot_id')
            ->whereIn('match_slots.show_id', $this->weekShowIds($match))
            ->where('match_participants.role', ParticipantRole::Competitor->value)
            ->whereIn('match_participants.save_wrestler_id', [$helperId, $rivalId])
            ->get(['match_participants.match_slot_id', 'match_participants.save_wrestler_id'])
            ->groupBy('match_slot_id')
            ->contains(fn ($rows): bool => $rows->pluck('save_wrestler_id')->unique()->count() === 2);
    }

    /**
     * @return list<int>
     */
    private function weekShowIds(StoryMatch $match): array
    {
        return array_values(array_map('intval', Show::where('save_id', $match->save()->id)
            ->where('week_number', $match->week())
            ->pluck('id')
            ->all()));
    }
}
