<?php

use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Models\NewsEvent;
use App\Services\News\NewsComposer;
use App\Services\News\NewsRecorder;
use App\Services\Week\WeekResolver;
use Tests\Support\GameBuilder;

/*
| §19 News layout rules.
*/

function news($save, NewsEventType $type, NewsTier $tier, NewsBrand $brand, int $priority = 0, ?string $dedupe = null, array $payload = []): void
{
    app(NewsRecorder::class)->record($save, $type, $tier, $brand, $payload, [], $priority, 1, $dedupe);
}

it('fills one headline per slot with the highest-priority headline', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    news($save, NewsEventType::TitleChange, NewsTier::Headline, NewsBrand::Raw, 50, payload: ['winner' => 'Low']);
    news($save, NewsEventType::TitleChange, NewsTier::Headline, NewsBrand::Raw, 90, payload: ['winner' => 'High']);
    news($save, NewsEventType::TradeCompleted, NewsTier::Headline, NewsBrand::Both, 80);
    news($save, NewsEventType::TitleVacated, NewsTier::Headline, NewsBrand::Smackdown, 70);

    $page = app(NewsComposer::class)->compose($save, 1);

    expect($page['headlines']['raw']['text'])->toContain('High')
        ->and($page['headlines']['smackdown']['type'])->toBe('title_vacated')
        ->and($page['headlines']['neutral']['type'])->toBe('trade_completed')
        // The leftover Raw headline drops to the story row.
        ->and(collect($page['stories']['raw'])->pluck('text')->implode(' '))->toContain('Low');
});

it('promotes a brand’s top story when it has no headline', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    news($save, NewsEventType::WinStreak, NewsTier::Story, NewsBrand::Smackdown, 20);
    news($save, NewsEventType::Released, NewsTier::Story, NewsBrand::Smackdown, 10);

    $page = app(NewsComposer::class)->compose($save, 1);

    expect($page['headlines']['smackdown']['type'])->toBe('win_streak')
        ->and($page['headlines']['smackdown']['promoted'])->toBeTrue()
        ->and($page['stories']['smackdown'])->toHaveCount(1);
});

it('keeps two stories per brand and drops leftovers to the ticker', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    news($save, NewsEventType::TitleChange, NewsTier::Headline, NewsBrand::Raw, 100);

    foreach ([40, 30, 20] as $p) {
        news($save, NewsEventType::Released, NewsTier::Story, NewsBrand::Raw, $p);
    }

    news($save, NewsEventType::LosingStreak, NewsTier::Ticker, NewsBrand::Raw);

    $page = app(NewsComposer::class)->compose($save, 1);

    expect($page['stories']['raw'])->toHaveCount(2)
        ->and(collect($page['stories']['raw'])->pluck('priority')->all())->toBe([40, 30])
        ->and(collect($page['ticker'])->pluck('priority')->all())->toContain(20)
        ->and(collect($page['ticker'])->pluck('type')->all())->toContain('losing_streak');
});

it('dedupes title_change and streak_snapped from the same match, keeping the title change as headline', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    news($save, NewsEventType::StreakSnapped, NewsTier::Headline, NewsBrand::Raw, 60, 'slot:9');
    news($save, NewsEventType::TitleChange, NewsTier::Headline, NewsBrand::Raw, 100, 'slot:9');

    $page = app(NewsComposer::class)->compose($save, 1);

    expect($page['headlines']['raw']['type'])->toBe('title_change')
        ->and($page['stories']['raw'][0]['type'])->toBe('streak_snapped')
        ->and($page['stories']['raw'][0]['tier'])->toBe('story');
});

it('renders template placeholders from the payload', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    news($save, NewsEventType::TitleChange, NewsTier::Headline, NewsBrand::Raw, 100, payload: ['winner' => 'Edge', 'loser' => 'Cena', 'title' => 'WWE Championship', 'show' => 'Raw']);

    expect(app(NewsComposer::class)->compose($save, 1)['headlines']['raw']['text'])
        ->toBe('NEW CHAMPION! Edge defeats Cena for the WWE Championship at Raw!');
});

it('archives news per week, newest first', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    foreach (range(1, 3) as $ignored) {
        app(WeekResolver::class)->resolve($save->refresh());
    }

    $weeks = app(NewsComposer::class)->weeks($save);

    expect($weeks)->not->toBeEmpty()
        ->and($weeks)->toBe(collect($weeks)->sortDesc()->values()->all());
});

it('announces the next PPV two weeks out as a story and one week out as a headline', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    // Backlash (Raw) is week 4.
    foreach (range(1, 3) as $ignored) {
        app(WeekResolver::class)->resolve($save->refresh());
    }

    $upcoming = NewsEvent::where('type', 'ppv_upcoming')->where('payload->ppv', 'Backlash')->orderBy('week_number')->get();

    expect($upcoming->pluck('tier')->map->value->all())->toBe(['story', 'headline'])
        ->and($upcoming->pluck('week_number')->all())->toBe([2, 3]);
});
