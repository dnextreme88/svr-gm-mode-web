<?php

use App\Enums\Brand;
use App\Enums\SaveStage;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\User;
use App\Services\Draft\DraftService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

it('starts a new game and lands in the draft room', function () {
    GameBuilder::seedCatalog();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('gm.saves.store'), ['name' => 'Career', 'brand' => 'smackdown'])
        ->assertRedirect();

    $save = Save::where('user_id', $user->id)->firstOrFail();

    expect($save->player_brand)->toBe(Brand::Smackdown);

    $this->actingAs($user)->get(route('gm.draft.show', $save))
        ->assertInertia(fn (Assert $page) => $page
            ->component('gm/draft')
            ->has('pool')
            ->where('game.player_brand', 'smackdown'));
});

it('drafts through the HTTP endpoint with the AI answering', function () {
    $save = GameBuilder::newSave(Brand::Raw);
    $pickFirst = SaveWrestler::where('save_id', $save->id)->orderBy('id')->firstOrFail();

    if ($save->draft_order[0] === 'smackdown') {
        app(DraftService::class)->runAi($save);
        $pickFirst = SaveWrestler::where('save_id', $save->id)->whereNull('brand')->orderBy('id')->firstOrFail();
    }

    $this->actingAs($save->user)
        ->post(route('gm.draft.pick', $save), ['save_wrestler_id' => $pickFirst->id])
        ->assertRedirect();

    expect($pickFirst->refresh()->brand)->toBe(Brand::Raw)
        ->and(SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Smackdown)->count())->toBeGreaterThanOrEqual(1);
});

it('keeps saves private to their owner', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    $this->actingAs(User::factory()->create())
        ->get(route('gm.schedule', $save))
        ->assertForbidden();
});

it('renders every in-season screen', function (string $route, string $component) {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

    $this->actingAs($save->user)
        ->get(route($route, $save))
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

    $this->actingAs($save->user)
        ->get(route('gm.rosters.show', [$save, $edge]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('gm/rosters/show')
            ->where('wrestler.name', 'Edge')
            ->has('quotes', 5)
            ->where('is_own', true));
});

it('airs the week and shows results', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    $this->actingAs($save->user)
        ->post(route('gm.advance', $save))
        ->assertRedirect(route('gm.results', [$save, 1]));

    expect($save->refresh()->current_week)->toBe(2);
});

it('signs a free agent and renegotiates through HTTP', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $foley = GameBuilder::wrestler($save, 'Mick Foley');
    $edge = GameBuilder::wrestler($save, 'Edge');

    $this->actingAs($save->user)
        ->post(route('gm.free-agency.sign', [$save, $foley]), ['length_weeks' => 24])
        ->assertRedirect();

    $this->actingAs($save->user)
        ->post(route('gm.contracts.renegotiate', [$save, $edge]), ['length_weeks' => 12])
        ->assertRedirect();

    expect($edge->refresh()->activeContract?->length_weeks)->toBe(12);
});

it('rejects game-rule violations with the message', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $batista = GameBuilder::wrestler($save, 'Batista');

    $this->actingAs($save->user)
        ->from(route('gm.free-agency', $save))
        ->post(route('gm.free-agency.sign', [$save, $batista]), ['length_weeks' => 24])
        ->assertRedirect(route('gm.free-agency', $save))
        ->assertSessionHasErrors('game');
});

it('moves from title assignment into the season', function () {
    $save = GameBuilder::newSave(Brand::Raw);
    $save->update(['stage' => SaveStage::AssigningTitles]);

    $this->actingAs($save->user)
        ->post(route('gm.titles.assign.complete', $save))
        ->assertRedirect(route('gm.schedule', $save));

    expect($save->refresh()->stage)->toBe(SaveStage::InSeason);
});

it('books and clears a match through the card builder endpoints', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $show = Card::show($save);
    $edge = GameBuilder::wrestler($save, 'Edge');
    $kane = GameBuilder::wrestler($save, 'Kane');

    $this->actingAs($save->user)
        ->get(route('gm.card.show', [$save, $show]))
        ->assertInertia(fn (Assert $page) => $page->component('gm/card')->has('slots', 6)->has('match_types'));

    $this->actingAs($save->user)
        ->put(route('gm.card.book', [$save, $show, 1]), [
            'match_type_id' => Card::singles()->id,
            'sides' => [[$edge->id], [$kane->id]],
        ])
        ->assertRedirect();

    expect(MatchSlot::where('show_id', $show->id)->where('position', 1)->value('match_type_id'))->not->toBeNull();

    $this->actingAs($save->user)->delete(route('gm.card.clear', [$save, $show, 1]))->assertRedirect();

    expect(MatchSlot::where('show_id', $show->id)->where('position', 1)->value('match_type_id'))->toBeNull();
});

it('shows flashing outcomes and reports after the week airs', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);
    $this->actingAs($save->user)->post(route('gm.advance', $save));

    $this->actingAs($save->user)
        ->get(route('gm.results', [$save, 1]))
        ->assertInertia(fn (Assert $page) => $page->component('gm/results')->has('shows.0.outcomes', 1));

    $this->actingAs($save->user)
        ->get(route('gm.reports', $save))
        ->assertInertia(fn (Assert $page) => $page->component('gm/reports')->has('shows'));
});

it('refuses to open the rival brand card', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $show = Card::show($save, Brand::Smackdown);

    $this->actingAs($save->user)
        ->from(route('gm.schedule', $save))
        ->put(route('gm.card.book', [$save, $show, 1]), ['match_type_id' => 1, 'sides' => [[1], [2]]])
        ->assertSessionHasErrors('game');
});
