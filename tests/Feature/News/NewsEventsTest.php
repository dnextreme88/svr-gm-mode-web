<?php

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Enums\ContractStatus;
use App\Enums\MatchMethod;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\PromoTypeKey;
use App\Models\Contract;
use App\Models\NewsEvent;
use App\Models\PromoType;
use App\Models\Save;
use App\Services\Contracts\ContractService;
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
        ->and($results->pluck('rendered_text')->implode(' | '))->toContain('Edge defeats Kane at ', 'Christian defeats Shelton Benjamin by disqualification at ');
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

    expect($text)->toStartWith('Batista & JBL defeat Christian & Booker T at ')
        ->and($text)->not->toContain('!.');
});

it('records a promo result for the brand that ran it, except Slander and Interference', function () {
    config(['gm.promos.success_rates.advertising' => 1.0]);
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    newsPromo($save, PromoTypeKey::Advertising);
    newsPromo($save, PromoTypeKey::SlanderPromotion, Brand::Smackdown);

    newsWeek($save);

    // The rival GM books its own promos on SmackDown!, so look at Raw's.
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
