<?php

use App\Enums\Brand;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\SaveWrestler;
use App\Services\Roster\RosterService;
use App\Services\Titles\TitleService;
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

    // Every draft contract ran out at week 52. The rival judged each one on
    // its final week (house rule, 2026-11-17): some re-signed, some let go,
    // and free agents signed to fill the roster back up. Champions are
    // always kept. Signings made in the last week join next week, so they
    // count too.
    $champions = app(TitleService::class)->currentReigns($save)->flatMap(fn ($r) => $r->holderIds())->all();
    $rivalChampions = SaveWrestler::whereIn('id', $champions)->where('brand', Brand::Smackdown)->pluck('id');

    expect((clone $contracts)->where('status', ContractStatus::Superseded)->count())->toBeGreaterThan(0)
        ->and(Contract::whereIn('save_wrestler_id', $rivalChampions)->where('status', ContractStatus::Active)->count())->toBe($rivalChampions->count())
        ->and(app(RosterService::class)->rosterCount($save, Brand::Smackdown))->toBeGreaterThanOrEqual(22);
});
