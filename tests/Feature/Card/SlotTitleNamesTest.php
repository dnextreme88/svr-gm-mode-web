<?php

use App\Enums\Brand;
use App\Enums\Stipulation;
use App\Http\Presenters\CardPresenter;
use App\Models\Championship;
use App\Models\MatchSlot;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| House rule (2026-11-17): a slot names its title and stipulation title and
| says whose they are, so an interbrand card can show the rival's own on its
| slots (the player's title context only knows the player's titles).
*/

it('names the title on the line and its brand', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Batista', 'Kane'], smackdown: ['Edge']);
    $reign = GameBuilder::giveTitle($save, 'wwe', ['Batista']);
    $slot = Card::book($save, Card::show($save), 1, Card::singles(), [['Batista'], ['Kane']], titleId: $reign->championship_id);

    $view = CardPresenter::slot(MatchSlot::with(['participants.saveWrestler.wrestler', 'titleChampionship', 'stipulationChampionship'])->find($slot->id), Brand::Smackdown);

    expect($view['title_name'])->toBe(Championship::find($reign->championship_id)->name)
        ->and($view['title_brand'])->toBe('raw');
});

it('names a stipulation’s title and the brand that booked it', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Kane', 'Eugene', 'Batista'], smackdown: ['Edge']);
    $reign = GameBuilder::giveTitle($save, 'wwe', ['Batista']);
    $slot = Card::book($save, Card::show($save), 1, Card::singles(), [['Kane'], ['Eugene']]);
    $slot->update(['stipulation' => Stipulation::NumberOneContender, 'stipulation_championship_id' => $reign->championship_id]);

    $view = CardPresenter::slot(MatchSlot::with(['participants.saveWrestler.wrestler', 'titleChampionship', 'stipulationChampionship'])->find($slot->id), Brand::Smackdown);

    expect($view['stipulation_title_names'])->toBe([Championship::find($reign->championship_id)->name])
        ->and($view['stipulation_brand'])->toBe('raw');
});
