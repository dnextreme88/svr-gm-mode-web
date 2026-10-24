<?php

use App\Enums\Brand;
use App\Enums\MatchMethod;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\ShowType;
use App\Models\Championship;
use App\Models\NewsEvent;
use App\Models\Save;
use App\Services\News\NewsComposer;
use App\Services\News\NewsRecorder;
use App\Services\News\NewsTemplates;
use App\Services\Week\ShowScheduler;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| House rule (2026-10-25): in a PPV week the WWE headline is the PPV's main
| event, ahead of everything else (Slander and Interference included).
*/

const PPV_WEEK = 4; // Backlash (Raw)

function backlash(): Save
{
    $save = GameBuilder::inSeason(raw: ['Edge', 'John Cena', 'Kane', 'Christian'], smackdown: ['Batista', 'JBL']);
    $save->update(['current_week' => PPV_WEEK]);
    app(ShowScheduler::class)->ensureWeek($save, PPV_WEEK);

    return $save->refresh();
}

function ppvHeadline(Save $save): array
{
    return app(NewsComposer::class)->compose($save, PPV_WEEK)['headlines']['neutral'];
}

it('makes the PPV main event the WWE headline, with the title and the gimmick, ahead of a Slander result', function () {
    $save = backlash();
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    $ppv = Card::show($save, Brand::Raw, ShowType::Ppv, PPV_WEEK);
    Card::book($save, $ppv, 1, Card::singles(), [['Kane'], ['Christian']]);
    Card::book($save, $ppv, 2, Card::type('ladder', 'Single'), [['Edge'], ['John Cena']], [], Championship::where('key', 'wwe')->value('id'));
    FixedOutcomes::queue('Kane')->queue('John Cena', MatchMethod::MatchSpecific);
    app(NewsRecorder::class)->record($save, NewsEventType::SlanderResult, NewsTier::Headline, NewsBrand::Both, ['brand' => 'Smackdown', 'rival_brand' => 'Raw', 'outcome' => 'fails'], [], 100, PPV_WEEK);

    app(WeekResolver::class)->resolve($save);

    $headline = ppvHeadline($save);

    expect($headline['type'])->toBe('ppv_results')
        ->and($headline['text'])->toBe('Backlash main event: John Cena defeats Edge in a Ladder match to win the WWE Championship!')
        ->and($headline['summary'])->toBe('Backlash ended with a new champion: John Cena beat Edge in a Ladder match via briefcase retrieval and walked out with the WWE Championship.');
});

it('words a retained title and a plain non-title main event', function (bool $title, string $text) {
    $save = backlash();
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    $ppv = Card::show($save, Brand::Raw, ShowType::Ppv, PPV_WEEK);
    Card::book($save, $ppv, 1, Card::singles(), [['Edge'], ['John Cena']], [], $title ? Championship::where('key', 'wwe')->value('id') : null);
    FixedOutcomes::queue('Edge');

    app(WeekResolver::class)->resolve($save);

    expect(ppvHeadline($save)['text'])->toBe($text);
})->with([
    'title retained' => [true, 'Backlash main event: Edge defeats John Cena to retain the WWE Championship!'],
    'non-title' => [false, 'Backlash main event: Edge defeats John Cena via pinfall!'],
]);

it('keeps the title with the champion when the challenger wins the PPV main event by DQ', function () {
    $save = backlash();
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    $ppv = Card::show($save, Brand::Raw, ShowType::Ppv, PPV_WEEK);
    Card::book($save, $ppv, 1, Card::singles(), [['Edge'], ['John Cena']], [], Championship::where('key', 'wwe')->value('id'));
    FixedOutcomes::queue('John Cena', MatchMethod::Dq);

    app(WeekResolver::class)->resolve($save);

    expect(ppvHeadline($save)['text'])->toBe('Backlash main event: John Cena defeats Edge by disqualification, but the WWE Championship stays put!');
});

it('puts every PPV’s buzz on the WWE card, brand PPVs included', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $save->update(['current_week' => PPV_WEEK - 1]);

    app(WeekResolver::class)->resolve($save->refresh());

    $buzz = NewsEvent::where('type', NewsEventType::PpvUpcoming)->where('week_number', PPV_WEEK - 1)->sole();

    expect($buzz->brand)->toBe(NewsBrand::Both)
        ->and($buzz->tier)->toBe(NewsTier::Headline)
        ->and(app(NewsTemplates::class)->render(NewsEventType::PpvUpcoming, $buzz->payload))->toBe('Backlash is 1 week away.');
});
