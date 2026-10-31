<?php

use App\Models\Rivalry;
use App\Services\Rivalries\RivalryService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GameBuilder;

/*
| House rule (2026-10-25): rivalries are always 1v1 and named alphabetically,
| "Jeff Hardy vs. Rob Van Dam".
*/

it('stores a new rivalry with the alphabetically first wrestler first', function () {
    $save = GameBuilder::inSeason(raw: ['Rob Van Dam', 'Jeff Hardy'], smackdown: ['Batista']);
    $rvd = GameBuilder::wrestler($save, 'Rob Van Dam');
    $hardy = GameBuilder::wrestler($save, 'Jeff Hardy');

    $rivalry = app(RivalryService::class)->start($save, $rvd->id, $hardy->id, 1);

    expect($rivalry->wrestler_a_id)->toBe($hardy->id)
        ->and($rivalry->wrestler_b_id)->toBe($rvd->id)
        // Either order finds it.
        ->and(app(RivalryService::class)->between($save, $rvd->id, $hardy->id)?->id)->toBe($rivalry->id);
});

it('lists every rivalry alphabetically on the Rivalry page, older ones included', function () {
    $save = GameBuilder::inSeason(raw: ['Rob Van Dam', 'Jeff Hardy'], smackdown: ['Batista']);
    // A rivalry stored the old way, by id, with the names out of order.
    Rivalry::create([
        'save_id' => $save->id,
        'wrestler_a_id' => GameBuilder::wrestler($save, 'Rob Van Dam')->id,
        'wrestler_b_id' => GameBuilder::wrestler($save, 'Jeff Hardy')->id,
        'heat' => 10,
        'started_week' => 1,
        'last_activity_week' => 1,
    ]);

    $this->get(route('gm.rivalries', $save))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rivalries.0.wrestler_a', 'Jeff Hardy')
            ->where('rivalries.0.wrestler_b', 'Rob Van Dam'));
});

it('sends the rivalry rules’ numbers from config', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

    $this->get(route('gm.rivalries', $save))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rules.meetings', config('gm.rivalries.auto_create_meetings'))
            ->where('rules.window_weeks', config('gm.rivalries.auto_create_window_weeks'))
            ->where('rules.idle_decay', config('gm.rivalries.idle_decay'))
            ->where('rules.blowoff_min_heat', config('gm.rivalries.blowoff_min_heat'))
            ->where('rules.start_promo_bars', config('gm.promos.fatigue_bars.start_rivalry'))
            ->where('rules.continue_promo_cost', abs(config('gm.promos.amounts.continue_rivalry'))));
});
