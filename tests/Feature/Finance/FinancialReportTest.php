<?php

use App\Enums\Brand;
use App\Enums\LedgerCategory;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\PromoType;
use App\Services\Finance\FinancialReport;
use App\Services\Promos\PromoService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
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
        ->and(collect($week['groups'])->flatMap(fn (array $g): array => $g['lines'])->pluck('category')->all())->toBe(['signing_fee', 'show_income']);
});

it('groups a week by night, then week-wide, and folds promos into one line (house rule, 2026-11-15)', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $show = Card::show($save);
    Card::book($save, $show, 1, Card::type('ladder', 'Single'), [['Edge'], ['Kane']]);
    app(PromoService::class)->book($show, 1, PromoType::where('key', 'advertising')->sole());

    app(WeekResolver::class)->resolve($save->refresh());

    $week = app(FinancialReport::class)->forWeek($save, Brand::Raw, 1);
    $night = collect($week['groups'])->firstWhere('name', $show->name);
    $weekWide = collect($week['groups'])->firstWhere('key', 'week');

    expect($week['title'])->toBe($show->name)
        ->and(collect($night['lines'])->pluck('group')->all())->toContain('match_cost', 'promos', 'show_production', 'show_income')
        ->and(collect($night['lines'])->firstWhere('group', 'promos')['label'])->toBe('Promos')
        // House rule (2026-11-17): no weekly salaries; contracts were paid when signed.
        ->and(collect($weekWide['lines'] ?? [])->pluck('category')->all())->not->toContain('contract_upkeep')
        ->and(collect($week['totals'])->firstWhere('group', 'promos')['amount'])->toBe(200_000)
        ->and($week['net'])->toBe(collect($week['groups'])->sum('total'));
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
