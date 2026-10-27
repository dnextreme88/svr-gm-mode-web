<?php

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Enums\DraftMode;
use App\Enums\RosterType;
use App\Exceptions\GameRuleException;
use App\Models\SaveWrestler;
use App\Models\Wrestler;
use App\Services\Contracts\ContractService;
use App\Services\Game\NewGameService;
use App\Services\Roster\RosterService;
use Tests\Support\GameBuilder;

/*
| §1 roster cap: 30 per brand (Superstars, Divas and Legends all count). No
| way of signing someone may take a brand past it.
*/

function fullRosterSave()
{
    GameBuilder::seedCatalog();

    return app(NewGameService::class)->create('Cap', Brand::Raw, GameBuilder::SEED, DraftMode::WweRoster);
}

it('starts a Use WWE roster game with both brands at exactly the cap', function () {
    $save = fullRosterSave();
    $roster = app(RosterService::class);

    expect($roster->rosterCount($save, Brand::Raw))->toBe(30)
        ->and($roster->rosterCount($save, Brand::Smackdown))->toBe(30)
        ->and($roster->hasRoom($save, Brand::Raw))->toBeFalse();
});

it('refuses to sign a drafted wrestler onto a full roster', function () {
    $save = fullRosterSave();

    expect(fn () => app(ContractService::class)->signDrafted($save, GameBuilder::wrestler($save, 'Matt Hardy'), Brand::Raw, 1))
        ->toThrow(GameRuleException::class, 'The Raw roster is full (30).');

    expect(SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Raw)->count())->toBe(30)
        ->and(GameBuilder::wrestler($save, 'Matt Hardy')->brand)->toBeNull();
});

it('refuses a Free Agency signing onto a full roster', function () {
    $save = fullRosterSave();

    expect(fn () => app(ContractService::class)->signFreeAgent($save, GameBuilder::wrestler($save, 'Matt Hardy'), Brand::Raw, ContractLength::OneYear, 1))
        ->toThrow(GameRuleException::class, 'The Raw roster is full (30).');
});

it('counts signings that start next week against the cap', function () {
    $save = fullRosterSave();
    $contracts = app(ContractService::class);
    $contracts->release(GameBuilder::wrestler($save, 'Tatanka'), 1);

    $contracts->signFreeAgent($save, GameBuilder::wrestler($save, 'Matt Hardy'), Brand::Raw, ContractLength::OneYear, 1);

    expect(app(RosterService::class)->rosterCount($save, Brand::Raw))->toBe(30)
        ->and(fn () => $contracts->signFreeAgent($save, GameBuilder::wrestler($save, 'Jeff Hardy'), Brand::Raw, ContractLength::OneYear, 1))
        ->toThrow(GameRuleException::class, 'The Raw roster is full (30).');
});

it('refuses to build a test career over the cap', function () {
    GameBuilder::seedCatalog();
    $names = Wrestler::whereNull('placeholder_brand')->where('roster_type', RosterType::Superstar)->orderBy('id')->limit(31)->pluck('name')->all();

    expect(fn () => GameBuilder::inSeason(raw: $names, smackdown: ['Batista']))
        ->toThrow(GameRuleException::class, 'The Raw roster is full (30).');
});
