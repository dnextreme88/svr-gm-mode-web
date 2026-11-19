<?php

use App\Enums\Brand;
use App\Services\Calendar\CalendarService;
use App\Services\News\BrandTickers;
use App\Services\News\NewsComposer;
use App\Services\Week\ShowScheduler;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| Each brand's News ticker (house rule, 2026-11-17): a line per match on its
| weekly show, in card order. In a week the brand is on a PPV, the PPV is left
| to the headlines and stories and the ticker lists the week before's show.
*/

it('lists every match on the brand’s weekly show, in card order', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito', 'Chris Masters'], smackdown: ['Batista']);
    $show = Card::show($save, Brand::Raw);
    Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kane']]);
    Card::book($save, $show, 2, Card::singles(), [['Carlito'], ['Chris Masters']]);
    FixedOutcomes::queue('Edge');
    FixedOutcomes::queue('Carlito');

    app(WeekResolver::class)->resolve($save->refresh());

    $tickers = app(NewsComposer::class)->compose($save, 1)['tickers'];

    expect($tickers['raw'])->toBe([
        'Edge def. Kane · Normal Matches — Single',
        'Carlito def. Chris Masters · Normal Matches — Single',
    ]);
});

it('lists the week before’s weekly show in a week the brand is on a PPV', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $calendar = app(CalendarService::class);
    $ppvWeek = collect(range(2, 10))->first(fn (int $w): bool => $calendar->ppvForWeek($w, Brand::Raw) !== null);
    $quietWeek = collect(range(2, 10))->first(fn (int $w): bool => $calendar->ppvsForWeek($w)->isEmpty());

    foreach ([$ppvWeek, $quietWeek] as $week) {
        app(ShowScheduler::class)->ensureWeek($save, $week);
    }

    $tickers = app(BrandTickers::class);

    expect($tickers->sourceWeek($save, Brand::Raw, $ppvWeek))->toBe($ppvWeek - 1)
        ->and($tickers->sourceWeek($save, Brand::Raw, $quietWeek))->toBe($quietWeek);
});
