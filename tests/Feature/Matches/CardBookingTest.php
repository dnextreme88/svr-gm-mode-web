<?php

use App\Enums\Brand;
use App\Enums\InjurySeverity;
use App\Enums\ShowType;
use App\Exceptions\GameRuleException;
use App\Models\MatchSlot;
use App\Models\PromoSlot;
use App\Services\Injuries\InjuryService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| §4 Card Building.
*/

it('gives weekly shows 6 match slots and 2 promo slots, and PPVs 5 and none', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $weekly = Card::show($save);
    // Backlash (Raw) is week 4.
    $ppv = Card::show($save, Brand::Raw, ShowType::Ppv, 4);

    expect(MatchSlot::where('show_id', $weekly->id)->count())->toBe(6)
        ->and(PromoSlot::where('show_id', $weekly->id)->count())->toBe(2)
        ->and(MatchSlot::where('show_id', $ppv->id)->count())->toBe(5)
        ->and(PromoSlot::where('show_id', $ppv->id)->count())->toBe(0);
});

it('has no per-card limit on how many matches a wrestler is booked in', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito', 'Kurt Angle'], smackdown: ['Batista']);
    $show = Card::show($save);

    Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kane']]);
    Card::book($save, $show, 2, Card::singles(), [['Edge'], ['Carlito']]);
    Card::book($save, $show, 3, Card::singles(), [['Edge'], ['Kurt Angle']]);

    expect(MatchSlot::where('show_id', $show->id)->whereNotNull('match_type_id')->count())->toBe(3);
});

it('enforces the match type competitor count', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);

    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane', 'Carlito']]);
})->throws(GameRuleException::class);

it('blocks seriously injured wrestlers as competitors and as managers', function (string $role) {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Ric Flair'], smackdown: ['Batista']);
    app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Ric Flair'), InjurySeverity::Serious, 1, $save->rng('t'));
    $show = Card::show($save);

    $role === 'competitor'
        ? Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Ric Flair']])
        : Card::book($save, $show, 1, Card::type('single', 'No Manager vs. w/Manager'), [['Edge'], ['Kane']], [1 => 'Ric Flair']);
})->with(['competitor', 'manager'])->throws(GameRuleException::class, 'seriously injured');

it('lets minor-injured wrestlers be booked normally', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Kane'), InjurySeverity::Minor, 1, $save->rng('t'));

    $slot = Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);

    expect($slot->isBooked())->toBeTrue();
});

it('blocks wrestlers sitting out a blocked defection', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    GameBuilder::wrestler($save, 'Kane')->update(['unbookable_until_week' => 3]);

    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);
})->throws(GameRuleException::class, 'sitting out');

it('lets anyone on the roster manage, but not a match they compete in', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Ric Flair'], smackdown: ['Batista']);
    $show = Card::show($save);
    $type = Card::type('single', 'No Manager vs. w/Manager');

    $slot = Card::book($save, $show, 1, $type, [['Edge'], ['Kane']], [1 => 'Ric Flair']);
    expect($slot->managers())->toHaveCount(1);

    // Flair can compete in another match on the same card.
    Card::book($save, $show, 2, Card::singles(), [['Ric Flair'], ['Edge']]);

    expect(fn () => Card::book($save, $show, 3, $type, [['Edge'], ['Kane']], [1 => 'Kane']))
        ->toThrow(GameRuleException::class, 'manager');
});

it('only allows managers on w/Manager sides', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Ric Flair'], smackdown: ['Batista']);

    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']], [1 => 'Ric Flair']);
})->throws(GameRuleException::class);

it('keeps Divas-only types to Divas and Divas out of Money in the Bank', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Trish Stratus', 'Lita'], smackdown: ['Batista']);
    $show = Card::show($save);

    expect(fn () => Card::book($save, $show, 1, Card::type('fulfill_your_fantasy', 'Single'), [['Edge'], ['Lita']]))->toThrow(GameRuleException::class, 'Divas only');

    $slot = Card::book($save, $show, 1, Card::type('fulfill_your_fantasy', 'Single'), [['Trish Stratus'], ['Lita']]);
    expect($slot->isBooked())->toBeTrue();
});

it('allows PPV-exclusive types only on their PPV and PPV-only types only on PPVs', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $weekly = Card::show($save);
    $backlash = Card::show($save, Brand::Raw, ShowType::Ppv, 4);
    $buried = Card::type('buried_alive', 'Single');

    expect(fn () => Card::book($save, $weekly, 1, $buried, [['Edge'], ['Kane']]))->toThrow(GameRuleException::class)
        ->and(fn () => Card::book($save, $backlash, 1, $buried, [['Edge'], ['Kane']]))->toThrow(GameRuleException::class, 'exclusive');

    $rumble = Card::type('royal_rumble', '30-Man');
    expect(fn () => Card::book($save, $backlash, 2, Card::type('elimination_chamber', 'Normal 6-Man Chamber'), [['Edge'], ['Kane'], ['Edge'], ['Kane'], ['Edge'], ['Kane']]))
        ->toThrow(GameRuleException::class, 'exclusive');
    expect($rumble->ppv_exclusive_id)->not->toBeNull();
});

it('only books wrestlers from the brand roster', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Batista']]);
})->throws(GameRuleException::class, 'brand roster');

it('refuses paid match types after choosing to downgrade during a deficit', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $save->brand(Brand::Raw)->update(['deficit_choice' => 'cheap_matches']);

    Card::book($save, Card::show($save), 1, Card::type('ladder', 'Single'), [['Edge'], ['Kane']]);
})->throws(GameRuleException::class, 'free match types');

it('charges special match costs to Financials when the show airs', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $budget = $save->brand(Brand::Raw)->budget;
    Card::book($save, Card::show($save), 1, Card::type('ladder', 'Single'), [['Edge'], ['Kane']]);

    app(WeekResolver::class)->resolve($save->refresh());

    expect($save->brand(Brand::Raw)->budget)->toBe($budget - 15_000 - 16_000);
});

it('has the AI book its own card before the week airs', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista', 'Rey Mysterio', 'Booker T', 'JBL', 'Randy Orton', 'Christian', 'Chris Benoit', 'Undertaker']);

    app(WeekResolver::class)->resolve($save->refresh());

    $smackdown = Card::show($save, Brand::Smackdown, ShowType::Weekly, 1);

    expect(MatchSlot::where('show_id', $smackdown->id)->whereNotNull('match_type_id')->count())->toBeGreaterThan(0);
});
