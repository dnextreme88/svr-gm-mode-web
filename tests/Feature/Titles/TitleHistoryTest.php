<?php

use App\Enums\Brand;
use App\Enums\MatchMethod;
use App\Enums\ReignEndReason;
use App\Enums\Stipulation;
use App\Enums\TitleHolderType;
use App\Enums\WonVia;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\MatchResult;
use App\Models\MatchType;
use App\Models\NewsEvent;
use App\Models\Save;
use App\Models\Show;
use App\Models\TitleReign;
use App\Services\Contracts\ContractService;
use App\Services\Titles\ChampionshipAssignment;
use App\Services\Titles\TitleRecords;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| §16 Title History.
*/

function wwe(): Championship
{
    return Championship::where('key', 'wwe')->firstOrFail();
}

function lineage(Save $save, string $key = 'wwe')
{
    return TitleReign::where('save_id', $save->id)->where('championship_id', Championship::where('key', $key)->value('id'))->orderBy('id')->get();
}

function air(Save $save): void
{
    app(WeekResolver::class)->resolve($save->refresh());
}

function titleMatch(Save $save, string $champion, string $challenger, string $key = 'wwe', int $position = 1, ?MatchType $type = null, ?Stipulation $stip = null): void
{
    Card::book($save, Card::show($save), $position, $type ?? Card::singles(), [[$champion], [$challenger]], [], Championship::where('key', $key)->value('id'), $stip);
}

it('starts a new reign linked to the show and match when the title changes hands', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'John Cena'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    titleMatch($save, 'Edge', 'John Cena');
    FixedOutcomes::queue('John Cena');

    air($save);

    $reigns = lineage($save);
    $show = Show::where('save_id', $save->id)->where('week_number', 1)->where('brand', 'raw')->firstOrFail();

    expect($reigns)->toHaveCount(2)
        ->and($reigns[0]->end_reason)->toBe(ReignEndReason::LostMatch)
        ->and($reigns[0]->lost_week)->toBe(1)
        ->and($reigns[0]->lost_show_id)->toBe($show->id)
        ->and($reigns[1]->holderName())->toBe('John Cena')
        ->and($reigns[1]->reign_number)->toBe(2)
        ->and($reigns[1]->won_via)->toBe(WonVia::Match)
        ->and($reigns[1]->won_show_id)->toBe($show->id)
        ->and($reigns[1]->won_match_id)->not->toBeNull()
        ->and(MatchResult::first()->title_banner)->toBe('new_champion')
        ->and(NewsEvent::where('type', 'title_change')->count())->toBe(1);
});

it('counts successful defenses', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'John Cena'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    titleMatch($save, 'Edge', 'John Cena');
    FixedOutcomes::queue('Edge');

    air($save);

    expect(lineage($save)->sole()->defenses)->toBe(1)
        ->and(MatchResult::first()->title_banner)->toBe('and_still');
});

it('keeps the title with the champion on a DQ or count-out loss', function (MatchMethod $method) {
    $save = GameBuilder::inSeason(raw: ['Edge', 'John Cena'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    titleMatch($save, 'Edge', 'John Cena');
    FixedOutcomes::queue('John Cena', $method);

    air($save);

    expect(lineage($save)->sole()->holderName())->toBe('Edge')
        ->and(MatchResult::first()->title_changed)->toBeFalse()
        ->and(NewsEvent::where('type', 'retained_by_dq')->count())->toBe(1);
})->with([MatchMethod::Dq, MatchMethod::Countout]);

it('vacates on release and on contract expiry', function (string $how, ReignEndReason $reason) {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    $edge = GameBuilder::wrestler($save, 'Edge');

    $how === 'release'
        ? app(ContractService::class)->release($edge, 1)
        : tap($edge->activeContract)->update(['weeks_remaining' => 1]) && air($save);

    $reigns = lineage($save);

    expect($reigns[0]->end_reason)->toBe($reason)
        ->and($reigns[1]->holder_type)->toBe(TitleHolderType::Vacant)
        ->and($reigns[1]->end_reason)->toBe($reason);
})->with([['release', ReignEndReason::Released], ['expiry', ReignEndReason::ContractExpired]]);

it('vacates a tag title when either member leaves, keeping the members in the snapshot', function () {
    $save = GameBuilder::inSeason(raw: ['Lance Cade', 'Trevor Murdoch', 'Kane'], smackdown: ['Batista']);
    $reign = GameBuilder::giveTitle($save, 'world_tag_team', ['Lance Cade', 'Trevor Murdoch']);

    expect($reign->holder_type)->toBe(TitleHolderType::TagTeam)
        ->and($reign->holder_snapshot['names'])->toEqualCanonicalizing(['Lance Cade', 'Trevor Murdoch']);

    app(ContractService::class)->release(GameBuilder::wrestler($save, 'Trevor Murdoch'), 1);
    $reign->tagTeam?->members()->detach();

    $old = lineage($save, 'world_tag_team')->first();

    expect($old->end_reason)->toBe(ReignEndReason::Released)
        ->and($old->holderName())->toBe('Lance Cade & Trevor Murdoch');
});

it('shows vacant periods and fills them by award or by a vacancy match', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'John Cena', 'Kane'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    app(ContractService::class)->release(GameBuilder::wrestler($save, 'Edge'), 1);

    // Won in a match while vacant.
    titleMatch($save, 'John Cena', 'Kane');
    FixedOutcomes::queue('Kane');
    air($save);

    $reigns = lineage($save);

    expect($reigns)->toHaveCount(3)
        ->and($reigns[1]->isVacant())->toBeTrue()
        ->and($reigns[1]->lost_week)->toBe(1)
        ->and($reigns[2]->won_via)->toBe(WonVia::VacancyMatch)
        ->and($reigns[2]->holderName())->toBe('Kane');

    // Awarded while vacant.
    app(ContractService::class)->release(GameBuilder::wrestler($save, 'Kane'), 2);
    $awarded = app(ChampionshipAssignment::class)->assign($save->refresh(), wwe(), [GameBuilder::wrestler($save, 'John Cena')->id]);

    expect($awarded->won_via)->toBe(WonVia::Awarded)
        ->and($awarded->reign_number)->toBe(3);
});

it('only lets a title be on the line if the champion is in the match', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'John Cena', 'Kane'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);

    titleMatch($save, 'Kane', 'John Cena');
})->throws(GameRuleException::class, 'champion(s) are in the match');

it('enforces title eligibility for challengers', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Rey Mysterio', 'Batista', 'Paul London']);
    GameBuilder::giveTitle($save, 'cruiserweight', ['Rey Mysterio']);
    $show = Card::show($save, Brand::Smackdown);
    $save->update(['player_brand' => 'smackdown']);
    $cruiser = Championship::where('key', 'cruiserweight')->value('id');

    expect(fn () => Card::book($save, $show, 1, Card::singles(), [['Rey Mysterio'], ['Batista']], [], $cruiser))
        ->toThrow(GameRuleException::class, 'eligible');

    Card::book($save, $show, 1, Card::singles(), [['Rey Mysterio'], ['Paul London']], [], $cruiser);
    expect(fn () => Card::book($save, $show, 2, Card::singles(), [['Rey Mysterio'], ['Paul London']], [], $cruiser))
        ->toThrow(GameRuleException::class, 'already on this card');
});

it('keeps title records per title and overall', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'John Cena'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);

    titleMatch($save, 'Edge', 'John Cena');
    FixedOutcomes::queue('John Cena');
    air($save);

    titleMatch($save->refresh(), 'John Cena', 'Edge');
    FixedOutcomes::queue('Edge');
    air($save);

    $records = app(TitleRecords::class)->records($save->refresh(), wwe());

    expect($records['most_reigns'])->toBe(['holder' => 'Edge', 'value' => 2])
        ->and($records['longest']['holder'])->not->toBeNull()
        ->and($records['shortest'])->not->toBeNull()
        ->and(app(TitleRecords::class)->records($save)['most_reigns']['value'])->toBe(2);
});

it('renders the Title History screen', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);

    $this->get(route('gm.titles.history', $save))
        ->assertInertia(fn ($page) => $page->component('gm/titles/history')->has('titles', 8)->has('hall')->has('records'));
});

it('lists each reign’s defenses, newest first, linked to their results', function () {
    // Keep the rival's card empty so only these two results exist.
    config(['gm.ai.roster.target_size' => 0]);
    $save = GameBuilder::inSeason(raw: ['Edge', 'John Cena', 'Kane'], smackdown: ['Batista']);
    $reign = GameBuilder::giveTitle($save, 'wwe', ['Edge']);

    titleMatch($save, 'Edge', 'John Cena');
    FixedOutcomes::queue('Edge');
    air($save);

    titleMatch($save->refresh(), 'Edge', 'Kane');
    FixedOutcomes::queue('Kane', MatchMethod::Dq);
    air($save);

    $results = MatchResult::orderBy('id')->get();

    expect($results->pluck('champion_reign_id')->all())->toBe([$reign->id, $reign->id]);

    $this->get(route('gm.titles.history', $save))
        ->assertInertia(fn ($page) => $page
            ->where('titles.0.lineage.0.how', 'Awarded')
            ->where('titles.0.lineage.0.holder_reign', 1)
            // An awarded title has no match it was won in.
            ->has('titles.0.lineage.0.matches', 2)
            // Newest first; a DQ loss is still a defense.
            ->where('titles.0.lineage.0.matches.0.number', 2)
            ->where('titles.0.lineage.0.matches.0.opponents', 'Kane')
            ->where('titles.0.lineage.0.matches.1.number', 1)
            ->where('titles.0.lineage.0.matches.1.opponents', 'John Cena')
            ->where('titles.0.lineage.0.matches.1.season_week', 1)
            ->where('titles.0.lineage.0.matches.1.match_type', Card::singles()->name)
            ->where('titles.0.lineage.0.matches.1.link', route('gm.results', [$save, 1, 'match' => $results[0]->match_slot_id, 'brand' => 'raw'], false)));
});

it('counts the holder’s own reigns, names the winning match and the vacancy reason', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'John Cena', 'Kane'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);

    titleMatch($save, 'Edge', 'John Cena');
    FixedOutcomes::queue('John Cena');
    air($save);

    titleMatch($save->refresh(), 'John Cena', 'Edge');
    FixedOutcomes::queue('Edge');
    air($save);

    app(ContractService::class)->release(GameBuilder::wrestler($save, 'Edge'), 3);

    $this->get(route('gm.titles.history', $save))
        ->assertInertia(fn ($page) => $page
            // Newest first: vacant, Edge (2nd reign), Cena, Edge (1st reign).
            ->where('titles.0.lineage.0.holder_type', 'vacant')
            ->where('titles.0.lineage.0.vacated_reason', 'released')
            ->where('titles.0.lineage.0.holder_reign', null)
            ->where('titles.0.lineage.1.holder', 'Edge')
            ->where('titles.0.lineage.1.reign_number', 3)
            ->where('titles.0.lineage.1.holder_reign', 2)
            ->where('titles.0.lineage.1.how', Card::singles()->name)
            ->where('titles.0.lineage.1.won_ppv', null)
            // Below Edge's second reign: the match he won it back in.
            ->has('titles.0.lineage.1.matches', 1)
            ->where('titles.0.lineage.1.matches.0.kind', 'won')
            ->where('titles.0.lineage.1.matches.0.number', null)
            ->where('titles.0.lineage.1.matches.0.opponents', 'John Cena')
            ->where('titles.0.lineage.2.holder_reign', 1)
            ->where('titles.0.lineage.3.holder_reign', 1));
});
