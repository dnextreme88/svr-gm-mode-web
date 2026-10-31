<?php

use App\Enums\WonVia;
use App\Models\Championship;
use App\Services\Titles\LastChanceService;
use App\Services\Titles\TitleService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| Active Effects (house rule): the card page sends what's in play.
*/

it('sends Last Chance lockouts until the champion loses the title', function () {
    $save = GameBuilder::inSeason(raw: ['Batista', 'Undertaker', 'Edge'], smackdown: ['Kane']);
    $reign = GameBuilder::giveTitle($save, 'wwe', ['Batista']);
    $show = Card::show($save);
    app(LastChanceService::class)->lockOut($reign, [GameBuilder::wrestler($save, 'Undertaker')->id], 1, $show->matchSlots()->first()->id);

    $this->get(route('gm.card.show', [$save, $show]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('title_context.last_chance_lockouts', 1)
            ->where('title_context.last_chance_lockouts.0.contenders', 'Undertaker')
            ->where('title_context.last_chance_lockouts.0.champion', 'Batista'));

    app(TitleService::class)->changeHands($save, Championship::where('key', 'wwe')->first(), GameBuilder::wrestler($save, 'Edge'), 1, WonVia::Match, null, null);

    $this->get(route('gm.card.show', [$save, $show]))
        ->assertInertia(fn (Assert $page) => $page->has('title_context.last_chance_lockouts', 0));
});

it('sends Buried Alive losers while they sit out', function () {
    $save = GameBuilder::inSeason(raw: ['Batista', 'Undertaker'], smackdown: ['Kane']);
    GameBuilder::wrestler($save, 'Undertaker')->update(['buried_week' => 0, 'unbookable_until_week' => $save->current_week + 3]);

    $this->get(route('gm.card.show', [$save, Card::show($save)]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('buried_alive', 1)
            ->where('buried_alive.0.name', 'Undertaker')
            ->where('buried_alive.0.weeks_left', 3));
});
