<?php

use App\Models\Save;
use App\Services\Week\WeekResolver;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| House rule (2026-11-17): after an aired week the player goes from its
| results to its Financials, the News and the inbox (`?wrap=1`), then back
| to the Schedule, with the header hidden the whole way.
*/

function airedWeek(): Save
{
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);
    app(WeekResolver::class)->resolve($save->refresh());

    return $save->refresh();
}

it('opens the week just aired’s Financials in wrap-up mode', function () {
    $save = airedWeek();

    $this->get(route('gm.financials', ['save' => $save, 'wrap' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('wrap_up', true));

    // Opened from the menu, it isn't.
    $this->get(route('gm.financials', $save))
        ->assertInertia(fn (Assert $page) => $page->where('wrap_up', false));
});

it('keeps wrap-up mode on the rival’s Financials for the week just aired', function () {
    $save = airedWeek();
    $rival = $save->player_brand->value === 'raw' ? 'smackdown' : 'raw';

    $this->get(route('gm.financials', ['save' => $save, 'wrap' => 1, 'brand' => $rival]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('wrap_up', true)
            ->where('brand', $rival)
            ->where('statement.week', $save->current_week - 1));
});

it('sends the News’ Continue to the inbox in wrap-up mode', function () {
    $save = airedWeek();

    $this->get(route('gm.news', ['save' => $save, 'wrap' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('wrap_up', true)
            ->where('continue_to.url', route('gm.email', ['save' => $save, 'wrap' => 1])));

    $this->get(route('gm.news', $save))
        ->assertInertia(fn (Assert $page) => $page
            ->where('wrap_up', false)
            ->where('continue_to.url', route('gm.email', $save)));
});

it('marks the week just aired’s emails as new in the inbox in wrap-up mode', function () {
    $save = airedWeek();

    $this->get(route('gm.email', ['save' => $save, 'wrap' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.wrap', true)
            ->where('new_week', $save->current_week - 1));

    $this->get(route('gm.email', $save))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.wrap', false)
            ->where('new_week', null));
});

it('ignores wrap-up before any week has aired', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

    $this->get(route('gm.financials', ['save' => $save, 'wrap' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('wrap_up', false));
});
