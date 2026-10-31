<?php

use App\Enums\Brand;
use App\Enums\LedgerCategory;
use App\Models\LedgerEntry;
use App\Services\Week\WeekResolver;
use Tests\Support\GameBuilder;

/*
| Show income (house rule, 2026-10-28): once the week is done, each brand
| earns a flat $250,000 for its shows.
*/

it('pays each brand $250,000 once the week is done', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    app(WeekResolver::class)->resolve($save->refresh());

    $income = LedgerEntry::where('save_id', $save->id)->where('category', LedgerCategory::ShowIncome)->get();

    expect(config('gm.finances.weekly_show_income'))->toBe(250_000)
        ->and($income)->toHaveCount(2)
        ->and($income->pluck('brand')->map->value->sort()->values()->all())->toBe(['raw', 'smackdown'])
        ->and($income->every(fn (LedgerEntry $e) => $e->amount === 250_000 && $e->week_number === 1 && $e->memo === 'Weekly show income'))->toBeTrue();
});

it('pays once per week, however many weeks air', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    app(WeekResolver::class)->resolve($save->refresh());
    app(WeekResolver::class)->resolve($save->refresh());

    $raw = LedgerEntry::where('save_id', $save->id)->where('brand', Brand::Raw)->where('category', LedgerCategory::ShowIncome);

    expect((clone $raw)->count())->toBe(2)
        ->and((clone $raw)->pluck('week_number')->sort()->values()->all())->toBe([1, 2]);
});

it('pays nothing when the income is set to 0', function () {
    GameBuilder::passiveRival();
    config(['gm.finances.weekly_show_income' => 0]);
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    app(WeekResolver::class)->resolve($save->refresh());

    expect(LedgerEntry::where('save_id', $save->id)->where('category', LedgerCategory::ShowIncome)->exists())->toBeFalse();
});
