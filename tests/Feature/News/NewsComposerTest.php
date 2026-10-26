<?php

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Models\NewsEvent;
use App\Services\Contracts\ContractService;
use App\Services\News\NewsCatalog;
use App\Services\News\NewsComposer;
use App\Services\News\NewsRecorder;
use App\Services\News\NewsTemplates;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| §19 News layout rules: one pool per week, drawn with the seeded RNG.
*/

function news($save, NewsEventType $type, NewsTier $tier, NewsBrand $brand, int $priority = 0, ?string $dedupe = null, array $payload = []): void
{
    app(NewsRecorder::class)->record($save, $type, $tier, $brand, $payload, [], $priority, 1, $dedupe);
}

/**
 * Every event id on a composed page, in headline → story → ticker order.
 *
 * @return list<int>
 */
function pageIds(array $page): array
{
    return [
        ...collect($page['headlines'])->filter()->pluck('id'),
        ...collect($page['stories'])->flatten(1)->pluck('id'),
        ...collect($page['ticker'])->pluck('id'),
    ];
}

it('lays out the same page for the same week every time', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    foreach (range(1, 6) as $i) {
        news($save, NewsEventType::Released, NewsTier::Story, NewsBrand::Raw, $i * 5);
        news($save, NewsEventType::Released, NewsTier::Story, NewsBrand::Smackdown, $i * 5);
    }

    $composer = app(NewsComposer::class);

    expect($composer->compose($save, 1))->toBe($composer->compose($save, 1));
});

it('puts every event on the page exactly once', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    news($save, NewsEventType::TitleChange, NewsTier::Headline, NewsBrand::Raw, 100);
    news($save, NewsEventType::TradeCompleted, NewsTier::Headline, NewsBrand::Both, 85);
    news($save, NewsEventType::LegendSigned, NewsTier::Headline, NewsBrand::Both, 60);

    foreach ([40, 30, 20] as $p) {
        news($save, NewsEventType::Released, NewsTier::Story, NewsBrand::Raw, $p);
    }

    news($save, NewsEventType::LosingStreak, NewsTier::Ticker, NewsBrand::Raw);

    $page = app(NewsComposer::class)->compose($save, 1);
    $ids = pageIds($page);

    expect($ids)->toHaveCount(NewsEvent::count())
        ->and(array_unique($ids))->toHaveCount(count($ids))
        ->and($page['stories']['raw'])->toHaveCount(2)
        ->and(collect($page['ticker'])->pluck('type')->all())->toContain('losing_streak');
});

it('picks a brand headline from its headline-tier events when it has any', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    news($save, NewsEventType::TitleChange, NewsTier::Headline, NewsBrand::Raw, 1);

    foreach (range(1, 5) as $ignored) {
        news($save, NewsEventType::Released, NewsTier::Story, NewsBrand::Raw, 90);
    }

    $page = app(NewsComposer::class)->compose($save, 1);

    expect($page['headlines']['raw']['type'])->toBe('title_change')
        ->and($page['headlines']['raw']['promoted'])->toBeFalse();
});

it('promotes one of a brand’s stories when it has no headline', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    news($save, NewsEventType::WinStreak, NewsTier::Story, NewsBrand::Smackdown, 20);
    news($save, NewsEventType::Released, NewsTier::Story, NewsBrand::Smackdown, 10);

    $page = app(NewsComposer::class)->compose($save, 1);

    expect($page['headlines']['smackdown']['promoted'])->toBeTrue()
        ->and($page['stories']['smackdown'])->toHaveCount(1);
});

/**
 * A match result's priority from `gm.news.match_result_priority`.
 */
function rank(bool $main = false, bool $title = false, bool $ppv = false, bool $gimmick = false, bool $dq = false): int
{
    $cfg = config('gm.news.match_result_priority');

    return $cfg[$main ? 'main_event' : 'other'] + ($title ? $cfg['title_bonus'] : 0) + ($ppv ? $cfg['ppv_bonus'] : 0)
        + ($gimmick ? $cfg['gimmick_bonus'] : 0) - ($dq ? $cfg['not_decisive_penalty'] : 0);
}

it('makes the main event a quiet brand’s headline over any non-title undercard match', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    foreach (range(1, 5) as $i) {
        news($save, NewsEventType::MatchResult, NewsTier::Story, NewsBrand::Raw, rank(gimmick: true), "slot:{$i}", ['main_event' => false]);
    }

    news($save, NewsEventType::MatchResult, NewsTier::Story, NewsBrand::Raw, rank(main: true), 'slot:6', ['main_event' => true]);
    $main = NewsEvent::latest('id')->first();

    $headline = app(NewsComposer::class)->compose($save, 1)['headlines']['raw'];

    expect($headline['id'])->toBe($main->id)
        ->and($headline['promoted'])->toBeTrue();
});

it('ranks headlines PPV, then title, then main event, then gimmick', function () {
    expect(rank(ppv: true))->toBeGreaterThan(rank(main: true, title: true, gimmick: true))
        ->and(rank(title: true, dq: true))->toBeGreaterThan(rank(main: true, gimmick: true))
        ->and(rank(main: true, dq: true))->toBeGreaterThan(rank(gimmick: true))
        ->and(rank(gimmick: true))->toBeGreaterThan(rank());
});

it('picks an undercard title match over a non-title main event', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    news($save, NewsEventType::MatchResult, NewsTier::Story, NewsBrand::Raw, rank(title: true), 'slot:2', ['main_event' => false]);
    $title = NewsEvent::latest('id')->first();
    news($save, NewsEventType::MatchResult, NewsTier::Story, NewsBrand::Raw, rank(main: true, gimmick: true), 'slot:6', ['main_event' => true]);

    expect(app(NewsComposer::class)->compose($save, 1)['headlines']['raw']['id'])->toBe($title->id);
});

it('picks the PPV main event over the weekly show’s when both aired that week', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    news($save, NewsEventType::MatchResult, NewsTier::Story, NewsBrand::Raw, rank(main: true, title: true, gimmick: true), 'slot:6', ['main_event' => true, 'ppv' => false]);
    news($save, NewsEventType::MatchResult, NewsTier::Story, NewsBrand::Raw, rank(main: true, ppv: true), 'slot:11', ['main_event' => true, 'ppv' => true]);
    $ppv = NewsEvent::latest('id')->first();

    expect(app(NewsComposer::class)->compose($save, 1)['headlines']['raw']['id'])->toBe($ppv->id);
});

it('promotes the bigger news that replaced the main event result', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    news($save, NewsEventType::MatchResult, NewsTier::Story, NewsBrand::Raw, rank(), 'slot:1', ['main_event' => false]);
    news($save, NewsEventType::MatchResult, NewsTier::Story, NewsBrand::Raw, rank(main: true, title: true), 'slot:6', ['main_event' => true]);
    news($save, NewsEventType::TitleRetained, NewsTier::Story, NewsBrand::Raw, 20, 'slot:6');
    $retained = NewsEvent::latest('id')->first();

    expect(app(NewsComposer::class)->compose($save, 1)['headlines']['raw']['id'])->toBe($retained->id);
});

it('always gives a title change its brand’s headline', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    foreach (range(1, 6) as $i) {
        news($save, NewsEventType::RecordBroken, NewsTier::Headline, NewsBrand::Raw, 200);
    }

    news($save, NewsEventType::TitleChange, NewsTier::Headline, NewsBrand::Raw, 100);
    $change = NewsEvent::latest('id')->first();

    expect(app(NewsComposer::class)->compose($save, 1)['headlines']['raw']['id'])->toBe($change->id);
});

it('never promotes quiet-week filler over real news', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    news($save, NewsEventType::SuperstarRecap, NewsTier::Story, NewsBrand::Raw, 50);
    news($save, NewsEventType::Released, NewsTier::Story, NewsBrand::Raw, 1);
    $real = NewsEvent::latest('id')->first();

    expect(app(NewsComposer::class)->compose($save, 1)['headlines']['raw']['id'])->toBe($real->id);
});

it('keeps brand cards to their own brand and the neutral card to cross-brand news', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    foreach (range(1, 4) as $i) {
        news($save, NewsEventType::Released, NewsTier::Story, NewsBrand::Raw, $i * 10);
    }

    $page = app(NewsComposer::class)->compose($save, 1);

    expect($page['headlines']['neutral'])->toBeNull()
        ->and($page['headlines']['smackdown'])->toBeNull()
        ->and($page['stories']['smackdown'])->toBe([])
        ->and(collect($page['stories']['raw'])->pluck('slot')->unique()->all())->toBe(['raw']);
});

it('always gives the neutral headline to a Slander or Interference result', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    news($save, NewsEventType::TradeCompleted, NewsTier::Headline, NewsBrand::Both, 100);
    news($save, NewsEventType::DefectionCompleted, NewsTier::Headline, NewsBrand::Both, 100);
    news($save, NewsEventType::SlanderResult, NewsTier::Headline, NewsBrand::Both, 1, payload: ['brand' => 'Raw', 'rival_brand' => 'Smackdown', 'outcome' => 'succeeds']);

    expect(app(NewsComposer::class)->compose($save, 1)['headlines']['neutral']['type'])->toBe('slander_result');
});

it('drops a plain match result when bigger news covers the same match', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    news($save, NewsEventType::MatchResult, NewsTier::Story, NewsBrand::Raw, 12, 'slot:9');
    news($save, NewsEventType::TitleChange, NewsTier::Headline, NewsBrand::Raw, 100, 'slot:9');
    news($save, NewsEventType::MatchResult, NewsTier::Story, NewsBrand::Raw, 12, 'slot:10');

    $types = collect(pageIds(app(NewsComposer::class)->compose($save, 1)))
        ->map(fn (int $id): string => NewsEvent::find($id)->type->value)
        ->all();

    expect($types)->toHaveCount(2)
        ->and($types)->toContain('title_change', 'match_result');
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

it('keeps the old wording for title changes recorded before they carried how they were won', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    news($save, NewsEventType::TitleChange, NewsTier::Headline, NewsBrand::Raw, 100, payload: ['winner' => 'Edge', 'loser' => 'Cena', 'title' => 'WWE Championship', 'show' => 'Raw']);

    $headline = app(NewsComposer::class)->compose($save, 1)['headlines']['raw'];

    expect($headline['text'])->toBe('NEW CHAMPION! Edge defeats Cena for the WWE Championship at Raw!')
        ->and($headline['summary'])->toBe('Edge beat Cena at Raw and walks out with the WWE Championship. The old reign is over.');
});

it('has a summary for every news event type', function () {
    $templates = app(NewsTemplates::class);

    expect($templates->summaryTypes())->toEqualCanonicalizing($templates->types())
        ->and($templates->types())->toEqualCanonicalizing(NewsEventType::values());
});

it('describes every news event type on the docs catalog, in a known group', function () {
    $catalog = app(NewsCatalog::class);

    expect($catalog->types())->toEqualCanonicalizing(NewsEventType::values())
        ->and(collect($catalog->entries())->pluck('group')->unique()->diff(NewsCatalog::GROUPS)->all())->toBe([])
        ->and(collect($catalog->entries())->pluck('headline')->implode(' '))->not->toContain('{defeats}', '{by}');
});

it('tells a different story for each Slander outcome', function (string $outcome, string $expected) {
    $summary = app(NewsTemplates::class)->summary(NewsEventType::SlanderResult, ['brand' => 'Raw', 'rival_brand' => 'Smackdown', 'outcome' => $outcome]);

    expect($summary)->toContain($expected);
})->with([
    ['succeeds', 'and it stuck'],
    ['fails', 'nobody bought it'],
    ['backfires', 'one smear campaign too many'],
]);

it('lists every played week, quiet ones included, newest first', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    foreach (range(1, 3) as $ignored) {
        app(WeekResolver::class)->resolve($save->refresh());
    }

    NewsEvent::where('save_id', $save->id)->where('week_number', 1)->delete();

    $composer = app(NewsComposer::class);
    $pages = $composer->pages($save->refresh());

    expect($composer->weeks($save))->toBe([3, 2, 1])
        ->and(collect($pages)->pluck('week')->all())->toBe([3, 2, 1])
        ->and($pages[2]['headlines'])->toBe(['raw' => null, 'smackdown' => null, 'neutral' => null]);
});

it('only shows weeks that have aired, even once news is recorded in the current one', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Christian'], smackdown: ['Batista']);
    $composer = app(NewsComposer::class);

    expect($composer->weeks($save))->toBe([]);

    app(WeekResolver::class)->resolve($save->refresh());
    $save->refresh();

    // Week 2 is being booked: a signing records week 2 news straight away.
    app(ContractService::class)->signFreeAgent($save, GameBuilder::wrestler($save, 'Kane'), Brand::Raw, ContractLength::OneYear, $save->current_week);

    expect($save->current_week)->toBe(2)
        ->and(NewsEvent::where('save_id', $save->id)->where('week_number', 2)->where('type', 'free_agent_signed')->exists())->toBeTrue()
        ->and($composer->weeks($save))->toBe([1])
        ->and(collect($composer->pages($save))->pluck('week')->all())->toBe([1]);

    // Halfway through airing week 2 (Raw's match waits to be decided),
    // it's still hidden.
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Christian']]);
    app(WeekResolver::class)->begin($save);

    expect($composer->weeks($save->refresh()))->toBe([1]);

    app(WeekResolver::class)->simulateAll($save);
    $pages = $composer->pages($save->refresh());

    expect(collect($pages)->pluck('week')->all())->toBe([2, 1])
        ->and(json_encode($pages[0]))->toContain('Kane');
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
