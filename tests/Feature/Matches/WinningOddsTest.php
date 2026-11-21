<?php

use App\Enums\InjurySeverity;
use App\Enums\PromoTypeKey;
use App\Models\MatchSlot;
use App\Models\PromoSlot;
use App\Models\PromoType;
use App\Services\Injuries\InjuryService;
use App\Services\Matches\WinningOdds;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| Winning odds (house rule, 2026-11-21): equal shares plus percentage points per
| side; one side's points come off the others, so equal points cancel out.
*/

function oddsSlot(MatchSlot $slot): MatchSlot
{
    return $slot->fresh(['matchType', 'participants.saveWrestler.wrestler.specialties', 'show']);
}

/**
 * @return array<int, float>
 */
function chances(MatchSlot $slot): array
{
    return app(WinningOdds::class)->chances(oddsSlot($slot));
}

it('starts every side on an equal share', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Triple H', 'Kurt Angle'], smackdown: ['Batista']);
    $slot = Card::book($save, Card::show($save), 1, Card::type('single', 'Triple Threat'), [['Edge'], ['Triple H'], ['Kurt Angle']]);

    expect(chances($slot))->toBe([0 => 33.3, 1 => 33.3, 2 => 33.3]);
});

it('gives a specialist 10 points in their specialty', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kurt Angle'], smackdown: ['Batista']);
    $slot = Card::book($save, Card::show($save), 1, Card::type('ladder', 'Single'), [['Edge'], ['Kurt Angle']]);

    $odds = app(WinningOdds::class)->forSlot(oddsSlot($slot));

    expect(chances($slot))->toBe([0 => 60.0, 1 => 40.0])
        ->and($odds['sides'][0]['factors'])->toBe([['label' => 'Ladder specialist', 'points' => 10]]);
});

it('cancels out when both sides are specialists', function () {
    $save = GameBuilder::inSeason(raw: ['Jeff Hardy', 'Christian'], smackdown: ['Batista']);
    $slot = Card::book($save, Card::show($save), 1, Card::type('ladder', 'Single'), [['Jeff Hardy'], ['Christian']]);

    expect(chances($slot))->toBe([0 => 50.0, 1 => 50.0]);
});

it('counts a specialty once per side', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Shelton Benjamin', 'Triple H', 'Kurt Angle'], smackdown: ['Batista']);
    $slot = Card::book($save, Card::show($save), 1, Card::type('ladder', 'Tornado Tag'), [['Edge', 'Shelton Benjamin'], ['Triple H', 'Kurt Angle']]);

    expect(chances($slot))->toBe([0 => 60.0, 1 => 40.0]);
});

it('rewards win streaks of 5, 10 and 20', function (int $streak, float $chance) {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kurt Angle'], smackdown: ['Batista']);
    GameBuilder::wrestler($save, 'Edge')->update(['win_streak' => $streak]);
    $slot = Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kurt Angle']]);

    expect(chances($slot)[0])->toBe($chance);
})->with([[4, 50.0], [5, 55.0], [9, 55.0], [10, 60.0], [20, 65.0]]);

it('takes 10 points off for each superstar with a minor injury', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kurt Angle'], smackdown: ['Batista']);
    app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Kurt Angle'), InjurySeverity::Minor, 1, $save->rng('t'));
    $slot = Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kurt Angle']]);

    expect(chances($slot))->toBe([0 => 60.0, 1 => 40.0]);
});

it('counts an Interview Space that aired this week', function (bool $succeeded, float $chance) {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kurt Angle'], smackdown: ['Batista']);
    $show = Card::show($save);
    $slot = Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kurt Angle']]);
    PromoSlot::updateOrCreate(['show_id' => $show->id, 'position' => 1], [
        'promo_type_id' => PromoType::where('key', PromoTypeKey::InterviewSpace)->value('id'),
        'target_wrestler_id' => GameBuilder::wrestler($save, 'Edge')->id,
        'succeeded' => $succeeded,
    ]);

    expect(chances($slot)[0])->toBe($chance);
})->with([[true, 55.0], [false, 45.0]]);

it('ignores an Interview Space that has not aired yet', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kurt Angle'], smackdown: ['Batista']);
    $show = Card::show($save);
    $slot = Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kurt Angle']]);
    PromoSlot::updateOrCreate(['show_id' => $show->id, 'position' => 1], [
        'promo_type_id' => PromoType::where('key', PromoTypeKey::InterviewSpace)->value('id'),
        'target_wrestler_id' => GameBuilder::wrestler($save, 'Edge')->id,
    ]);

    expect(chances($slot))->toBe([0 => 50.0, 1 => 50.0]);
});

it('gives 5 points for every weight class above the lightest side', function () {
    $save = GameBuilder::inSeason(raw: ['Big Show', 'Tajiri', 'Edge', 'Carlito'], smackdown: ['Batista']);
    $show = Card::show($save);

    // Super Heavyweight vs Cruiserweight: 3 classes.
    $slot = Card::book($save, $show, 1, Card::singles(), [['Big Show'], ['Tajiri']]);
    expect(chances($slot))->toBe([0 => 65.0, 1 => 35.0]);

    // Heavyweight vs Light Heavyweight: 1 class.
    $slot = Card::book($save, $show, 2, Card::singles(), [['Edge'], ['Carlito']]);
    expect(chances($slot))->toBe([0 => 55.0, 1 => 45.0]);
});

it('skips the weight class gap when a side mixes classes', function () {
    $save = GameBuilder::inSeason(raw: ['Big Show', 'Tajiri', 'Edge', 'Triple H'], smackdown: ['Batista']);
    $slot = Card::book($save, Card::show($save), 1, Card::type('tag_team', 'Normal Tag'), [['Big Show', 'Tajiri'], ['Edge', 'Triple H']]);

    expect(chances($slot))->toBe([0 => 50.0, 1 => 50.0]);
});

it('gives an Ultra Heavyweight 25 points over a Featherweight', function () {
    $save = GameBuilder::inSeason(raw: ['Andre The Giant', 'Lita'], smackdown: ['Batista']);
    $slot = Card::book($save, Card::show($save), 1, Card::singles(), [['Andre The Giant'], ['Lita']]);

    expect(chances($slot))->toBe([0 => 75.0, 1 => 25.0]);
});

it('keeps every side at 5% or more', function () {
    $save = GameBuilder::inSeason(raw: ['Andre The Giant', 'Lita'], smackdown: ['Batista']);
    GameBuilder::wrestler($save, 'Andre The Giant')->update(['win_streak' => 20]);
    app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Lita'), InjurySeverity::Minor, 1, $save->rng('t'));
    $slot = Card::book($save, Card::show($save), 1, Card::singles(), [['Andre The Giant'], ['Lita']]);

    // 50 + 25 + 15 + 10 for Andre against Lita: 100 vs 0, floored at 5 and scaled back.
    expect(chances($slot))->toBe([0 => 95.2, 1 => 4.8]);
});

it('keeps the odds going in on the result', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kurt Angle'], smackdown: ['Batista']);
    $slot = Card::book($save, Card::show($save), 1, Card::type('ladder', 'Single'), [['Edge'], ['Kurt Angle']]);

    app(WeekResolver::class)->resolve($save->refresh());

    $odds = $slot->refresh()->result->winning_odds;

    expect((float) $odds['sides'][0]['chance'])->toBe(60.0)
        ->and((float) $odds['sides'][1]['chance'])->toBe(40.0);
});
