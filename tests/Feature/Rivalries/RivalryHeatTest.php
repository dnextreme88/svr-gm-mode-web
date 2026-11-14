<?php

use App\Enums\Brand;
use App\Enums\FinishEvent;
use App\Enums\NewsEventType;
use App\Enums\ShowStatus;
use App\Enums\ShowType;
use App\Models\MatchResult;
use App\Models\NewsEvent;
use App\Models\Rivalry;
use App\Models\Save;
use App\Services\Rivalries\RivalryService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| Rivalry heat (house rule, 2026-11-12), settled once a week after the shows:
| a week the rivals share a match (opponents or partners, whoever wins) +10,
| a PPV they share a match at +20, a Continue Rivalry promo holds it, any
| other week -20 (0 ends it), and reaching 100 ends it the week after with a
| headline. A Rival Interference finish still adds 10 (house rule, 2026-11-11).
*/

function heatSave(): Save
{
    GameBuilder::passiveRival();

    return GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito', 'Chris Masters', 'Shelton Benjamin', 'Snitsky'], smackdown: ['Batista']);
}

/** A rivalry between Edge and Kane that started in week 1. */
function edgeKane(Save $save, int $heat): Rivalry
{
    $rivalry = app(RivalryService::class)->start($save, GameBuilder::wrestler($save, 'Edge')->id, GameBuilder::wrestler($save, 'Kane')->id, 1);
    $rivalry->update(['heat' => $heat]);

    return $rivalry;
}

/**
 * Book matches on a show of that week, mark it aired and settle the week.
 *
 * @param  list<list<list<string>>>  $matches  each match's sides
 */
function settleWith(Save $save, int $week, array $matches, ShowType $type = ShowType::Weekly): void
{
    $show = Card::show($save, Brand::Raw, $type, $week);

    foreach ($matches as $i => $sides) {
        $family = count($sides) > 2 ? Card::type('single', 'Fatal 4-Way') : (count($sides[0]) > 1 ? Card::type('tag_team', 'Normal Tag') : Card::singles());
        Card::book($save, $show, $i + 1, $family, $sides);
    }

    $show->update(['status' => ShowStatus::Aired]);
    app(RivalryService::class)->settleWeek($save, $week);
}

it('heats up the rivalry when a rival runs in and costs their rival the match', function () {
    $save = heatSave();
    config([
        'gm.outcomes.clean_finish_chance' => 0.0,
        'gm.outcomes.finish_event_weights' => ['rival_interference' => 1],
    ]);
    $rivalries = app(RivalryService::class);
    $id = fn (string $name): int => GameBuilder::wrestler($save, $name)->id;
    $withEdge = $rivalries->start($save, $id('Carlito'), $id('Edge'), 1);
    $withKane = $rivalries->start($save, $id('Carlito'), $id('Kane'), 1);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);

    app(WeekResolver::class)->resolve($save->refresh());

    $result = MatchResult::sole();
    $winners = $result->matchSlot->sides()[$result->winner_side];
    $cost = in_array($id('Edge'), $winners, true) ? $withKane : $withEdge;
    $other = $cost->is($withEdge) ? $withKane : $withEdge;

    expect($result->finish_event)->toBe(FinishEvent::RivalInterference)
        ->and($cost->refresh()->heat)->toBe((int) config('gm.rivalries.start_heat') + 10)
        // The other rivalry started this week, so it stays at the start heat.
        ->and($other->refresh()->heat)->toBe((int) config('gm.rivalries.start_heat'));
});

it('leaves a rivalry at its start heat the week it starts', function () {
    $save = heatSave();
    $rivalry = edgeKane($save, 10);

    settleWith($save, 1, [[['Edge'], ['Kane']]]);

    expect($rivalry->refresh()->heat)->toBe(10)->and($rivalry->active)->toBeTrue();
});

it('adds 10 for a week the rivals share a match, as opponents, partners or fellow losers', function (array $sides) {
    $save = heatSave();
    $rivalry = edgeKane($save, 30);

    settleWith($save, 2, [$sides]);

    expect($rivalry->refresh()->heat)->toBe(40)->and($rivalry->active)->toBeTrue();
})->with([
    'opponents' => [[['Edge'], ['Kane']]],
    'tag partners' => [[['Edge', 'Kane'], ['Carlito', 'Chris Masters']]],
    'both in a Fatal 4-Way' => [[['Edge'], ['Kane'], ['Carlito'], ['Chris Masters']]],
]);

it('adds 10 once a week, however many matches they share', function () {
    $save = heatSave();
    $rivalry = edgeKane($save, 30);

    settleWith($save, 2, [[['Edge'], ['Kane']], [['Edge', 'Kane'], ['Carlito', 'Chris Masters']]]);

    expect($rivalry->refresh()->heat)->toBe(40);
});

it('adds 20 for a PPV the rivals share a match at, and never ends it there', function () {
    $save = heatSave();
    $rivalry = edgeKane($save, 70);

    settleWith($save, 4, [[['Edge'], ['Kane']]], ShowType::Ppv);

    expect($rivalry->refresh()->heat)->toBe(90)
        ->and($rivalry->active)->toBeTrue()
        ->and(NewsEvent::where('type', NewsEventType::RivalryBlowoff)->count())->toBe(0);
});

it('holds the heat for a week with a Continue Rivalry promo and no shared match', function () {
    $save = heatSave();
    $rivalry = edgeKane($save, 30);
    app(RivalryService::class)->hold($rivalry, 2);

    settleWith($save, 2, [[['Edge'], ['Carlito']]]);

    expect($rivalry->refresh()->heat)->toBe(30)->and($rivalry->active)->toBeTrue();
});

it('loses 20 for a quiet week and ends at 0 or below', function () {
    $save = heatSave();
    $rivalry = edgeKane($save, 30);

    settleWith($save, 2, [[['Edge'], ['Carlito']]]);
    expect($rivalry->refresh()->heat)->toBe(10)->and($rivalry->active)->toBeTrue();

    settleWith($save, 3, [[['Kane'], ['Carlito']]]);
    expect($rivalry->refresh()->heat)->toBe(0)
        ->and($rivalry->active)->toBeFalse()
        ->and($rivalry->ended_week)->toBe(3);
});

it('ends a rivalry the week after it reaches 100, with a headline', function () {
    $save = heatSave();
    $rivalry = edgeKane($save, 90);

    settleWith($save, 2, [[['Edge'], ['Kane']]]);
    expect($rivalry->refresh()->heat)->toBe(100)
        ->and($rivalry->maxed_week)->toBe(2)
        ->and($rivalry->active)->toBeTrue();

    // Meeting again doesn't keep it going: it has peaked.
    settleWith($save, 3, [[['Edge'], ['Kane']]]);
    $news = NewsEvent::where('type', NewsEventType::RivalryConcluded)->sole();

    expect($rivalry->refresh()->active)->toBeFalse()
        ->and($rivalry->ended_week)->toBe(3)
        ->and($news->rendered_text)->toContain('Edge')
        ->and($news->rendered_text)->toContain('Kane')
        ->and($news->tier->value)->toBe('headline');
});

it('keeps the heat rules in config', function () {
    expect(config('gm.rivalries'))
        ->toMatchArray([
            'start_heat' => 10,
            'heat_per_meeting' => 10,
            'heat_per_ppv_meeting' => 20,
            'idle_decay' => 20,
            'max_heat' => 100,
        ])
        ->not->toHaveKey('blowoff_min_heat')
        ->not->toHaveKey('heat_per_promo');
});
