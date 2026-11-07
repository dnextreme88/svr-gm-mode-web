<?php

use App\Enums\Brand;
use App\Enums\ContendershipVoidReason;
use App\Enums\ReignEndReason;
use App\Enums\RematchClauseStatus;
use App\Enums\WonVia;
use App\Models\Championship;
use App\Models\MatchSlot;
use App\Models\RematchClause;
use App\Models\Save;
use App\Services\Ai\AiBooker;
use App\Services\Contracts\ContractService;
use App\Services\Titles\ContendershipService;
use App\Services\Titles\RematchClauseService;
use App\Services\Titles\TitleService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| Rematch Clause (house rule, 2026-11-07): the former champion(s) of a title
| lost in a match are owed a title match against the new champion(s) within
| 4 weeks. Any result resolves it; it never blocks a card.
*/

function rematchWeek(Save $save): void
{
    app(WeekResolver::class)->resolve($save->refresh());
}

function clauseFor(Save $save, string $key): ?RematchClause
{
    return RematchClause::where('save_id', $save->id)
        ->where('championship_id', Championship::where('key', $key)->sole()->id)
        ->latest('id')
        ->first();
}

/** Edge takes the WWE title from John Cena on this week's Raw. */
function edgeWinsTheTitle(Save $save): void
{
    $title = Championship::where('key', 'wwe')->sole();
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['John Cena']], titleId: $title->id);
    FixedOutcomes::queue('Edge');
    rematchWeek($save);
}

beforeEach(fn () => GameBuilder::passiveRival());

it('owes the former champion a rematch when the title changes hands in a match', function () {
    $save = GameBuilder::inSeason(raw: ['John Cena', 'Edge', 'Kane'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['John Cena']);

    edgeWinsTheTitle($save);

    $clause = clauseFor($save, 'wwe');
    expect($clause)->not->toBeNull()
        ->and($clause->status)->toBe(RematchClauseStatus::Pending)
        ->and($clause->former_ids)->toBe([GameBuilder::wrestler($save, 'John Cena')->id])
        ->and($clause->champion_ids)->toBe([GameBuilder::wrestler($save, 'Edge')->id])
        ->and($clause->expires_week)->toBe(1 + 4);
});

it('gives no rematch for a title awarded while vacant', function () {
    $save = GameBuilder::inSeason(raw: ['John Cena', 'Edge'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['John Cena']);

    expect(RematchClause::count())->toBe(0);
});

it('is resolved by a 1v1 title rematch, whoever wins', function (string $winner) {
    $save = GameBuilder::inSeason(raw: ['John Cena', 'Edge', 'Kane'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
    edgeWinsTheTitle($save);
    $title = Championship::where('key', 'wwe')->sole();

    Card::book($save->refresh(), Card::show($save), 1, Card::singles(), [['John Cena'], ['Edge']], titleId: $title->id);
    FixedOutcomes::queue($winner);
    rematchWeek($save);

    $first = RematchClause::where('save_id', $save->id)->orderBy('id')->first();
    expect($first->status)->toBe(RematchClauseStatus::Resolved)
        ->and($first->resolved_week)->toBe(2);
})->with(['John Cena', 'Edge']);

it('counts a w/Manager variant as the rematch', function () {
    $save = GameBuilder::inSeason(raw: ['John Cena', 'Edge', 'Kane', 'Lita'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
    edgeWinsTheTitle($save);
    $title = Championship::where('key', 'wwe')->sole();

    Card::book($save->refresh(), Card::show($save), 1, Card::type('single', 'No Manager vs. w/Manager'), [['John Cena'], ['Edge']], titleId: $title->id, managers: [1 => 'Lita']);
    FixedOutcomes::queue('Edge');
    rematchWeek($save);

    expect(clauseFor($save, 'wwe')->status)->toBe(RematchClauseStatus::Resolved);
});

it('is resolved by a 2v2 rematch for a tag title', function () {
    $save = GameBuilder::inSeason(raw: ['Lance Cade', 'Trevor Murdoch', 'Rene Dupree', 'Rob Conway'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'world_tag_team', ['Rene Dupree', 'Rob Conway']);
    $title = Championship::where('key', 'world_tag_team')->sole();
    Card::book($save, Card::show($save), 1, Card::type('tag_team', 'Normal Tag'), [['Lance Cade', 'Trevor Murdoch'], ['Rene Dupree', 'Rob Conway']], titleId: $title->id);
    FixedOutcomes::queue('Lance Cade');
    rematchWeek($save);

    expect(clauseFor($save, 'world_tag_team')->status)->toBe(RematchClauseStatus::Pending);

    Card::book($save->refresh(), Card::show($save), 1, Card::type('tag_team', 'Normal Tag'), [['Rob Conway', 'Rene Dupree'], ['Trevor Murdoch', 'Lance Cade']], titleId: $title->id);
    FixedOutcomes::queue('Lance Cade');
    rematchWeek($save);

    expect(clauseFor($save, 'world_tag_team')->status)->toBe(RematchClauseStatus::Resolved);
});

it("isn't resolved by a Triple Threat", function () {
    $save = GameBuilder::inSeason(raw: ['John Cena', 'Edge', 'Kane'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
    edgeWinsTheTitle($save);
    $title = Championship::where('key', 'wwe')->sole();

    Card::book($save->refresh(), Card::show($save), 1, Card::type('single', 'Normal Triple Threat'), [['John Cena'], ['Edge'], ['Kane']], titleId: $title->id);
    FixedOutcomes::queue('Edge');
    rematchWeek($save);

    expect(clauseFor($save, 'wwe')->status)->toBe(RematchClauseStatus::Pending);
});

it('runs out after 4 weeks', function () {
    $save = GameBuilder::inSeason(raw: ['John Cena', 'Edge'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
    edgeWinsTheTitle($save);
    $service = app(RematchClauseService::class);

    expect($service->active($save, 5, Brand::Raw))->toHaveCount(1)
        ->and($service->active($save, 6, Brand::Raw))->toHaveCount(0)
        ->and(clauseFor($save, 'wwe')->weeksLeft(2))->toBe(4);
});

it('ends early when a former champion leaves the brand or someone is seriously injured', function (string $who, string $how) {
    $save = GameBuilder::inSeason(raw: ['John Cena', 'Edge'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
    edgeWinsTheTitle($save);
    $wrestler = GameBuilder::wrestler($save, $who);

    $how === 'release'
        ? app(ContractService::class)->release($wrestler, 2)
        : app(ContendershipService::class)->voidFor($wrestler, ContendershipVoidReason::SeriousInjury, 2);

    expect(clauseFor($save, 'wwe')->status)->toBe(RematchClauseStatus::Voided);
})->with([
    ['John Cena', 'release'],
    ['John Cena', 'injury'],
    ['Edge', 'injury'],
]);

it('ends when the title changes hands again or is vacated', function () {
    $save = GameBuilder::inSeason(raw: ['John Cena', 'Edge', 'Kane'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
    edgeWinsTheTitle($save);
    $first = clauseFor($save, 'wwe');
    $title = Championship::where('key', 'wwe')->sole();

    // Kane takes it from Edge: Cena's rematch is off, Edge is owed one now.
    app(TitleService::class)->changeHands($save, $title, GameBuilder::wrestler($save, 'Kane'), 2, WonVia::Match, null, null);

    expect($first->refresh()->status)->toBe(RematchClauseStatus::Voided)
        ->and(clauseFor($save, 'wwe')->former_ids)->toBe([GameBuilder::wrestler($save, 'Edge')->id]);

    app(TitleService::class)->vacate($save, $title, ReignEndReason::Injured, 2);

    expect(clauseFor($save, 'wwe')->status)->toBe(RematchClauseStatus::Voided);
});

it('has the rival book the rematch when its roll hits', function () {
    config(['gm.ai.titles.rematch_clause_chances' => [1.0, 1.0, 1.0, 1.0]]);
    $save = GameBuilder::inSeason(raw: ['John Cena'], smackdown: ['Batista', 'Rey Mysterio', 'Kane', 'Edge', 'Snitsky', 'Carlito', 'Tajiri', 'Val Venis']);
    GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
    $title = Championship::where('key', 'world_heavyweight')->sole();
    app(TitleService::class)->changeHands($save, $title, GameBuilder::wrestler($save, 'Rey Mysterio'), 0, WonVia::Match, null, null);
    $show = Card::show($save, Brand::Smackdown);

    app(AiBooker::class)->book($show, Brand::Smackdown);

    $rematch = MatchSlot::with('participants')->where('show_id', $show->id)->where('title_championship_id', $title->id)->get()
        ->first(fn (MatchSlot $s): bool => app(RematchClauseService::class)->fits(clauseFor($save, 'world_heavyweight'), $s));
    expect($rematch)->not->toBeNull();
});

it("has the rival skip the rematch when a holder can't be booked", function () {
    config(['gm.ai.titles.rematch_clause_chances' => [1.0, 1.0, 1.0, 1.0], 'gm.ai.booking.careful_chance' => 1.0]);
    $save = GameBuilder::inSeason(raw: ['John Cena'], smackdown: ['Batista', 'Rey Mysterio', 'Kane', 'Edge', 'Snitsky', 'Carlito', 'Tajiri', 'Val Venis']);
    GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
    $title = Championship::where('key', 'world_heavyweight')->sole();
    app(TitleService::class)->changeHands($save, $title, GameBuilder::wrestler($save, 'Rey Mysterio'), 0, WonVia::Match, null, null);
    // Batista is worn out: no match keeps him at or under the careful limit.
    GameBuilder::wrestler($save, 'Batista')->update(['fatigue' => 100]);
    $show = Card::show($save, Brand::Smackdown);

    app(AiBooker::class)->book($show, Brand::Smackdown);

    $rematch = MatchSlot::with('participants')->where('show_id', $show->id)->where('title_championship_id', $title->id)->get()
        ->first(fn (MatchSlot $s): bool => app(RematchClauseService::class)->fits(clauseFor($save, 'world_heavyweight'), $s));
    expect($rematch)->toBeNull();
});
