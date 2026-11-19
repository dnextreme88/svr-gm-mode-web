<?php

use App\Enums\FinishEvent;
use App\Enums\MatchMethod;
use App\Enums\Stipulation;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\GauntletBout;
use App\Models\MatchResult;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Services\Card\Booking;
use App\Services\Card\CardService;
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

it('takes no stipulation but a #1 Contender’s Match', function () {
    $save = GameBuilder::inSeason(raw: GAUNTLET, smackdown: ['Batista']);

    expect(fn () => Card::book($save, Card::show($save), 1, Card::type('gauntlet', '6-Man Gauntlet'), array_map(fn (string $n): array => [$n], GAUNTLET), [], null, Stipulation::LoserLeavesShow))
        ->toThrow(GameRuleException::class, "Gauntlet can't have a Loser Leaves Show stipulation");
});

it('runs a 4-Man and a 5-Man Gauntlet in 3 and 4 bouts (house rule, 2026-11-08)', function (string $variant, int $entrants) {
    $save = GameBuilder::inSeason(raw: GAUNTLET, smackdown: ['Batista', 'JBL']);
    GameBuilder::passiveRival();
    $names = array_slice(GAUNTLET, 0, $entrants);
    $slot = Card::book($save, Card::show($save), 1, Card::type('gauntlet', $variant), array_map(fn (string $n): array => [$n], $names));

    app(WeekResolver::class)->resolve($save->refresh());

    $bouts = GauntletBout::where('match_slot_id', $slot->id)->orderBy('round')->get();
    $winner = MatchResult::where('match_slot_id', $slot->id)->sole()->winner_side;

    expect($bouts)->toHaveCount($entrants - 1)
        ->and($bouts->last()->winner_side)->toBe($winner)
        ->and($slot->refresh()->matchType->fatigue_cost_bars)->toBe(3);
})->with([
    ['4-Man Gauntlet', 4],
    ['5-Man Gauntlet', 5],
]);

/*
| Tornado Tag Turmoil (house rule, 2026-11-17): 5 tag teams, 4 tag bouts,
| the singles Gauntlets' rules otherwise; its #1 Contender's Match is for a
| tag title.
*/

const TURMOIL = [['Jeff Hardy', 'Matt Hardy'], ['Rob Van Dam', 'Edge'], ['Triple H', 'Undertaker'], ['Kane', 'Carlito'], ['Chris Masters', 'Shelton Benjamin']];

function turmoilSave(): Save
{
    GameBuilder::passiveRival();

    return GameBuilder::inSeason(raw: [...array_merge(...TURMOIL), 'Lance Cade', 'Trevor Murdoch', 'Eugene'], smackdown: ['Batista', 'JBL']);
}

it('runs a Tornado Tag Turmoil as 4 tag bouts between 5 teams', function () {
    $save = turmoilSave();
    $slot = Card::book($save, Card::show($save), 1, Card::type('gauntlet', 'Tornado Tag Turmoil'), TURMOIL);

    app(WeekResolver::class)->resolve($save->refresh());

    $bouts = GauntletBout::where('match_slot_id', $slot->id)->orderBy('round')->get();
    $result = MatchResult::where('match_slot_id', $slot->id)->sole();
    $winners = TURMOIL[$result->winner_side];

    expect($slot->refresh()->matchType->sides)->toBe([2, 2, 2, 2, 2])
        ->and($slot->matchType->fatigue_cost_bars)->toBe(3)
        ->and($bouts)->toHaveCount(4)
        ->and($bouts->last()->winner_side)->toBe($result->winner_side);

    // The record counts once: the winning team a win each, every other entrant a loss.
    foreach (array_merge(...TURMOIL) as $name) {
        $w = GameBuilder::wrestler($save, $name);

        expect([$w->wins, $w->losses])->toBe(in_array($name, $winners, true) ? [1, 0] : [0, 1]);
    }
});

it('books a Tornado Tag Turmoil bout by bout with Book to Win', function () {
    $save = turmoilSave();
    $show = Card::show($save);
    $slot = Card::book($save, $show, 1, Card::type('gauntlet', 'Tornado Tag Turmoil'), TURMOIL);
    Card::book($save, $show, 2, Card::singles(), [['Lance Cade'], ['Trevor Murdoch']]);

    app(WeekResolver::class)->begin($save);
    app(WeekResolver::class)->decide($save, $slot->id, null, [1, 2, 2, 4]);

    expect(GauntletBout::where('match_slot_id', $slot->id)->orderBy('round')->get()->map(fn (GauntletBout $b) => [$b->side_a, $b->side_b, $b->winner_side])->all())
        ->toBe([[0, 1, 1], [1, 2, 2], [2, 3, 2], [2, 4, 4]])
        ->and(MatchResult::where('match_slot_id', $slot->id)->value('winner_side'))->toBe(4);
});

it('makes a Tornado Tag Turmoil a #1 Contender’s Match for a tag title, not a singles one', function () {
    $save = turmoilSave();
    GameBuilder::giveTitle($save, 'world_tag_team', ['Lance Cade', 'Trevor Murdoch']);
    GameBuilder::giveTitle($save, 'intercontinental', ['Eugene']);
    $type = Card::type('gauntlet', 'Tornado Tag Turmoil');
    $ids = array_map(fn (array $side): array => array_map(fn (string $n): int => GameBuilder::wrestler($save, $n)->id, $side), TURMOIL);
    $tag = Championship::where('key', 'world_tag_team')->firstOrFail();
    $ic = Championship::where('key', 'intercontinental')->firstOrFail();
    $cards = app(CardService::class);

    $slot = $cards->book(Card::show($save), 1, new Booking($type, $ids, [], null, Stipulation::NumberOneContender, $tag->id));

    expect($slot->stipulation)->toBe(Stipulation::NumberOneContender)
        ->and(fn () => $cards->book(Card::show($save), 2, new Booking($type, $ids, [], null, Stipulation::NumberOneContender, $ic->id)))
        ->toThrow(GameRuleException::class, 'one superstar per side');
});
