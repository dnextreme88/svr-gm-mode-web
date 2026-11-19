<?php

use App\Enums\Brand;
use App\Enums\ShowType;
use App\Models\PpvEvent;
use App\Services\Card\CardRandomizer;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| House rule (2026-11-17): no upper limit on a slot's competitors (it was 6),
| so the 10-superstar Tornado Tag Turmoil is bookable like any Gauntlet. The
| 30-man Royal Rumble and the Survivor Series 5 vs 5 still only fill their
| PPV's fixed main event.
*/

it('offers Tornado Tag Turmoil on the card like the other Gauntlets', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $show = Card::show($save);
    $names = app(CardRandomizer::class)->eligibleTypes($show, Brand::Raw)->pluck('name')->all();

    expect($names)->toContain('Gauntlet — Tornado Tag Turmoil', 'Gauntlet — 6-Man Gauntlet');

    $this->get(route('gm.card.show', [$save, $show]))
        ->assertInertia(fn (Assert $page) => $page->where('match_types', fn ($types) => collect($types)->contains('name', 'Gauntlet — Tornado Tag Turmoil')));
});

it('never offers the Royal Rumble or the Survivor Series 5 vs 5 for an ordinary slot, even on their own PPV', function (string $ppv) {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $show = Card::show($save);
    // The PPV's own card, in memory: its exclusives would otherwise show up.
    $show->show_type = ShowType::Ppv;
    $show->ppv_event_id = PpvEvent::where('key', $ppv)->value('id');

    $families = app(CardRandomizer::class)->eligibleTypes($show, Brand::Raw)->map(fn ($t) => $t->family->value)->unique()->all();

    expect($families)->not->toContain('royal_rumble')
        ->and($families)->not->toContain('survivor_series_elimination');
})->with(['royal_rumble', 'survivor_series']);
