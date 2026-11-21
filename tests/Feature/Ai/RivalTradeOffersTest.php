<?php

use App\Enums\Brand;
use App\Enums\EmailTemplate;
use App\Enums\TradeStatus;
use App\Exceptions\GameRuleException;
use App\Models\Email;
use App\Models\Trade;
use App\Services\Ai\AiTradeJudge;
use App\Services\Ai\AiTradeOffers;
use App\Services\Titles\TitleService;
use Tests\Support\GameBuilder;

/*
| Rival GM trade offers (house rule, 2026-11-21).
*/

function rivalOffer(): array
{
    $save = GameBuilder::inSeason(size: 14);
    $week = $save->current_week;
    $trade = app(AiTradeOffers::class)->make($save, $week);

    return [$save, $trade];
}

it('offers a trade that leaves it 1 or 2 points ahead', function () {
    [$save, $trade] = rivalOffer();

    expect($trade)->not->toBeNull();

    $gives = $trade->items->where('direction', 'out')->pluck('save_wrestler_id')->all();
    $gets = $trade->items->where('direction', 'in')->pluck('save_wrestler_id')->all();
    $judge = app(AiTradeJudge::class);
    $ahead = $judge->value($save, $save->rivalBrand(), $gets) - $judge->value($save, $save->rivalBrand(), $gives);

    expect($ahead)->toBeIn([1, 2])
        ->and($trade->proposer_brand)->toBe($save->rivalBrand())
        ->and($trade->status)->toBe(TradeStatus::Proposed)
        ->and(count($gets))->toBeGreaterThanOrEqual(1)
        ->and(count($gets))->toBeLessThanOrEqual(5)
        ->and(count($gives))->toBeGreaterThanOrEqual(1);
});

it('asks for anywhere from 1 to 5 superstars', function () {
    $save = GameBuilder::inSeason(size: 20);
    $offers = app(AiTradeOffers::class);
    $sizes = [];

    // Offers on different weeks draw different sizes.
    foreach ([8, 16, 24, 32, 40, 48] as $week) {
        $trade = $offers->make($save, $week);

        if ($trade !== null) {
            $sizes[] = $trade->items->where('direction', 'in')->count();
            $trade->update(['status' => TradeStatus::Rejected]);
        }
    }

    expect($sizes)->not->toBeEmpty()
        ->and(min($sizes))->toBeGreaterThanOrEqual(1)
        ->and(max($sizes))->toBeLessThanOrEqual(5)
        ->and(count(array_unique($sizes)))->toBeGreaterThan(1);
});

it('emails the offer to the player', function () {
    [$save, $trade] = rivalOffer();

    $email = Email::where('save_id', $save->id)->where('template', EmailTemplate::RivalTradeOffer)->sole();

    expect($email->brand)->toBe($save->player_brand)
        ->and($email->payload['trade_id'])->toBe($trade->id);
});

it('is due every 8 season weeks, once', function () {
    $save = GameBuilder::inSeason(size: 14);
    $offers = app(AiTradeOffers::class);

    expect($offers->due($save, 7))->toBeFalse()
        ->and($offers->due($save, 8))->toBeTrue();

    $offers->make($save, 8);

    expect($offers->due($save, 8))->toBeFalse();
});

it('swaps the superstars when the player accepts', function () {
    [$save, $trade] = rivalOffer();
    $get = $trade->items->firstWhere('direction', 'in')->saveWrestler;

    app(AiTradeOffers::class)->accept($save, $trade);

    expect($get->refresh()->brand)->toBe($save->rivalBrand())
        ->and($trade->refresh()->status)->toBe(TradeStatus::Accepted)
        // No "trade accepted" answer: the rival made the offer.
        ->and(Email::where('save_id', $save->id)->where('template', EmailTemplate::Trade)->count())->toBe(0);
});

it('leaves the rosters alone when the player declines', function () {
    [$save, $trade] = rivalOffer();
    $get = $trade->items->firstWhere('direction', 'in')->saveWrestler;

    app(AiTradeOffers::class)->decline($save, $trade);

    expect($get->refresh()->brand)->toBe($save->player_brand)
        ->and($trade->refresh()->status)->toBe(TradeStatus::Rejected);
});

it('expires an offer nobody answered', function () {
    [$save, $trade] = rivalOffer();
    $offers = app(AiTradeOffers::class);

    $offers->expire($save, (int) $trade->expires_week);
    expect($trade->refresh()->status)->toBe(TradeStatus::Proposed);

    $offers->expire($save, (int) $trade->expires_week + 1);
    expect($trade->refresh()->status)->toBe(TradeStatus::Expired);

    $save->update(['current_week' => (int) $trade->expires_week + 1]);
    expect(fn () => $offers->accept($save->refresh(), $trade->refresh()))->toThrow(GameRuleException::class);
});

it('answers from the email routes', function () {
    [$save, $trade] = rivalOffer();

    $this->post(route('gm.trade.offers.decline', ['save' => $save->id, 'trade' => $trade->id]))->assertRedirect();

    expect($trade->refresh()->status)->toBe(TradeStatus::Rejected);
});

it('never asks for a champion', function () {
    [$save, $trade] = rivalOffer();

    foreach ($trade->items as $item) {
        expect(app(TitleService::class)->isChampion($item->saveWrestler))->toBeFalse();
    }

    expect(Trade::where('save_id', $save->id)->count())->toBe(1)
        ->and(Brand::cases())->toContain($trade->proposer_brand);
});
