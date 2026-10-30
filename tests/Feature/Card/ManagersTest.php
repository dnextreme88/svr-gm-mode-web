<?php

use App\Exceptions\GameRuleException;
use App\Http\Presenters\CardPresenter;
use App\Models\MatchSlot;
use App\Services\Card\CardRandomizer;
use App\Services\Week\WeekResolver;
use App\Support\Rng\SeededRng;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| Managers: every manager slot of a w/Manager type must be filled, and the
| results name each side's manager ("Snitsky w/ Eugene").
*/

it('refuses a w/Manager match without its manager', function () {
    $save = GameBuilder::inSeason(raw: ['Shelton Benjamin', 'Snitsky', 'Eugene'], smackdown: ['Batista']);

    Card::book($save, Card::show($save), 1, Card::type('single', 'No Manager vs. w/Manager'), [['Shelton Benjamin'], ['Snitsky']]);
})->throws(GameRuleException::class, 'needs a manager for each w/Manager side');

it('books a w/Manager match once the manager is in', function () {
    $save = GameBuilder::inSeason(raw: ['Shelton Benjamin', 'Snitsky', 'Eugene'], smackdown: ['Batista']);

    $slot = Card::book($save, Card::show($save), 1, Card::type('single', 'No Manager vs. w/Manager'), [['Shelton Benjamin'], ['Snitsky']], [1 => 'Eugene']);

    expect($slot->participants()->where('role', 'manager')->count())->toBe(1);
});

it('has Randomize fill every manager slot of a w/Manager type', function () {
    $save = GameBuilder::inSeason(size: 12);
    $type = Card::type('single', 'w/Manager vs. w/Manager');

    foreach (range(1, 10) as $seed) {
        $proposal = app(CardRandomizer::class)->proposeSlot(Card::show($save), 1, $type, new SeededRng("mgr-{$seed}"));
        $managers = $proposal->booking->managers;

        expect(array_keys($managers))->toEqualCanonicalizing([0, 1])
            ->and(array_intersect($managers, $proposal->booking->competitorIds()))->toBe([])
            ->and(count(array_unique($managers)))->toBe(2)
            ->and($proposal->toArray()['managers'])->toBe($managers);
    }
});

it('names each side’s manager on the results', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Shelton Benjamin', 'Snitsky', 'Eugene'], smackdown: ['Batista']);
    $slot = Card::book($save, Card::show($save), 1, Card::type('single', 'No Manager vs. w/Manager'), [['Shelton Benjamin'], ['Snitsky']], [1 => 'Eugene']);
    FixedOutcomes::queue('Shelton Benjamin');

    app(WeekResolver::class)->resolve($save->refresh());

    $outcome = CardPresenter::outcome(MatchSlot::with(['participants.saveWrestler.wrestler', 'result.championReign', 'matchType', 'show', 'gauntletBouts'])->find($slot->id));

    expect($outcome['winners'])->toBe(['Shelton Benjamin'])
        ->and($outcome['winner_manager'])->toBeNull()
        ->and($outcome['loser_sides'])->toBe([['Snitsky']])
        ->and($outcome['loser_side_managers'])->toBe(['Eugene']);
});

it('gives a manager the win or the loss with their side, but no streak or fatigue', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Shelton Benjamin', 'Snitsky', 'Eugene', 'Kane'], smackdown: ['Batista']);
    Card::book($save, Card::show($save), 1, Card::type('single', 'w/Manager vs. w/Manager'), [['Shelton Benjamin'], ['Snitsky']], [0 => 'Kane', 1 => 'Eugene']);
    FixedOutcomes::queue('Shelton Benjamin');

    app(WeekResolver::class)->resolve($save->refresh());

    $kane = GameBuilder::wrestler($save, 'Kane');
    $eugene = GameBuilder::wrestler($save, 'Eugene');

    expect([$kane->wins, $kane->losses])->toBe([1, 0])
        ->and([$eugene->wins, $eugene->losses])->toBe([0, 1])
        ->and([$kane->win_streak, $eugene->loss_streak])->toBe([0, 0])
        ->and([$kane->fatigue, $eugene->fatigue])->toBe([0, 0]);
});

it('names each side’s manager on a match still to be decided', function () {
    $save = GameBuilder::inSeason(raw: ['Shelton Benjamin', 'Snitsky', 'Eugene'], smackdown: ['Batista']);
    $slot = Card::book($save, Card::show($save), 1, Card::type('single', 'No Manager vs. w/Manager'), [['Shelton Benjamin'], ['Snitsky']], [1 => 'Eugene']);

    $pending = CardPresenter::pending(MatchSlot::with(['participants.saveWrestler.wrestler', 'matchType', 'show'])->find($slot->id), true, []);

    expect(array_column($pending['sides'], 'manager'))->toBe([null, 'Eugene']);
});
