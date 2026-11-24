<?php

use App\Enums\Brand;
use App\Enums\MitbStatus;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\PpvKey;
use App\Enums\WonVia;
use App\Exceptions\GameRuleException;
use App\Models\MoneyInTheBank;
use App\Models\NewsEvent;
use App\Models\TitleReign;
use App\Services\Calendar\CalendarService;
use App\Services\Contracts\ContractService;
use App\Services\News\NewsComposer;
use App\Services\News\NewsTemplates;
use App\Services\Titles\MitbService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| §10 Money in the Bank.
*/

it('awards a briefcase that expires after 52 weeks or at the next WrestleMania, whichever is first', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    $early = app(MitbService::class)->award($save, GameBuilder::wrestler($save, 'Edge'), 10);
    $atMania = app(MitbService::class)->award($save, GameBuilder::wrestler($save, 'Batista'), 52);

    expect($early->expires_week)->toBe(52)
        ->and($early->brand)->toBe(Brand::Raw)
        ->and($atMania->expires_week)->toBe(104);
});

it('cashes in for a new reign won via mitb_cash_in', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'John Cena'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
    $briefcase = app(MitbService::class)->award($save, GameBuilder::wrestler($save, 'Edge'), 1);
    $slot = app(MitbService::class)->cashIn(Card::show($save), $briefcase);

    expect($slot->position)->toBe(7);

    FixedOutcomes::queue('Edge');
    app(WeekResolver::class)->resolve($save->refresh());

    $reign = TitleReign::where('save_id', $save->id)->orderByDesc('id')->first();

    expect($reign->holderName())->toBe('Edge')
        ->and($reign->won_via)->toBe(WonVia::MitbCashIn)
        ->and($briefcase->refresh()->status)->toBe(MitbStatus::CashedInWon);
});

it('spends the briefcase on a losing cash-in', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'John Cena'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
    $briefcase = app(MitbService::class)->award($save, GameBuilder::wrestler($save, 'Edge'), 1);
    app(MitbService::class)->cashIn(Card::show($save), $briefcase);

    FixedOutcomes::queue('John Cena');
    app(WeekResolver::class)->resolve($save->refresh());

    expect($briefcase->refresh()->status)->toBe(MitbStatus::CashedInLost)
        ->and(fn () => app(MitbService::class)->cashIn(Card::show($save->refresh()), $briefcase))->toThrow(GameRuleException::class);
});

it('expires on schedule', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $briefcase = MoneyInTheBank::create(['save_id' => $save->id, 'season' => 1, 'brand' => Brand::Raw, 'save_wrestler_id' => GameBuilder::wrestler($save, 'Edge')->id, 'won_week' => 1, 'expires_week' => 1, 'status' => MitbStatus::Held]);

    app(WeekResolver::class)->resolve($save->refresh());

    expect($briefcase->refresh()->status)->toBe(MitbStatus::Expired);
});

it('is voided when the holder leaves the brand', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $briefcase = app(MitbService::class)->award($save, GameBuilder::wrestler($save, 'Edge'), 1);

    app(ContractService::class)->release(GameBuilder::wrestler($save, 'Edge'), 1);

    expect($briefcase->refresh()->status)->toBe(MitbStatus::Voided);
});

it('makes the WWE headline the week after the Royal Rumble while a briefcase is still held', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $calendar = app(CalendarService::class);
    $rumble = collect(range(1, 52))->first(fn (int $w): bool => $calendar->isPpvWeek($w, PpvKey::RoyalRumble));
    MoneyInTheBank::create(['save_id' => $save->id, 'season' => 1, 'brand' => Brand::Raw, 'save_wrestler_id' => GameBuilder::wrestler($save, 'Edge')->id, 'won_week' => 1, 'expires_week' => 104, 'status' => MitbStatus::Held]);
    $service = app(MitbService::class);

    // Not on Royal Rumble week itself, nor any other week.
    $service->expire($save, $rumble);
    $service->expire($save, $rumble - 5);
    expect(NewsEvent::where('type', NewsEventType::MitbExpiring)->count())->toBe(0);

    $service->expire($save, $rumble + 1);
    $event = NewsEvent::where('type', NewsEventType::MitbExpiring)->sole();
    $page = app(NewsComposer::class)->compose($save->refresh(), $rumble + 1);

    expect($event->tier)->toBe(NewsTier::Headline)
        ->and($event->brand)->toBe(NewsBrand::Both)
        ->and($page['headlines']['neutral']['type'])->toBe('mitb_expiring')
        ->and(app(NewsTemplates::class)->summary(NewsEventType::MitbExpiring, $event->payload))->toContain('Edge of Raw');
});
