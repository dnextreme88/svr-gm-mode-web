<?php

use App\Enums\Brand;
use App\Enums\MatchMethod;
use App\Enums\PromoTypeKey;
use App\Enums\ShowStatus;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\FatigueLog;
use App\Models\MatchResult;
use App\Models\MatchSlot;
use App\Models\PromoType;
use App\Models\Save;
use App\Models\Show;
use App\Models\WeekResolution;
use App\Services\Promos\PromoService;
use App\Services\Week\WeekResolver;
use App\Services\Week\WeekSimulator;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| Live results (house rule, 2026-10-25): Air week starts the week, and the
| player decides each of their matches on the results screen, in air order:
| simulate it, or Book to Win.
*/

function live(): WeekResolver
{
    return app(WeekResolver::class);
}

/**
 * Raw (the player) books two singles matches; SmackDown (the rival) one.
 *
 * @return array{0: Save, 1: MatchSlot, 2: MatchSlot}
 */
function liveWeek(string $seed = GameBuilder::SEED): array
{
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito', 'Shelton Benjamin'], smackdown: ['Batista', 'JBL'], seed: $seed);
    $raw = Card::show($save);
    $first = Card::book($save, $raw, 1, Card::singles(), [['Edge'], ['Kane']]);
    $second = Card::book($save, $raw, 2, Card::singles(), [['Carlito'], ['Shelton Benjamin']]);
    Card::book($save, Card::show($save, Brand::Smackdown), 1, Card::singles(), [['Batista'], ['JBL']]);

    return [$save, $first, $second];
}

it('waits for the player’s matches and airs the rival’s later show on its own', function () {
    [$save, $first, $second] = liveWeek();

    live()->begin($save);

    $smackdown = Show::where('save_id', $save->id)->where('week_number', 1)->where('brand', 'smackdown')->firstOrFail();
    $raw = Show::where('save_id', $save->id)->where('week_number', 1)->where('brand', 'raw')->firstOrFail();

    // Friday's SmackDown airs after Monday's Raw, so it waits its turn.
    expect($raw->status)->toBe(ShowStatus::Airing)
        ->and($smackdown->status)->toBe(ShowStatus::Airing)
        ->and(MatchResult::where('match_slot_id', $first->id)->exists())->toBeFalse()
        ->and(WeekResolution::where('save_id', $save->id)->exists())->toBeFalse()
        ->and($save->refresh()->current_week)->toBe(1);

    live()->decide($save, $first->id);
    live()->decide($save, $second->id);

    expect($smackdown->refresh()->status)->toBe(ShowStatus::Aired);
});

it('decides the player’s matches in air order only', function () {
    [$save, $first, $second] = liveWeek();
    live()->begin($save);

    expect(fn () => live()->decide($save, $second->id))->toThrow(GameRuleException::class, 'not up next')
        ->and(fn () => live()->decide($save, $first->id, 5))->toThrow(GameRuleException::class, 'one of the sides');

    live()->decide($save, $first->id);

    expect(MatchResult::where('match_slot_id', $first->id)->exists())->toBeTrue()
        ->and($save->refresh()->current_week)->toBe(1);
});

it('finishes the week after the last match', function () {
    [$save, $first, $second] = liveWeek();
    live()->begin($save);

    live()->decide($save, $first->id);
    live()->decide($save, $second->id, 1);

    expect(WeekResolution::where('save_id', $save->id)->where('week_number', 1)->exists())->toBeTrue()
        ->and($save->refresh()->current_week)->toBe(2)
        ->and(FatigueLog::where('save_id', $save->id)->where('week_number', 1)->count())->toBeGreaterThan(0)
        ->and(live()->airing($save, 1))->toBeNull();
});

it('gives the same results simulated live as aired in one go', function () {
    [$a] = liveWeek('same-seed');
    live()->resolve($a);

    [$b] = liveWeek('same-seed');
    live()->begin($b);
    live()->simulateAll($b);

    $results = fn (Save $save): array => MatchResult::whereHas('matchSlot.show', fn ($q) => $q->where('save_id', $save->id))
        ->orderBy('id')
        ->get()
        ->map(fn (MatchResult $r): array => [$r->winner_side, $r->method->value, $r->summary])
        ->all();

    expect($results($b))->toBe($results($a))
        ->and($b->refresh()->current_week)->toBe(2);
});

it('makes the booked side win', function (int $side) {
    [$save, $first] = liveWeek();
    live()->begin($save);

    live()->decide($save, $first->id, $side);

    $result = MatchResult::where('match_slot_id', $first->id)->sole();

    expect($result->winner_side)->toBe($side)
        ->and($result->booked)->toBeTrue();
})->with([0, 1]);

it('ends a booked non-title match by DQ or count-out on the small roll', function () {
    config(['gm.outcomes.booked_non_decisive_chance' => 1.0]);
    [$save, $first] = liveWeek();
    live()->begin($save);

    live()->decide($save, $first->id, 0);

    expect(MatchResult::where('match_slot_id', $first->id)->sole()->method)->toBeIn([MatchMethod::Dq, MatchMethod::Countout]);
});

it('ends a booked match decisively without the roll', function () {
    config(['gm.outcomes.booked_non_decisive_chance' => 0.0]);
    [$save, $first] = liveWeek();
    live()->begin($save);

    live()->decide($save, $first->id, 0);

    expect(MatchResult::where('match_slot_id', $first->id)->sole()->isDecisive())->toBeTrue();
});

it('always ends a booked title match decisively, and the title changes hands', function () {
    config(['gm.outcomes.booked_non_decisive_chance' => 1.0]);
    $save = GameBuilder::inSeason(raw: ['Edge', 'John Cena'], smackdown: ['Batista']);
    $reign = GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    $slot = Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['John Cena']], [], Championship::where('key', 'wwe')->value('id'));
    live()->begin($save);

    live()->decide($save, $slot->id, 1);

    $result = MatchResult::where('match_slot_id', $slot->id)->sole();

    expect($result->isDecisive())->toBeTrue()
        ->and($result->title_changed)->toBeTrue()
        ->and($result->champion_reign_id)->toBe($reign->id);
});

it('never gives a booked win a DQ or count-out where the match type has none', function () {
    config(['gm.outcomes.booked_non_decisive_chance' => 1.0]);
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $slot = Card::book($save, Card::show($save), 1, Card::type('ladder', 'Single'), [['Edge'], ['Kane']]);
    live()->begin($save);

    live()->decide($save, $slot->id, 0);

    expect(MatchResult::where('match_slot_id', $slot->id)->sole()->method)->toBe(MatchMethod::MatchSpecific);
});

it('airs a promo right before the match it was placed in front of', function () {
    [$save, $first, $second] = liveWeek();
    $raw = Show::findOrFail($first->show_id);
    $promo = app(PromoService::class)->book($raw, 1, PromoType::where('key', PromoTypeKey::InterviewSpace)->firstOrFail(), GameBuilder::wrestler($save, 'Carlito')->id, beforePosition: 2);
    live()->begin($save);

    live()->decide($save, $first->id);
    expect($promo->refresh()->succeeded)->toBeNull();

    live()->decide($save, $second->id);
    expect($promo->refresh()->succeeded)->not->toBeNull();
});

it('finishes a half-aired week when simulating ahead', function () {
    [$save, $first] = liveWeek();
    live()->begin($save);
    live()->decide($save, $first->id, 0);

    app(WeekSimulator::class)->simulateTo($save->refresh(), 3);

    expect($save->refresh()->current_week)->toBe(3)
        ->and(MatchResult::where('match_slot_id', $first->id)->sole()->winner_side)->toBe(0);
});

it('airs live through HTTP: Air week, decide, Simulate all', function () {
    [$save, $first] = liveWeek();

    $this->post(route('gm.advance', $save))->assertRedirect(route('gm.results', [$save, 1]));

    $this->get(route('gm.results', [$save, 1]))
        ->assertInertia(fn (Assert $page) => $page->component('gm/results')
            ->where('airing', true)
            ->where('shows.0.outcomes.0.pending', true)
            ->where('shows.0.outcomes.0.is_next', true)
            ->where('shows.0.outcomes.1.is_next', false)
            ->where('shows.0.outcomes.0.sides.1.members.0.name', 'Kane')
            ->has('shows.0.fatigue', 0));

    $this->post(route('gm.results.decide', [$save, 1]), ['slot_id' => $first->id, 'winner_side' => 1])
        ->assertRedirect(route('gm.results', [$save, 1]));

    $this->get(route('gm.results', [$save, 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('shows.0.outcomes.0.pending', false)
            ->where('shows.0.outcomes.0.winners', ['Kane'])
            ->where('shows.0.outcomes.0.booked', true)
            ->where('shows.0.outcomes.1.is_next', true));

    $this->post(route('gm.results.simulate-all', [$save, 1]));

    $this->get(route('gm.results', [$save, 1]))
        ->assertInertia(fn (Assert $page) => $page->where('airing', false)->has('shows.0.fatigue', 4));
    expect($save->refresh()->current_week)->toBe(2);
});

it('holds roster moves until the week’s results are in', function () {
    [$save] = liveWeek();
    live()->begin($save);

    $this->from(route('gm.rosters.index', $save))
        ->post(route('gm.contracts.release', [$save, GameBuilder::wrestler($save, 'Carlito')]))
        ->assertSessionHasErrors('game');

    expect(GameBuilder::wrestler($save, 'Carlito')->brand)->toBe(Brand::Raw);
});

it('tags the champion going into a title match and lists promos with their result', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'John Cena', 'Kane'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    $raw = Card::show($save);
    $slot = Card::book($save, $raw, 1, Card::singles(), [['Edge'], ['John Cena']], [], Championship::where('key', 'wwe')->value('id'));
    app(PromoService::class)->book($raw, 1, PromoType::where('key', PromoTypeKey::InterviewSpace)->firstOrFail(), GameBuilder::wrestler($save, 'Kane')->id, beforePosition: 1);
    live()->begin($save);

    $this->get(route('gm.results', [$save, 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('shows.0.outcomes.0.champions', ['Edge'])
            ->where('shows.0.promos.0.status', null)
            ->where('shows.0.promos.0.before_position', 1)
            ->where('shows.0.promos.0.targets.0.name', 'Kane'));

    live()->decide($save, $slot->id, 1);

    $this->get(route('gm.results', [$save, 1, 'match' => $slot->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('focus_match_id', $slot->id)
            ->where('shows.0.outcomes.0.champions', ['Edge'])
            ->where('shows.0.outcomes.0.title_banner', 'new_champion')
            ->where('shows.0.promos.0.status', fn (?string $status) => in_array($status, ['succeeded', 'failed'], true)));
});

it('names the title a Title Match Hype promo hyped', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'John Cena'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    $raw = Card::show($save);
    $wwe = Championship::where('key', 'wwe')->value('id');
    Card::book($save, $raw, 1, Card::singles(), [['Edge'], ['John Cena']], [], $wwe);
    app(PromoService::class)->book($raw, 1, PromoType::where('key', PromoTypeKey::TitleMatchHype)->firstOrFail(), championshipId: $wwe, beforePosition: 1);
    live()->begin($save);

    $this->get(route('gm.results', [$save, 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('shows.0.promos.0.type', 'Title Match Hype')
            ->where('shows.0.promos.0.title', 'WWE Championship'));
});
