<?php

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Enums\ContractStatus;
use App\Enums\MatchMethod;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\PromoTypeKey;
use App\Models\Championship;
use App\Models\Contract;
use App\Models\NewsEvent;
use App\Models\PromoType;
use App\Models\Save;
use App\Services\Contracts\ContractService;
use App\Services\News\NewsTemplates;
use App\Services\News\PromoStories;
use App\Services\Promos\PromoService;
use App\Services\Rivalries\RivalryService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| What each week feeds into the News pool.
*/

function newsWeek(Save $save): void
{
    app(WeekResolver::class)->resolve($save->refresh());
}

function newsPromo(Save $save, PromoTypeKey $key, ?Brand $brand = null): void
{
    app(PromoService::class)->book(Card::show($save->refresh(), $brand ?? Brand::Raw), 1, PromoType::where('key', $key)->firstOrFail());
}

function newsOf(NewsEventType $type)
{
    return NewsEvent::where('type', $type)->get();
}

it('records every aired match as a story for its brand', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian', 'Shelton Benjamin'], smackdown: ['Batista', 'JBL']);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);
    Card::book($save, Card::show($save), 2, Card::singles(), [['Christian'], ['Shelton Benjamin']]);
    Card::book($save, Card::show($save, Brand::Smackdown), 1, Card::singles(), [['Batista'], ['JBL']]);
    FixedOutcomes::queue('Edge')->queue('Christian', MatchMethod::Dq)->queue('Batista');

    newsWeek($save);

    $results = newsOf(NewsEventType::MatchResult);

    expect($results)->toHaveCount(3)
        ->and($results->every(fn (NewsEvent $e): bool => $e->tier === NewsTier::Story))->toBeTrue()
        ->and($results->pluck('brand')->map->value->sort()->values()->all())->toBe(['raw', 'raw', 'smackdown'])
        ->and($results->pluck('rendered_text')->implode(' | '))->toContain(
            'Edge defeats Kane in decisive fashion. He made sure to get the win via pinfall.',
            'Christian defeats Shelton Benjamin via disqualification. Not the cleanest win, but a win is a win.',
        );
});

it('sums up a match result with the match in a word, not its full type name', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian', 'Shelton Benjamin', 'Carlito'], smackdown: ['Batista']);
    Card::book($save, Card::show($save), 1, Card::type('ladder', 'Single w/Manager vs. w/Manager'), [['Edge'], ['Kane']], ['Carlito', 'Christian']);
    FixedOutcomes::queue('Edge', MatchMethod::MatchSpecific);

    newsWeek($save);

    $event = newsOf(NewsEventType::MatchResult)->sole();
    $templates = app(NewsTemplates::class);

    expect($templates->render(NewsEventType::MatchResult, $event->payload))->toBe('Edge defeats Kane in decisive fashion. He made sure to get the win in a Ladder match.')
        ->and($templates->summary(NewsEventType::MatchResult, $event->payload))->toBe('In what was a thrilling match, Edge got the win over Kane as they competed in a thrilling Ladder match!');
});

it('calls a Diva winner she and a singles match singles', function () {
    $save = GameBuilder::inSeason(raw: ['Trish Stratus', 'Victoria'], smackdown: ['Batista']);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Trish Stratus'], ['Victoria']]);
    FixedOutcomes::queue('Trish Stratus');

    newsWeek($save);

    $event = newsOf(NewsEventType::MatchResult)->sole();

    expect($event->rendered_text)->toContain('She made sure to get the win via pinfall.')
        ->and(app(NewsTemplates::class)->summary(NewsEventType::MatchResult, $event->payload))->toEndWith('in a thrilling singles match!');
});

it('words a title change by how it was won, with she for the Women’s title and he for the rest', function (string $key, string $champion, string $challenger, string $pronoun) {
    $save = GameBuilder::inSeason(raw: ['Edge', 'John Cena', 'Trish Stratus', 'Victoria'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, $key, [$champion]);
    Card::book($save, Card::show($save), 1, Card::singles(), [[$champion], [$challenger]], [], Championship::where('key', $key)->value('id'));
    FixedOutcomes::queue($challenger, MatchMethod::Submission);

    newsWeek($save);

    $event = newsOf(NewsEventType::TitleChange)->sole();
    $title = Championship::where('key', $key)->value('name');

    expect($event->rendered_text)->toBe("NEW CHAMPION! {$challenger} defeats {$champion} for the {$title} via submission!")
        ->and(app(NewsTemplates::class)->summary(NewsEventType::TitleChange, $event->payload))
        ->toBe("{$challenger} beat {$champion} via submission and walks out with the {$title}! The champion looks forward to meeting new challengers that {$pronoun} will dethrone!");
})->with([
    ['womens', 'Trish Stratus', 'Victoria', 'she'],
    ['wwe', 'Edge', 'John Cena', 'he'],
]);

it('ranks the main event highest, a gimmick match above a plain one, and a DQ win lowest', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian', 'Shelton Benjamin', 'Carlito', 'Kurt Angle'], smackdown: ['Batista']);
    Card::book($save, Card::show($save), 1, Card::type('ladder', 'Single'), [['Edge'], ['Kane']]);
    Card::book($save, Card::show($save), 2, Card::singles(), [['Christian'], ['Shelton Benjamin']]);
    Card::book($save, Card::show($save), 3, Card::singles(), [['Carlito'], ['Kurt Angle']]);
    FixedOutcomes::queue('Edge', MatchMethod::MatchSpecific)->queue('Christian', MatchMethod::Dq)->queue('Carlito');

    newsWeek($save);

    $byWinner = newsOf(NewsEventType::MatchResult)->where('brand.value', 'raw')->keyBy('payload.winner');
    $cfg = config('gm.news.match_result_priority');

    expect($byWinner['Edge']->priority)->toBe($cfg['other'] + $cfg['gimmick_bonus'])
        ->and($byWinner['Christian']->priority)->toBe($cfg['other'] - $cfg['not_decisive_penalty'])
        ->and($byWinner['Carlito']->priority)->toBe($cfg['main_event'])
        ->and($byWinner->map(fn (NewsEvent $e) => $e->payload['main_event'])->all())->toBe(['Edge' => false, 'Christian' => false, 'Carlito' => true]);
});

it('ranks an undercard title match above a non-title gimmick main event, and Table tags as gimmicks', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian', 'Shelton Benjamin', 'Carlito', 'Kurt Angle', 'Chris Masters', 'Tajiri'], smackdown: ['Batista']);
    $title = GameBuilder::giveTitle($save, 'intercontinental', ['Kane'])->championship_id;
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']], titleId: $title);
    Card::book($save, Card::show($save), 2, Card::type('tag_team', 'Table Tornado Tag Elimination'), [['Christian', 'Shelton Benjamin'], ['Chris Masters', 'Tajiri']]);
    Card::book($save, Card::show($save), 3, Card::type('ladder', 'Single'), [['Carlito'], ['Kurt Angle']]);
    FixedOutcomes::queue('Kane')->queue('Christian', MatchMethod::MatchSpecific)->queue('Carlito', MatchMethod::MatchSpecific);

    newsWeek($save);

    $cfg = config('gm.news.match_result_priority');
    $byWinner = newsOf(NewsEventType::MatchResult)->where('brand.value', 'raw')->keyBy('payload.winner');

    expect($byWinner['Kane']->priority)->toBe($cfg['other'] + $cfg['title_bonus'])
        ->and($byWinner['Kane']->priority)->toBeGreaterThan($byWinner['Carlito']->priority)
        ->and($byWinner['Christian & Shelton Benjamin']->priority)->toBe($cfg['other'] + $cfg['gimmick_bonus'])
        ->and($byWinner['Carlito']->priority)->toBe($cfg['main_event'] + $cfg['gimmick_bonus']);
});

it('turns the main event into a headline after a successful Main Event Hype', function (float $rate, NewsEventType $expected) {
    config(['gm.promos.success_rates.main_event_hype' => $rate]);
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $show = Card::show($save);
    Card::book($save, $show, $show->matchSlotCount(), Card::singles(), [['Edge'], ['Kane']]);
    newsPromo($save, PromoTypeKey::MainEventHype);
    FixedOutcomes::queue('Edge');

    newsWeek($save);

    $event = NewsEvent::whereIn('type', [NewsEventType::MatchResult, NewsEventType::MainEventHypeResult])->sole();

    expect($event->type)->toBe($expected);
})->with([
    'success' => [1.0, NewsEventType::MainEventHypeResult],
    'failure' => [0.0, NewsEventType::MatchResult],
]);

it('treats the last booked match as the main event when the later slots are empty', function () {
    config(['gm.promos.success_rates.main_event_hype' => 1.0]);
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian', 'Shelton Benjamin'], smackdown: ['Batista']);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Christian'], ['Shelton Benjamin']]);
    Card::book($save, Card::show($save), 2, Card::singles(), [['Edge'], ['Kane']]);
    // The hype airs "before the main event" slot, which is empty, so it resolves after every match.
    newsPromo($save, PromoTypeKey::MainEventHype);
    FixedOutcomes::queue('Christian')->queue('Edge');

    newsWeek($save);

    $hype = newsOf(NewsEventType::MainEventHypeResult)->sole();

    expect($hype->payload['story'])->toBe('main_event_hype.success_match')
        ->and($hype->payload['winner'])->toBe('Edge')
        ->and($hype->payload['loser'])->toBe('Kane')
        ->and(newsOf(NewsEventType::MatchResult)->sole()->rendered_text)->toStartWith('Christian defeats Shelton Benjamin');
});

it('words a tag team win in the plural and drops the stray period after a show name ending in !', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista', 'JBL', 'Christian', 'Booker T']);
    Card::book($save, Card::show($save, Brand::Smackdown), 1, Card::type('tag_team', 'Normal Tag'), [['Batista', 'JBL'], ['Christian', 'Booker T']]);
    FixedOutcomes::queue('Batista');

    newsWeek($save);

    $text = newsOf(NewsEventType::MatchResult)->sole()->rendered_text;

    expect($text)->toStartWith('Batista & JBL defeat Christian & Booker T in decisive fashion. They made sure')
        ->and($text)->not->toContain('!.');
});

it('records a promo result for the brand that ran it, except Slander and Interference', function () {
    config(['gm.promos.success_rates.advertising' => 1.0]);
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    newsPromo($save, PromoTypeKey::Advertising);
    newsPromo($save, PromoTypeKey::SlanderPromotion, Brand::Smackdown);

    newsWeek($save);

    // The rival GM books its own promos on Smackdown, so look at Raw's.
    $result = newsOf(NewsEventType::PromoResult)->where('brand.value', 'raw')->sole();

    expect($result->brand->value)->toBe('raw')
        ->and($result->payload['story'])->toBe('advertising.success')
        ->and($result->rendered_text)->toBe(str_replace('{brand}', 'Raw', PromoStories::story('advertising.success', $result->payload['variant'])[0]))
        ->and(newsOf(NewsEventType::SlanderResult))->toHaveCount(1)
        ->and(newsOf(NewsEventType::PromoResult)->pluck('payload.promo')->all())->not->toContain('Slander Promotion');
});

it('makes a free-agent signing a story', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    app(ContractService::class)->signFreeAgent($save, GameBuilder::wrestler($save, 'Kane'), Brand::Raw, ContractLength::OneYear, 1);

    $signed = newsOf(NewsEventType::FreeAgentSigned)->sole();

    expect($signed->tier)->toBe(NewsTier::Story)
        ->and($signed->brand->value)->toBe('raw');
});

it('reports a contract with 2 weeks left', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    Contract::where('save_wrestler_id', GameBuilder::wrestler($save, 'Edge')->id)->where('status', ContractStatus::Active)->update(['weeks_remaining' => 3]);

    newsWeek($save);

    expect(newsOf(NewsEventType::ContractExpiring)->sole()->rendered_text)->toBe("Edge's Raw contract runs out in 2 weeks.");
});

it('celebrates a rivalry that stays hot for 10 straight weeks, once', function (int $heat, int $expected) {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $rivalry = app(RivalryService::class)->start($save, GameBuilder::wrestler($save, 'Edge')->id, GameBuilder::wrestler($save, 'Kane')->id, 1);
    $rivalry->update(['weeks_active' => 9, 'heat' => $heat, 'last_activity_week' => 1]);

    newsWeek($save);
    newsWeek($save);

    expect(newsOf(NewsEventType::RivalryMilestone))->toHaveCount($expected);
})->with([
    'hot' => [90, 1],
    'cold' => [20, 0],
]);
