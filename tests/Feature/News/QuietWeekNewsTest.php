<?php

use App\Enums\Brand;
use App\Enums\InjurySeverity;
use App\Enums\Morale;
use App\Enums\NewsEventType;
use App\Models\NewsEvent;
use App\Models\Save;
use App\Models\SaveBrand;
use App\Services\Injuries\InjuryService;
use App\Services\News\NewsComposer;
use App\Services\News\NewsTemplates;
use App\Services\Week\Steps\QuietWeekNews;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| House rule: the News page is never empty. Cards a week leaves empty get
| quiet-week filler, written when the week airs.
*/

function quietWeek(Save $save, int $week = 1): void
{
    app(QuietWeekNews::class)->handle(new WeekContext($save->refresh(), $week, 1));
}

function quiet(NewsEventType $type, ?Brand $brand = null)
{
    return NewsEvent::where('type', $type)->when($brand, fn ($q) => $q->where('brand', $brand->value))->get();
}

it('fills every card of an empty week', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista', 'JBL']);

    quietWeek($save);
    $page = app(NewsComposer::class)->compose($save, 1);

    expect($page['headlines']['neutral']['type'])->toBe('tickets_on_sale')
        ->and($page['headlines']['raw']['type'])->toBe('brand_spotlight')
        ->and($page['headlines']['smackdown']['type'])->toBe('brand_spotlight')
        ->and(collect($page['stories']['raw'])->pluck('type')->sort()->values()->all())->toBe(['injury_report', 'superstar_recap'])
        ->and($page['ticker'])->not->toBeEmpty()
        ->and(collect($page['ticker'])->pluck('type')->unique()->all())->toBe(['locker_room_note']);
});

it('sells tickets for both weekly shows, each with its date', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    quietWeek($save);
    $tickets = quiet(NewsEventType::TicketsOnSale)->sole();

    expect($tickets->payload['story'])->toBe('tickets.shows')
        ->and($tickets->rendered_text)->toBe('Tickets on sale for Raw and Smackdown')
        ->and(app(NewsTemplates::class)->summary(NewsEventType::TicketsOnSale, $tickets->payload))
        ->toBe("Monday Night Raw comes to town on April 11, 2005 and Smackdown on April 15, 2005. Buy your tickets for these shows and don't miss out on the action!");
});

it('keeps the wording of Raw-only ticket news already recorded', function () {
    $payload = ['story' => 'tickets.show', 'variant' => 0, 'event' => 'Monday Night Raw', 'date' => 'Mon Apr 11, 2005'];

    expect(app(NewsTemplates::class)->render(NewsEventType::TicketsOnSale, $payload))->toBe('Tickets on sale now for Monday Night Raw');
});

it('names the PPV when it airs next', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    // Backlash airs in week 4.
    quietWeek($save, 3);
    $tickets = quiet(NewsEventType::TicketsOnSale)->sole();

    expect($tickets->payload['story'])->toBe('tickets.ppv')
        ->and($tickets->payload['event'])->toBe('Backlash');
});

it('sends the top champion on the road, world title first', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'intercontinental', ['Edge']);
    GameBuilder::giveTitle($save, 'wwe', ['Kane']);
    // Awarding the titles is news of its own; this checks an empty week.
    NewsEvent::query()->delete();

    quietWeek($save);
    $spotlight = quiet(NewsEventType::BrandSpotlight, Brand::Raw)->sole();

    expect($spotlight->payload['story'])->toBe('spotlight.champion')
        ->and($spotlight->payload['champion'])->toBe('Kane')
        ->and($spotlight->payload['title'])->toBe('WWE Championship')
        ->and(config('gm.news.tour_cities'))->toContain($spotlight->payload['city']);
});

it('spends a week at WWE HQ without champions, and warns when money is low', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    SaveBrand::where('save_id', $save->id)->where('brand', Brand::Raw)->update(['budget' => 400_000]);

    quietWeek($save);
    $spotlight = quiet(NewsEventType::BrandSpotlight, Brand::Raw)->sole();

    expect($spotlight->payload['story'])->toBe('spotlight.hq')
        ->and($spotlight->payload['budget_ok'])->toBeFalse()
        ->and($spotlight->payload['budget'])->toContain('$400,000');
});

it('recaps a superstar’s last match and reports recent injuries', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);
    FixedOutcomes::queue('Edge');
    app(WeekResolver::class)->resolve($save->refresh());
    app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Kane'), InjurySeverity::Serious, 1, $save->rng('inj.kane'));
    NewsEvent::query()->delete();

    quietWeek($save, 2);
    $recap = quiet(NewsEventType::SuperstarRecap, Brand::Raw)->sole();
    $report = quiet(NewsEventType::InjuryReport, Brand::Raw)->sole();

    // Edge beat Kane, so whichever of them is recapped, the story matches.
    expect($recap->payload['story'])->toBe($recap->payload['wrestler'] === 'Edge' ? 'recap.won' : 'recap.lost')
        ->and($recap->payload['when'])->toBe('last week')
        ->and($report->payload['story'])->toBe('injuries.hurt')
        ->and($report->payload['count'])->toBe(1)
        ->and($report->payload['wrestler'])->toBe('Kane');
});

it('praises a roster with no injuries in 6 months', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    quietWeek($save);

    expect(quiet(NewsEventType::InjuryReport, Brand::Raw)->sole()->payload['story'])->toBe('injuries.clean');
});

it('puts unhappy wrestlers in the ticker', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    GameBuilder::wrestler($save, 'Kane')->update(['morale' => Morale::Upset]);

    quietWeek($save);
    $notes = quiet(NewsEventType::LockerRoomNote, Brand::Raw);

    expect($notes->pluck('payload.story')->all())->toContain('locker.unhappy')
        ->and($notes->firstWhere('payload.story', 'locker.unhappy')->payload['names'])->toBe('Kane');
});

it('leaves a busy week alone', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian', 'Carlito', 'Kurt Angle', 'Shelton Benjamin'], smackdown: ['Batista']);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);
    Card::book($save, Card::show($save), 2, Card::singles(), [['Christian'], ['Carlito']]);
    Card::book($save, Card::show($save), 3, Card::singles(), [['Kurt Angle'], ['Shelton Benjamin']]);
    FixedOutcomes::queue('Edge')->queue('Christian')->queue('Kurt Angle');

    app(WeekResolver::class)->resolve($save->refresh());

    expect(quiet(NewsEventType::BrandSpotlight, Brand::Raw))->toHaveCount(0)
        ->and(quiet(NewsEventType::SuperstarRecap, Brand::Raw))->toHaveCount(0)
        ->and(quiet(NewsEventType::InjuryReport, Brand::Raw))->toHaveCount(0);
});
