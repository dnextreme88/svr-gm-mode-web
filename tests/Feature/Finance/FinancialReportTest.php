<?php

use App\Enums\Brand;
use App\Enums\LedgerCategory;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Services\Finance\FinancialReport;
use Tests\Support\GameBuilder;

/*
| Financials: the starting budget is the opening balance, not income.
*/

function ledger($save, LedgerCategory $category, int $amount, int $week = 1): void
{
    LedgerEntry::create([
        'save_id' => $save->id,
        'brand' => Brand::Raw,
        'season' => 1,
        'week_number' => $week,
        'category' => $category,
        'amount' => $amount,
        'memo' => $category->label(),
    ]);
}

it('keeps the starting budget out of the week’s income, expenses and net', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    LedgerEntry::where('save_id', $save->id)->delete();
    ledger($save, LedgerCategory::StartingBudget, 15_000_000);
    ledger($save, LedgerCategory::SigningFee, -100_000);
    ledger($save, LedgerCategory::ShowIncome, 250_000);

    $week = app(FinancialReport::class)->forWeek($save, Brand::Raw, 1);

    expect($week['starting_budget'])->toBe(15_000_000)
        ->and($week['income'])->toBe(250_000)
        ->and($week['expenses'])->toBe(-100_000)
        ->and($week['net'])->toBe(150_000)
        ->and(collect($week['lines'])->pluck('category')->all())->toBe(['signing_fee', 'show_income']);
});

it('still counts the starting budget in the running balance', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    LedgerEntry::where('save_id', $save->id)->delete();
    ledger($save, LedgerCategory::StartingBudget, 15_000_000);
    ledger($save, LedgerCategory::SigningFee, -100_000);
    ledger($save, LedgerCategory::ContractUpkeep, -8_000, week: 2);

    $history = app(FinancialReport::class)->history($save, Brand::Raw);

    expect($history[0]['income'])->toBe(0)
        ->and($history[0]['net'])->toBe(-100_000)
        ->and($history[0]['balance'])->toBe(14_900_000)
        ->and($history[1]['balance'])->toBe(14_892_000);
});

it('sums the weekly upkeep of the brand’s active contracts', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

    $expected = (int) Contract::where('save_id', $save->id)->where('brand', Brand::Raw)->where('status', 'active')->sum('weekly_cost');

    expect(app(FinancialReport::class)->weeklyUpkeep($save, Brand::Raw))->toBe($expected)
        ->and($expected)->toBeGreaterThan(0);
});
