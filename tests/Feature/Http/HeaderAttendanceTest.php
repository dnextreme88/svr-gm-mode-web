<?php

use App\Enums\Brand;
use App\Enums\ShowStatus;
use App\Http\Presenters\GamePresenter;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| House rule (2026-11-11): the header shows the crowd at the player brand's
| latest aired show instead of the roster count.
*/

it('shows no attendance before anything has aired', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    expect(app(GamePresenter::class)->shell($save)['last_attendance'])->toBeNull();
});

it('shows the crowd at the player brand’s latest aired show, not the rival’s', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    Card::show($save, Brand::Raw)->update(['status' => ShowStatus::Aired, 'attendance' => 15_050]);
    Card::show($save, Brand::Smackdown)->update(['status' => ShowStatus::Aired, 'attendance' => 19_000]);

    expect(app(GamePresenter::class)->shell($save->refresh())['last_attendance'])
        ->toMatchArray(['crowd' => 15_050, 'week' => 1]);
});
