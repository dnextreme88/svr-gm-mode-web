<?php

use App\Enums\Brand;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Services\Roster\RosterService;
use App\Services\Week\WeekResolver;
use App\Services\Week\WeekSimulator;
use Tests\Support\GameBuilder;

it('keeps the rival roster together through the end of the season', function () {
    $save = GameBuilder::inSeason(size: 26);

    $result = app(WeekSimulator::class)->simulateTo($save, 52);
    expect($result['stopped'])->toBeNull();

    app(WeekSimulator::class)->bookWeek($save->refresh(), 52);
    app(WeekResolver::class)->resolveWeek($save->refresh(), 52);

    $contracts = Contract::where('save_id', $save->id)->where('brand', Brand::Smackdown);

    // Every draft contract ran out at week 52 before the rival renewed them.
    // Defections still come and go, and signings made in the last week join
    // next week, so they count too.
    expect((clone $contracts)->where('status', ContractStatus::Expired)->count())->toBe(0)
        ->and((clone $contracts)->where('status', ContractStatus::Superseded)->count())->toBeGreaterThanOrEqual(20)
        ->and((clone $contracts)->where('status', ContractStatus::Active)->min('weeks_remaining'))->toBeGreaterThan(4)
        ->and(app(RosterService::class)->rosterCount($save, Brand::Smackdown))->toBeGreaterThanOrEqual(22);
});
