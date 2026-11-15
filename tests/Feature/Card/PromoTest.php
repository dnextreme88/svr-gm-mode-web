<?php

use App\Enums\Brand;
use App\Enums\EmailTemplate;
use App\Enums\LedgerCategory;
use App\Enums\NewsEventType;
use App\Enums\PromoAlignment;
use App\Enums\PromoTypeKey;
use App\Enums\ShowType;
use App\Exceptions\GameRuleException;
use App\Models\Email;
use App\Models\LedgerEntry;
use App\Models\NewsEvent;
use App\Models\PromoSlot;
use App\Models\PromoType;
use App\Models\Rivalry;
use App\Models\Save;
use App\Services\Promos\PromoService;
use App\Services\Rivalries\RivalryService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| §5 Promo System.
*/

function promoType(PromoTypeKey $key): PromoType
{
    return PromoType::where('key', $key)->firstOrFail();
}

function bookPromo(Save $save, PromoTypeKey $key, int $position = 1, ?string $a = null, ?string $b = null): PromoSlot
{
    return app(PromoService::class)->book(
        Card::show($save->refresh()),
        $position,
        promoType($key),
        $a === null ? null : GameBuilder::wrestler($save, $a)->id,
        $b === null ? null : GameBuilder::wrestler($save, $b)->id,
    );
}

function airPromos(Save $save): void
{
    app(WeekResolver::class)->resolve($save->refresh());
}

function rawLedger(Save $save, LedgerCategory $category)
{
    return LedgerEntry::where('save_id', $save->id)->where('brand', Brand::Raw)->where('category', $category)->get();
}

it('rolls each promo against its configured success rate', function (float $rate, bool $succeeds) {
    config(['gm.promos.success_rates.main_event_hype' => $rate]);
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $slot = bookPromo($save, PromoTypeKey::MainEventHype);

    airPromos($save);

    expect($slot->refresh()->succeeded)->toBe($succeeds);
})->with([[1.0, true], [0.0, false]]);

it('still charges the cost when a promo fails', function () {
    config(['gm.promos.success_rates.main_event_hype' => 0.0]);
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    bookPromo($save, PromoTypeKey::MainEventHype);

    airPromos($save);

    expect(rawLedger($save, LedgerCategory::PromoCost)->sum('amount'))->toBe(-50_000);
});

it('fixes the rival-targeting promos at 50%', function () {
    GameBuilder::seedCatalog();

    expect(promoType(PromoTypeKey::InterferencePromotion)->successRate())->toBe(0.5)
        ->and(promoType(PromoTypeKey::SlanderPromotion)->successRate())->toBe(0.5);
});

it('pays Advertising a flat $200,000 the week it airs (house rule, 2026-11-15)', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $slot = bookPromo($save, PromoTypeKey::Advertising);

    airPromos($save);

    $income = rawLedger($save, LedgerCategory::PromoIncome)->sole();

    expect($income->amount)->toBe(200_000)
        ->and($income->week_number)->toBe(1)
        ->and($income->show_id)->toBe($slot->show_id)
        ->and($slot->refresh()->succeeded)->toBeTrue();
});

it('gambles Merchandise Sponsorship: a drawn payout, paid only on a success', function (float $chance, array $paid) {
    config(['gm.promos.merchandise_chances' => [$chance], 'gm.promos.merchandise_payouts' => [50_000]]);
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    $slot = bookPromo($save, PromoTypeKey::MerchandiseSponsorship);
    airPromos($save);

    expect(rawLedger($save, LedgerCategory::PromoIncome)->pluck('amount')->all())->toBe($paid)
        ->and(rawLedger($save, LedgerCategory::PromoCost))->toHaveCount(0)
        ->and($slot->refresh()->cost_or_profit)->toBe(50_000);
})->with([
    'lands' => [1.0, [50_000]],
    'falls through' => [0.0, []],
]);

it('draws Merchandise’s chance and payout separately, each with equal odds', function () {
    $chances = [];
    $payouts = [];

    foreach (range(1, 80) as $i) {
        $deal = app(PromoService::class)->merchandiseTier(GameBuilder::newSave()->rng("merch.{$i}"));
        $chances[(string) $deal['chance']] = true;
        $payouts[$deal['amount']] = true;
    }

    expect(config('gm.promos.merchandise_payouts'))->toBe([10_000, 25_000, 50_000, 100_000])
        ->and(config('gm.promos.merchandise_chances'))->toBe([0.80, 0.70, 0.50, 0.25])
        ->and(array_keys($payouts))->toEqualCanonicalizing([10_000, 25_000, 50_000, 100_000])
        ->and(array_keys($chances))->toEqualCanonicalizing(['0.8', '0.7', '0.5', '0.25']);
});

it('pays a successful Interference $100,000 and bills a failed one $25,000 more', function (float $chance, int $result) {
    config(['gm.promos.success_rates.interference_promotion' => $chance]);
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    bookPromo($save, PromoTypeKey::InterferencePromotion, 1, 'Edge');

    airPromos($save);

    expect(rawLedger($save, LedgerCategory::InterferenceResult)->sole()->amount)->toBe($result)
        ->and(rawLedger($save, LedgerCategory::PromoCost)->sole()->amount)->toBe(-50_000);
})->with([
    'works' => [1.0, 100_000],
    'fails' => [0.0, -25_000],
]);

it('makes every promo a sure thing except Interview Space, Interference, Slander and Call-out', function () {
    $rates = config('gm.promos.success_rates');

    expect(array_filter($rates, fn (float $rate): bool => $rate < 1.0))->toBe([
        'merchandise_sponsorship' => 0.80,
        'interference_promotion' => 0.50,
        'slander_promotion' => 0.50,
        'interview_space' => 0.90,
        'call_out' => 0.80,
    ]);
});

it('backfires Slander and Interference on their 4th use within 5 weeks, and always makes the neutral headline', function (PromoTypeKey $key, ?string $target, string $event) {
    GameBuilder::passiveRival();
    config(['gm.promos.success_rates.'.$key->value => 1.0]);
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian'], smackdown: ['Batista']);

    // Weeks 1-3: one a week, all roll as usual.
    $slots = [];

    foreach (range(1, 3) as $week) {
        $slots[] = bookPromo($save, $key, 1, $target);
        airPromos($save);
    }

    // Week 4: the 4th use in 5 weeks backfires.
    $slots[] = bookPromo($save, $key, 1, $target);
    airPromos($save);

    $news = NewsEvent::where('type', $event)->orderBy('id')->get();

    expect(collect($slots)->map(fn ($s) => $s->refresh()->succeeded)->all())->toBe([true, true, true, false])
        ->and($slots[3]->backfired)->toBeTrue()
        ->and($news)->toHaveCount(4)
        ->and($news->pluck('tier')->map->value->unique()->all())->toBe(['headline'])
        ->and($news->pluck('brand')->map->value->unique()->all())->toBe(['both'])
        ->and($news[3]->payload['story'])->toBe("{$key->value}.backfires");
})->with([
    'slander' => [PromoTypeKey::SlanderPromotion, null, 'slander_result'],
    'interference' => [PromoTypeKey::InterferencePromotion, 'Edge', 'interference_promo_result'],
]);

it('counts two of the same promo on one card as two uses', function () {
    GameBuilder::passiveRival();
    config(['gm.promos.success_rates.slander_promotion' => 1.0]);
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    $week1 = [bookPromo($save, PromoTypeKey::SlanderPromotion, 1), bookPromo($save, PromoTypeKey::SlanderPromotion, 2)];
    airPromos($save);
    $week2 = [bookPromo($save, PromoTypeKey::SlanderPromotion, 1), bookPromo($save, PromoTypeKey::SlanderPromotion, 2)];
    airPromos($save);

    // Promo 2 airs first (the earlier free spot), so promo 1 is week 2's
    // second Slander: the 4th use.
    $backfired = collect([...$week1, ...$week2])->map(fn ($s) => $s->refresh()->backfired)->all();

    expect($backfired)->toBe([false, false, true, false]);
});

it('forgets uses older than 5 weeks, and counts each promo on its own', function () {
    GameBuilder::passiveRival();
    config(['gm.promos.success_rates.slander_promotion' => 1.0, 'gm.promos.success_rates.interference_promotion' => 1.0]);
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

    // Weeks 1-3: a Slander each week, plus Interferences that don't count against it.
    foreach (range(1, 3) as $week) {
        bookPromo($save, PromoTypeKey::SlanderPromotion, 1);
        bookPromo($save, PromoTypeKey::InterferencePromotion, 2, 'Edge');
        airPromos($save);
    }

    // Weeks 4 and 5 off; week 6's window (weeks 2-6) holds only 2 Slanders.
    airPromos($save);
    airPromos($save);
    $late = bookPromo($save, PromoTypeKey::SlanderPromotion, 1);
    airPromos($save);

    expect($late->refresh()->backfired)->toBeFalse()
        ->and($late->succeeded)->toBeTrue();
});

it('reports Interference results as a neutral headline whether it succeeds or fails', function () {
    GameBuilder::passiveRival();
    config(['gm.promos.success_rates.interference_promotion' => 0.0]);
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    bookPromo($save, PromoTypeKey::InterferencePromotion, 1, 'Edge');

    airPromos($save);

    $event = NewsEvent::where('type', 'interference_promo_result')->sole();

    expect($event->payload['story'])->toBe('interference_promotion.fails')
        ->and($event->rendered_text)->toContain('Edge');
});

it('starts and continues rivalries', function () {
    GameBuilder::passiveRival();
    config(['gm.promos.success_rates.start_rivalry' => 1.0, 'gm.promos.success_rates.continue_rivalry' => 1.0]);
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

    bookPromo($save, PromoTypeKey::StartRivalry, 1, 'Edge', 'Kane');
    airPromos($save);
    $heat = Rivalry::sole()->heat;

    bookPromo($save, PromoTypeKey::ContinueRivalry, 1, 'Edge', 'Kane');
    airPromos($save);

    // House rule (2026-11-12): Continue Rivalry holds the heat, no more, no less.
    expect(Rivalry::sole()->heat)->toBe($heat)->and(Rivalry::sole()->active)->toBeTrue();
});

it('only allows New Superstar on a freshly signed or traded-in wrestler', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $save->update(['current_week' => 10]);
    GameBuilder::wrestler($save, 'Edge')->update(['joined_brand_week' => 1]);

    bookPromo($save, PromoTypeKey::NewSuperstar, 1, 'Edge');
})->throws(GameRuleException::class, 'freshly signed');

it('has two promo slots on weekly shows and none on PPVs', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $ppv = Card::show($save, Brand::Raw, ShowType::Ppv, 4);

    expect(Card::show($save)->promoSlotCount())->toBe(2)
        ->and(fn () => app(PromoService::class)->book($ppv, 1, promoType(PromoTypeKey::Advertising)))->toThrow(GameRuleException::class, 'PPV');
});

describe('Call-out (house rule, 2026-11-15)', function () {
    function promoCallOutSave(): Save
    {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian'], smackdown: ['Batista']);
        app(RivalryService::class)->start($save, GameBuilder::wrestler($save, 'Edge')->id, GameBuilder::wrestler($save, 'Kane')->id, 1);

        return $save->refresh();
    }

    it('needs an active rivalry between the two', function () {
        $save = promoCallOutSave();

        app(PromoService::class)->book(Card::show($save), 1, promoType(PromoTypeKey::CallOut), GameBuilder::wrestler($save, 'Edge')->id, GameBuilder::wrestler($save, 'Christian')->id);
    })->throws(GameRuleException::class);

    it('makes the news and emails the GM from the superstar who talked', function (PromoAlignment $alignment, NewsEventType $event, EmailTemplate $template) {
        config(['gm.promos.success_rates.call_out' => 1.0]);
        $save = promoCallOutSave();
        $slot = app(PromoService::class)->book(Card::show($save), 1, promoType(PromoTypeKey::CallOut), GameBuilder::wrestler($save, 'Edge')->id, GameBuilder::wrestler($save, 'Kane')->id, alignment: $alignment);

        airPromos($save);

        $email = Email::where('save_id', $save->id)->where('template', $template)->sole();

        expect($slot->refresh()->alignment)->toBe($alignment)
            ->and($slot->succeeded)->toBeTrue()
            ->and(rawLedger($save, LedgerCategory::PromoCost)->sole()->amount)->toBe(-10_000)
            ->and(NewsEvent::where('type', $event)->sole()->payload['alignment'])->toBe($alignment->value)
            ->and($email->sender)->toBe('Edge')
            ->and($email->subject)->toBe('I called out Kane');
    })->with([
        'face' => [PromoAlignment::Face, NewsEventType::CallOutFace, EmailTemplate::CallOutFace],
        'heel' => [PromoAlignment::Heel, NewsEventType::CallOutHeel, EmailTemplate::CallOutHeel],
    ]);

    it('adds 10 heat and keeps the rivalry from going quiet that week', function () {
        config(['gm.promos.success_rates.call_out' => 1.0]);
        $save = promoCallOutSave();
        Rivalry::query()->update(['heat' => 30]);
        app(PromoService::class)->book(Card::show($save), 1, promoType(PromoTypeKey::CallOut), GameBuilder::wrestler($save, 'Kane')->id, GameBuilder::wrestler($save, 'Edge')->id, alignment: PromoAlignment::Heel);

        airPromos($save);

        expect(Rivalry::sole()->heat)->toBe(40);
    });
});

it('keeps two rival Divas’ rivalry going with a Bikini Contest (house rule, 2026-11-15)', function () {
    $save = GameBuilder::inSeason(raw: ['Trish Stratus', 'Victoria', 'Edge'], smackdown: ['Batista']);
    app(RivalryService::class)->start($save, GameBuilder::wrestler($save, 'Trish Stratus')->id, GameBuilder::wrestler($save, 'Victoria')->id, 1);
    Rivalry::query()->update(['heat' => 30]);
    bookPromo($save->refresh(), PromoTypeKey::DivaBikiniContest, 1, 'Trish Stratus', 'Victoria');

    airPromos($save);

    expect(Rivalry::sole()->heat)->toBe(30);
});
