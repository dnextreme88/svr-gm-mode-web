<?php

use App\Enums\Brand;
use App\Enums\SaveStage;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\SaveWrestler;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

it('starts a new game without logging in and lands in the draft room', function () {
    GameBuilder::seedCatalog();

    $this->post(route('gm.saves.store'), ['name' => 'Career', 'brand' => 'smackdown'])
        ->assertRedirect();

    $save = Save::where('name', 'Career')->firstOrFail();

    expect($save->player_brand)->toBe(Brand::Smackdown);

    $this->get(route('gm.draft.show', $save))
        ->assertInertia(fn (Assert $page) => $page
            ->component('gm/draft')
            ->has('pool')
            ->where('game.player_brand', 'smackdown'));
});

it('lists a new career only after it is saved', function () {
    GameBuilder::seedCatalog();
    $this->post(route('gm.saves.store'), ['name' => 'Career', 'brand' => 'raw']);
    $save = Save::where('name', 'Career')->firstOrFail();

    expect($save->saved_at)->toBeNull();

    $this->get(route('gm.saves.index'))
        ->assertInertia(fn (Assert $page) => $page->has('saves', 0)->where('unsaved.id', $save->id));

    $this->get(route('gm.draft.show', $save))->assertInertia(fn (Assert $page) => $page->where('game.saved', false));

    $this->post(route('gm.saves.persist', $save))->assertRedirect();

    expect($save->refresh()->saved_at)->not->toBeNull();

    $this->get(route('gm.saves.index'))
        ->assertInertia(fn (Assert $page) => $page->has('saves', 1)->where('saves.0.id', $save->id)->where('unsaved', null));
});

it('discards unsaved careers, but not saved ones, when a new game starts', function () {
    GameBuilder::seedCatalog();
    $this->post(route('gm.saves.store'), ['name' => 'Kept', 'brand' => 'raw']);
    $kept = Save::where('name', 'Kept')->firstOrFail();
    $this->post(route('gm.saves.persist', $kept));

    $this->post(route('gm.saves.store'), ['name' => 'Abandoned', 'brand' => 'raw']);
    $abandoned = Save::where('name', 'Abandoned')->firstOrFail();

    $this->post(route('gm.saves.store'), ['name' => 'Fresh', 'brand' => 'smackdown']);

    expect(Save::pluck('name')->sort()->values()->all())->toBe(['Fresh', 'Kept'])
        ->and(SaveWrestler::where('save_id', $abandoned->id)->count())->toBe(0);
});

it('sends the home page to the save list', function () {
    $this->get('/')->assertRedirect('/gm');
});

it('makes AI draft picks one at a time through the step endpoint', function () {
    $save = GameBuilder::newSave(Brand::Raw);

    if ($save->draft_order[0] === 'raw') {
        $this->post(route('gm.draft.pick', $save), ['save_wrestler_id' => SaveWrestler::where('save_id', $save->id)->orderBy('id')->value('id')])
            ->assertRedirect();

        // The human pick no longer triggers the AI on the server.
        expect(SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Smackdown)->count())->toBe(0);
    }

    $step = $this->postJson(route('gm.draft.ai-step', $save))->assertOk()->json();

    expect($step['pick']['brand'])->toBe('smackdown')
        ->and($step['pick'])->toHaveKeys(['save_wrestler_id', 'name', 'roster_type', 'weight_class'])
        ->and($step['next_brand'])->toBe('raw')
        ->and($step['ai_turn'])->toBeFalse()
        ->and(SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Smackdown)->count())->toBe(1);

    // On the human's turn the step does nothing.
    expect($this->postJson(route('gm.draft.ai-step', $save))->json('pick'))->toBeNull();
});

it('deletes a saved career', function () {
    $save = GameBuilder::newSave();

    $this->delete(route('gm.saves.destroy', $save))->assertRedirect(route('gm.saves.index'));

    expect(Save::count())->toBe(0)
        ->and(SaveWrestler::where('save_id', $save->id)->count())->toBe(0);
});

it('renders every in-season screen', function (string $route, string $component) {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

    $this->get(route($route, $save))
        ->assertInertia(fn (Assert $page) => $page->component($component)->has('game'));
})->with([
    ['gm.schedule', 'gm/schedule'],
    ['gm.email', 'gm/email'],
    ['gm.rosters.index', 'gm/rosters/index'],
    ['gm.free-agency', 'gm/free-agency'],
    ['gm.financials', 'gm/financials'],
]);

it('shows a wrestler with contract quotes', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $edge = GameBuilder::wrestler($save, 'Edge');

    $this->get(route('gm.rosters.show', [$save, $edge]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('gm/rosters/show')
            ->where('wrestler.name', 'Edge')
            ->has('quotes', 5)
            ->where('is_own', true));
});

it('airs the week and shows results', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    $this->post(route('gm.advance', $save))
        ->assertRedirect(route('gm.results', [$save, 1]));

    expect($save->refresh()->current_week)->toBe(2);
});

it('signs a free agent and renegotiates through HTTP', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $foley = GameBuilder::wrestler($save, 'Mick Foley');
    $edge = GameBuilder::wrestler($save, 'Edge');

    $this->post(route('gm.free-agency.sign', [$save, $foley]), ['length_weeks' => 24])
        ->assertRedirect();

    $this->post(route('gm.contracts.renegotiate', [$save, $edge]), ['length_weeks' => 12])
        ->assertRedirect();

    expect($edge->refresh()->activeContract?->length_weeks)->toBe(12);
});

it('rejects game-rule violations with the message', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $batista = GameBuilder::wrestler($save, 'Batista');

    $this->from(route('gm.free-agency', $save))
        ->post(route('gm.free-agency.sign', [$save, $batista]), ['length_weeks' => 24])
        ->assertRedirect(route('gm.free-agency', $save))
        ->assertSessionHasErrors('game');
});

it('moves from title assignment into the season', function () {
    $save = GameBuilder::newSave(Brand::Raw);
    $save->update(['stage' => SaveStage::AssigningTitles]);

    $this->post(route('gm.titles.assign.complete', $save))
        ->assertRedirect(route('gm.schedule', $save));

    expect($save->refresh()->stage)->toBe(SaveStage::InSeason);
});

it('books and clears a match through the card builder endpoints', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $show = Card::show($save);
    $edge = GameBuilder::wrestler($save, 'Edge');
    $kane = GameBuilder::wrestler($save, 'Kane');

    $this->get(route('gm.card.show', [$save, $show]))
        ->assertInertia(fn (Assert $page) => $page->component('gm/card')->has('slots', 6)->has('match_types'));

    $this->put(route('gm.card.book', [$save, $show, 1]), [
        'match_type_id' => Card::singles()->id,
        'sides' => [[$edge->id], [$kane->id]],
    ])
        ->assertRedirect();

    expect(MatchSlot::where('show_id', $show->id)->where('position', 1)->value('match_type_id'))->not->toBeNull();

    $this->delete(route('gm.card.clear', [$save, $show, 1]))->assertRedirect();

    expect(MatchSlot::where('show_id', $show->id)->where('position', 1)->value('match_type_id'))->toBeNull();
});

it('shows flashing outcomes and reports after the week airs', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);
    $this->post(route('gm.advance', $save));

    $this->get(route('gm.results', [$save, 1]))
        ->assertInertia(fn (Assert $page) => $page->component('gm/results')->has('shows.0.outcomes', 1));

    $this->get(route('gm.reports', $save))
        ->assertInertia(fn (Assert $page) => $page->component('gm/reports')->has('shows'));
});

it('sends each show’s fatigue progression with the results', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
    GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 20]);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);
    $this->post(route('gm.advance', $save));

    $this->get(route('gm.results', [$save, 1]))
        ->assertInertia(fn (Assert $page) => $page->component('gm/results')
            // Only wrestlers on the show, in card order; Carlito sat out.
            ->has('shows.0.fatigue', 2)
            ->where('shows.0.fatigue.0.name', 'Edge')
            ->where('shows.0.fatigue.0.start', 20)
            ->where('shows.0.fatigue.0.after_shows', 30)
            ->where('shows.0.fatigue.0.end', 10)
            ->where('shows.0.fatigue.0.recovered_bars', 2)
            ->where('shows.0.fatigue.0.rested', false));
});

it('refuses to open the rival brand card', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $show = Card::show($save, Brand::Smackdown);

    $this->from(route('gm.schedule', $save))
        ->put(route('gm.card.book', [$save, $show, 1]), ['match_type_id' => 1, 'sides' => [[1], [2]]])
        ->assertSessionHasErrors('game');
});
