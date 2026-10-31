<?php

use App\Enums\MatchMethod;
use App\Enums\NewsEventType;
use App\Models\Championship;
use App\Models\MatchType;
use App\Models\NewsEvent;
use App\Services\Matches\ResolvedMatch;
use App\Services\News\NewsTemplates;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| House rule (2026-10-28): a match won the one way its type allows names the
| match ("in a Tables match"), not the finish ("via put through a table").
*/

function resolvedBy(MatchMethod $method): ResolvedMatch
{
    return new ResolvedMatch(0, 1, [1], [2], $method, null, null, '', 0);
}

it('names the match for a win by its own condition', function (string $family, string $variant, MatchMethod $method, string $how) {
    GameBuilder::seedCatalog();
    $type = MatchType::where('family', $family)->where('variant', $variant)->firstOrFail();

    expect(resolvedBy($method)->how($type))->toBe($how);
})->with([
    'Tables' => ['table', 'Single', MatchMethod::MatchSpecific, 'in a Tables match'],
    'Table tag' => ['tag_team', 'Table Tornado Tag Elimination', MatchMethod::MatchSpecific, 'in a Tables match'],
    'Ladder' => ['ladder', 'Single', MatchMethod::MatchSpecific, 'in a Ladder match'],
    'First Blood' => ['first_blood', 'Single', MatchMethod::MatchSpecific, 'in a First Blood match'],
    'Last Man Standing' => ['last_man_standing', 'Single', MatchMethod::MatchSpecific, 'in a Last Man Standing match'],
    'Steel Cage' => ['steel_cage', 'Single', MatchMethod::MatchSpecific, 'in a Steel Cage match'],
    'Submission' => ['submission', 'Single', MatchMethod::Submission, 'in a Submission match'],
    'Iron Man' => ['iron_man', 'Single', MatchMethod::MatchSpecific, 'in an Iron Man match'],
]);

it('keeps "via" for pinfalls, submissions and DQs', function () {
    GameBuilder::seedCatalog();
    $singles = Card::singles();
    $steelCage = MatchType::where('family', 'steel_cage')->where('variant', 'Single')->firstOrFail();

    expect(resolvedBy(MatchMethod::Pinfall)->how($singles))->toBe('via pinfall')
        ->and(resolvedBy(MatchMethod::Submission)->how($singles))->toBe('via submission')
        ->and(resolvedBy(MatchMethod::Dq)->how($steelCage))->toBe('via disqualification');
});

it('writes a Tables title change as "in a Tables match"', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Lance Cade', 'Trevor Murdoch', 'Rene Dupree', 'Rob Conway'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'world_tag_team', ['Rene Dupree', 'Rob Conway']);
    $title = Championship::where('key', 'world_tag_team')->sole();
    Card::book($save, Card::show($save), 1, Card::type('table', 'Tornado Tag'), [['Lance Cade', 'Trevor Murdoch'], ['Rene Dupree', 'Rob Conway']], titleId: $title->id);
    FixedOutcomes::queue('Lance Cade', MatchMethod::MatchSpecific);

    app(WeekResolver::class)->resolve($save->refresh());

    $news = NewsEvent::where('type', NewsEventType::TitleChange)->sole();

    expect($news->rendered_text)->toBe('NEW CHAMPION! Lance Cade & Trevor Murdoch defeats La Résistance for the World Tag Team Championship in a Tables match!')
        ->and(app(NewsTemplates::class)->summary(NewsEventType::TitleChange, $news->payload))
        ->toStartWith('Lance Cade & Trevor Murdoch beat La Résistance in a Tables match and walk out with the World Tag Team Championship!');
});

it('keeps the wording of news recorded before, which has only "via"', function () {
    $payload = ['winner' => 'Edge', 'loser' => 'Kane', 'title' => 'WWE Championship', 'show' => 'Raw', 'via' => 'briefcase retrieval'];

    expect(app(NewsTemplates::class)->render(NewsEventType::TitleChange, $payload))
        ->toBe('NEW CHAMPION! Edge defeats Kane for the WWE Championship via briefcase retrieval!');
});
