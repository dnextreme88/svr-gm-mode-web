<?php

use App\Enums\FinishEvent;
use App\Enums\MatchMethod;
use App\Enums\Stipulation;
use App\Exceptions\GameRuleException;
use App\Models\GauntletBout;
use App\Models\MatchResult;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Services\Matches\MatchResolver;
use App\Services\Week\WeekResolver;
use App\Support\Rng\SeededRng;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| Gauntlet (house rule, 2026-10-25; replaces Slobber Knocker): six entrants
| in one slot, five singles bouts. Entrants 1 and 2 open, each winner meets
| the next entrant, and the last bout's winner wins. One win for the winner,
| one loss for everyone else, a flat 3 fatigue bars each, no titles, no
| stipulations.
*/

const GAUNTLET = ['Jeff Hardy', 'Rob Van Dam', 'Edge', 'Matt Hardy', 'Triple H', 'Undertaker'];

/**
 * A Gauntlet as match 1 and a singles match after it, so the week is still
 * airing once the Gauntlet is decided.
 *
 * @return array{0: Save, 1: MatchSlot}
 */
function gauntletWeek(): array
{
    $save = GameBuilder::inSeason(raw: [...GAUNTLET, 'Kane', 'Carlito'], smackdown: ['Batista', 'JBL']);
    $show = Card::show($save);
    $slot = Card::book($save, $show, 1, Card::type('gauntlet', '6-Man Gauntlet'), array_map(fn (string $n): array => [$n], GAUNTLET));
    Card::book($save, $show, 2, Card::singles(), [['Kane'], ['Carlito']]);

    return [$save, $slot];
}

it('runs the bouts as a chain, booked one by one', function () {
    [$save, $slot] = gauntletWeek();
    $before = collect(GAUNTLET)->mapWithKeys(fn (string $n) => [$n => GameBuilder::wrestler($save, $n)->fatigue]);

    app(WeekResolver::class)->begin($save);
    // RVD beats Jeff and Edge, Matt Hardy beats RVD, Triple H and Undertaker.
    app(WeekResolver::class)->decide($save, $slot->id, null, [1, 1, 3, 3, 3]);

    $bouts = GauntletBout::where('match_slot_id', $slot->id)->orderBy('round')->get();
    $result = MatchResult::where('match_slot_id', $slot->id)->firstOrFail();

    expect($bouts->map(fn (GauntletBout $b) => [$b->side_a, $b->side_b, $b->winner_side])->all())->toBe([
        [0, 1, 1], [1, 2, 1], [1, 3, 3], [3, 4, 3], [3, 5, 3],
    ])
        ->and($bouts->every(fn (GauntletBout $b) => $b->booked))->toBeTrue()
        ->and($result->winner_side)->toBe(3)
        ->and($result->booked)->toBeTrue()
        ->and($result->summary)->toBe('Matt Hardy wins the Gauntlet, beating Rob Van Dam, Triple H and Undertaker.');

    foreach (GAUNTLET as $name) {
        $w = GameBuilder::wrestler($save, $name);
        $won = $name === 'Matt Hardy';

        expect([$w->wins, $w->losses])->toBe($won ? [1, 0] : [0, 1])
            ->and($w->fatigue)->toBe(min(100, $before[$name] + 30));
    }
});

it('refuses a pick for someone not in that bout', function () {
    [$save, $slot] = gauntletWeek();
    app(WeekResolver::class)->begin($save);

    expect(fn () => app(WeekResolver::class)->decide($save, $slot->id, null, [2]))->toThrow(GameRuleException::class, 'Bout 1')
        ->and(fn () => app(WeekResolver::class)->decide($save, $slot->id, null, [1, 0]))->toThrow(GameRuleException::class, 'Bout 2')
        ->and(fn () => app(WeekResolver::class)->decide($save, $slot->id, null, [1, null, 3]))->toThrow(GameRuleException::class, 'simulated too');
});

it('simulates the bouts left after the booked ones', function () {
    [$save, $slot] = gauntletWeek();
    app(WeekResolver::class)->begin($save);

    app(WeekResolver::class)->decide($save, $slot->id, null, [0, 0]);

    $bouts = GauntletBout::where('match_slot_id', $slot->id)->orderBy('round')->get();

    expect($bouts)->toHaveCount(5)
        ->and($bouts->pluck('booked')->all())->toBe([true, true, false, false, false])
        ->and($bouts[2]->side_a)->toBe(0)
        ->and(MatchResult::where('match_slot_id', $slot->id)->value('booked'))->toBeFalse();
});

it('plays each bout as a normal singles match, DQs included', function () {
    [, $slot] = gauntletWeek();
    config(['gm.outcomes.clean_finish_chance' => 0.0, 'gm.outcomes.finish_event_weights' => [FinishEvent::RefereeAttacked->value => 1]]);
    $slot = $slot->fresh(['matchType', 'participants.saveWrestler.wrestler.specialties', 'show']);

    $result = app(MatchResolver::class)->resolve($slot, new SeededRng('gauntlet-dq'), collect());

    expect($result->bouts)->toHaveCount(5)
        ->and(collect($result->bouts)->every(fn (array $b) => $b['method'] === MatchMethod::Dq))->toBeTrue()
        ->and($result->winners)->toHaveCount(1)
        ->and($result->losers)->toHaveCount(5);
});

it('takes no stipulation', function () {
    $save = GameBuilder::inSeason(raw: GAUNTLET, smackdown: ['Batista']);

    expect(fn () => Card::book($save, Card::show($save), 1, Card::type('gauntlet', '6-Man Gauntlet'), array_map(fn (string $n): array => [$n], GAUNTLET), [], null, Stipulation::LoserLeavesShow))
        ->toThrow(GameRuleException::class, "Gauntlet can't have a stipulation");
});
