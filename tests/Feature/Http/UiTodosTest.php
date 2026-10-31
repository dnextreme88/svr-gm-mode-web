<?php

use App\Enums\Brand;
use App\Enums\NewsEventType;
use App\Enums\SaveStage;
use App\Models\Email;
use App\Models\Save;
use App\Models\SaveWrestler;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GameBuilder;

it('returns the first pick as JSON so the new-game reel can reveal it', function () {
    GameBuilder::seedCatalog();

    $response = $this->postJson(route('gm.saves.store'), ['name' => 'Reel', 'brand' => 'raw'])
        ->assertCreated()
        ->assertJsonStructure(['next_url', 'first_brand']);

    $save = Save::where('name', 'Reel')->firstOrFail();

    expect($response->json('first_brand'))->toBe($save->draft_order[0])
        ->and($response->json('next_url'))->toBe(route('gm.draft.show', $save));
});

it('sends a "Use WWE roster" game straight to title assignment', function () {
    GameBuilder::seedCatalog();

    $response = $this->postJson(route('gm.saves.store'), ['name' => 'Real', 'brand' => 'smackdown', 'draft_mode' => 'wwe_roster'])
        ->assertCreated();

    $save = Save::where('name', 'Real')->firstOrFail();

    expect($response->json('next_url'))->toBe(route('gm.titles.assign', $save))
        ->and($save->stage)->toBe(SaveStage::AssigningTitles);
});

it('rejects a new game without a name over JSON', function () {
    GameBuilder::seedCatalog();

    $this->postJson(route('gm.saves.store'), ['name' => '', 'brand' => 'raw'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('passes the starting budget to the new-game screen', function () {
    GameBuilder::seedCatalog();

    $this->get(route('gm.saves.index'))
        ->assertInertia(fn (Assert $page) => $page->where('starting_budget', (int) config('gm.starting_budget')));
});

it('skips the rest of the draft once the player has stopped', function () {
    $save = GameBuilder::newSave(Brand::Raw);

    $this->post(route('gm.draft.finish', $save))->assertRedirect();
    $this->get(route('gm.draft.show', $save))
        ->assertInertia(fn (Assert $page) => $page->where('player_done', true));

    $this->post(route('gm.draft.ai-finish', $save))->assertRedirect(route('gm.saves.show', $save));

    $save->refresh();
    $rival = SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Smackdown)->count();

    expect($save->stage)->toBe(SaveStage::AssigningTitles)
        ->and($rival)->toBeGreaterThan(0)
        ->and($rival)->toBeLessThanOrEqual((int) config('gm.roster_cap'));
});

it('does not draft for the rival on the player\'s turn when skipping', function () {
    $save = GameBuilder::newSave(Brand::Raw);

    if ($save->draft_order[0] !== 'raw') {
        $this->postJson(route('gm.draft.ai-step', $save));
    }

    $this->post(route('gm.draft.ai-finish', $save));

    expect($save->refresh()->stage)->toBe(SaveStage::Drafting)
        ->and(SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Smackdown)->count())->toBeLessThanOrEqual(1);
});

it('shows the current season on the schedule and rejects seasons that have not happened', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    $this->get(route('gm.schedule', $save))
        ->assertInertia(fn (Assert $page) => $page->where('season', 1)->where('seasons', [1]));

    $this->get(route('gm.schedule', [$save, 'season' => 1]))->assertOk();
    $this->get(route('gm.schedule', [$save, 'season' => 2]))->assertNotFound();
    $this->get(route('gm.schedule', [$save, 'season' => 0]))->assertNotFound();
});

it('sends every wrestler name with its brand to the inbox and marks the opened email read', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $email = Email::create([
        'save_id' => $save->id,
        'brand' => Brand::Raw,
        'template' => 'hint',
        'sender' => 'JR',
        'subject' => 'Edge wants Batista',
        'season' => 1,
        'week_number' => 1,
        'payload' => [],
    ]);

    $this->get(route('gm.email', [$save, 'email' => $email->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('emails.0.read', true)
            ->where('wrestlers', fn ($wrestlers) => collect($wrestlers)->contains(fn ($w) => $w['name'] === 'Edge' && $w['brand'] === 'raw')
                && collect($wrestlers)->contains(fn ($w) => $w['name'] === 'Batista' && $w['brand'] === 'smackdown')));

    expect($email->refresh()->read_at)->not->toBeNull();
});

it('links title reigns on a wrestler page by championship key', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $edge = GameBuilder::wrestler($save, 'Edge');
    GameBuilder::giveTitle($save, 'intercontinental', ['Edge']);

    $this->get(route('gm.rosters.show', [$save, $edge]))
        ->assertInertia(fn (Assert $page) => $page->where('title_history.0.championship_key', 'intercontinental'));
});

it('renders the documentation page from the catalog', function () {
    GameBuilder::seedCatalog();

    $this->get(route('gm.docs'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('gm/docs')
            ->has('match_types')
            ->where('match_types.0.family', 'single')
            ->has('injuries.minor')
            ->has('injuries.serious')
            ->has('stipulations', 5)
            ->has('promo_stories', 14)
            ->has('quiet_stories', 5)
            ->has('rosters.wwe.raw', 30)
            ->has('rosters.wwe.smackdown', 30)
            ->where('rosters.cap', 30)
            ->where('specialists', fn ($rows) => collect($rows)->contains(fn ($w) => $w['name'] === 'Edge')));
});

it('lists the 2005 specialty additions on the documentation page', function () {
    GameBuilder::seedCatalog();

    $this->get(route('gm.docs'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('specialists', 33)
            ->where('specialists', function ($rows) {
                $byName = collect($rows)->keyBy('name')->map(fn ($w) => collect($w['specialties'])->sort()->values()->all());

                return $byName['Kane'] === ['first_blood']
                    && $byName['Stone Cold Steve Austin'] === ['first_blood']
                    && $byName['Mick Foley'] === ['hardcore', 'hell_in_a_cell']
                    && $byName['Spike Dudley'] === ['hardcore', 'table'];
            }));
});

it('documents every news event and GM email on the docs page', function () {
    GameBuilder::seedCatalog();

    $this->get(route('gm.docs'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('gm/docs')
            ->where('news.stories_per_brand', 2)
            ->has('news.events', count(NewsEventType::cases()))
            ->where('news.events', fn ($events) => collect($events)->firstWhere('type', 'match_result')['headline'] === '{winner} defeats {loser} in decisive fashion. {pronoun} made sure to get the win {how}.'
                && collect($events)->firstWhere('type', 'upset_win')['active'] === false)
            ->where('emails', fn ($emails) => collect($emails)->contains(fn ($e) => $e['email'] === 'Low on cash' && str_contains($e['when'], '$500,000'))
                && collect($emails)->contains(fn ($e) => $e['email'] === 'PPV reminder' && $e['from'] === 'Vince McMahon')));
});

it('documents the Buried Alive weeks and Royal Rumble entrants from config', function () {
    GameBuilder::seedCatalog();

    $this->get(route('gm.docs'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('results.buried_alive_weeks', (int) config('gm.buried_alive.sit_out_weeks'))
            ->where('results.rumble_entrants_per_brand', (int) config('gm.ppv.royal_rumble_entrants_per_brand')));
});

it('documents the season calendar with every PPV on its Sunday', function () {
    GameBuilder::seedCatalog();

    $this->get(route('gm.docs'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendar.weekly_show_days', ['raw' => 'Monday', 'smackdown' => 'Friday'])
            ->where('calendar.season_start', 'Sun Apr 3, 2005')
            ->where('calendar.season_end', 'Sun Mar 26, 2006')
            ->has('calendar.ppvs', 15)
            ->where('calendar.ppvs.0', ['key' => 'backlash', 'name' => 'Backlash', 'season_week' => 4, 'date' => 'Sun Apr 24, 2005', 'brand_scope' => 'raw'])
            ->where('calendar.ppvs.14.name', 'WrestleMania'));
});

it('serves the documentation inside a career with the GM header', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    $this->get(route('gm.saves.docs', $save))
        ->assertInertia(fn (Assert $page) => $page
            ->component('gm/docs')
            ->where('game.id', $save->id)
            ->where('match_types.0.label', 'Normal Matches')
            ->has('match_types.0.variants.0', fn (Assert $v) => $v
                ->hasAll(['fatigue_bars', 'rope_breaks', 'ring_out', 'captain_fall', 'timed'])
                ->etc()));
});
