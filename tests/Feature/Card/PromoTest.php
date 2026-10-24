<?php

use App\Enums\Brand;
use App\Enums\LedgerCategory;
use App\Enums\PromoTypeKey;
use App\Enums\ShowType;
use App\Exceptions\GameRuleException;
use App\Models\LedgerEntry;
use App\Models\NewsEvent;
use App\Models\PromoSlot;
use App\Models\PromoType;
use App\Models\Rivalry;
use App\Models\Save;
use App\Services\Promos\PromoService;
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

it('pays Advertising’s flat $150,000 next week', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    bookPromo($save, PromoTypeKey::Advertising);

    airPromos($save);

    $income = rawLedger($save, LedgerCategory::PromoIncome)->sole();

    expect($income->amount)->toBe(150_000)
        ->and($income->week_number)->toBe(2);
});

it('scales Merchandise Sponsorship with consistent use', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    foreach (range(1, 3) as $ignored) {
        bookPromo($save, PromoTypeKey::MerchandiseSponsorship);
        airPromos($save);
    }

    expect(rawLedger($save, LedgerCategory::PromoIncome)->sortBy('week_number')->pluck('amount')->all())->toBe([10_000, 20_000, 30_000]);
});

it('backfires Slander automatically if used more than once in 4 weeks, and always makes the neutral headline', function () {
    config(['gm.promos.success_rates.slander_promotion' => 1.0]);
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    $first = bookPromo($save, PromoTypeKey::SlanderPromotion);
    airPromos($save);
    $second = bookPromo($save, PromoTypeKey::SlanderPromotion);
    airPromos($save);

    $news = NewsEvent::where('type', 'slander_result')->orderBy('id')->get();

    expect($first->refresh()->succeeded)->toBeTrue()
        ->and($second->refresh()->backfired)->toBeTrue()
        ->and($second->succeeded)->toBeFalse()
        ->and($news)->toHaveCount(2)
        ->and($news->pluck('tier')->map->value->unique()->all())->toBe(['headline'])
        ->and($news->pluck('brand')->map->value->unique()->all())->toBe(['both'])
        ->and($news[1]->payload['story'])->toBe('slander_promotion.backfires');
});

it('reports Interference results as a neutral headline whether it succeeds or fails', function () {
    config(['gm.promos.success_rates.interference_promotion' => 0.0]);
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    bookPromo($save, PromoTypeKey::InterferencePromotion, 1, 'Edge');

    airPromos($save);

    $event = NewsEvent::where('type', 'interference_promo_result')->sole();

    expect($event->payload['story'])->toBe('interference_promotion.fails')
        ->and($event->rendered_text)->toContain('Edge');
});

it('starts and continues rivalries', function () {
    config(['gm.promos.success_rates.start_rivalry' => 1.0, 'gm.promos.success_rates.continue_rivalry' => 1.0]);
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

    bookPromo($save, PromoTypeKey::StartRivalry, 1, 'Edge', 'Kane');
    airPromos($save);
    $heat = Rivalry::sole()->heat;

    bookPromo($save, PromoTypeKey::ContinueRivalry, 1, 'Edge', 'Kane');
    airPromos($save);

    expect(Rivalry::sole()->heat)->toBeGreaterThan($heat - (int) config('gm.rivalries.idle_decay'));
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
