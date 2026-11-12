<?php

use App\Enums\FinishEvent;
use App\Models\MatchResult;
use App\Models\Rivalry;
use App\Services\Rivalries\RivalryService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| Rivalry heat (house rule, 2026-11-11): an idle week costs 20 heat, a PPV
| blow-off needs 70+, and a Rival Interference finish adds 10.
*/

it('heats up the rivalry when a rival runs in and costs their rival the match', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
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
        // The other rivalry started this week, so it doesn't cool off yet.
        ->and($other->refresh()->heat)->toBe((int) config('gm.rivalries.start_heat'));
});

it('ends a rivalry whose heat an idle week takes to 0 or below', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $rivalry = app(RivalryService::class)->start($save, GameBuilder::wrestler($save, 'Edge')->id, GameBuilder::wrestler($save, 'Kane')->id, 1);
    $rivalry->update(['heat' => 30]);

    app(RivalryService::class)->weeklyDecay($save, 2);
    expect($rivalry->refresh()->heat)->toBe(10)->and($rivalry->active)->toBeTrue();

    app(RivalryService::class)->weeklyDecay($save, 3);
    expect($rivalry->refresh()->heat)->toBe(0)->and($rivalry->active)->toBeFalse();
});

it('only settles a rivalry at a PPV from 70 heat', function () {
    expect((int) config('gm.rivalries.blowoff_min_heat'))->toBe(70)
        ->and((int) config('gm.rivalries.idle_decay'))->toBe(20)
        ->and(Rivalry::query()->count())->toBe(0);
});
