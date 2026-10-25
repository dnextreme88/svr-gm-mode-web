<?php

use App\Enums\Brand;
use App\Enums\ComplaintLevel;
use App\Enums\ContractLength;
use App\Enums\ContractStatus;
use App\Enums\EmailTemplate;
use App\Enums\InjurySeverity;
use App\Enums\LedgerCategory;
use App\Enums\MitbStatus;
use App\Enums\Morale;
use App\Enums\MoraleComplaintType;
use App\Enums\ReignEndReason;
use App\Models\Contract;
use App\Models\Email;
use App\Models\Injury;
use App\Models\InjuryType;
use App\Models\LedgerEntry;
use App\Models\MoneyInTheBank;
use App\Models\MoraleComplaint;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\TitleReign;
use App\Services\Contracts\ContractService;
use App\Services\Week\WeekResolver;
use Tests\Support\GameBuilder;

/*
| §2 Contracts — the spec's test list.
*/

function weeksLeft(SaveWrestler $wrestler): int
{
    return (int) Contract::where('save_wrestler_id', $wrestler->id)->where('status', ContractStatus::Active)->value('weeks_remaining');
}

function resolveWeeks(Save $save, int $count): void
{
    foreach (range(1, $count) as $ignored) {
        app(WeekResolver::class)->resolve($save->refresh());
    }
}

it('decrements weeks_remaining by exactly 1 per resolved week, including injured and unbooked wrestlers', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $edge = GameBuilder::wrestler($save, 'Edge');

    Injury::create([
        'save_id' => $save->id,
        'save_wrestler_id' => $edge->id,
        'injury_type_id' => InjuryType::where('label', 'Torn ACL')->value('id'),
        'severity' => InjurySeverity::Serious,
        'started_week' => 1,
        'ends_week' => 40,
    ]);

    resolveWeeks($save, 3);

    expect(weeksLeft($edge))->toBe(49)
        ->and(weeksLeft(GameBuilder::wrestler($save, 'Kane')))->toBe(49)
        ->and(weeksLeft(GameBuilder::wrestler($save, 'Batista')))->toBe(49);
});

it('does not decrement twice when resolution is re-run for the same week', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $resolver = app(WeekResolver::class);

    $first = $resolver->resolveWeek($save, 1);
    $again = $resolver->resolveWeek($save->refresh(), 1);

    expect($again->id)->toBe($first->id)
        ->and(weeksLeft(GameBuilder::wrestler($save, 'Edge')))->toBe(51)
        ->and(LedgerEntry::where('save_id', $save->id)->where('category', LedgerCategory::ContractUpkeep)->where('week_number', 1)->count())->toBe(2);
});

it('cannot double-decrement even if the tick itself runs twice in a week', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $contracts = app(ContractService::class);

    $contracts->tick($save, 1);
    $contracts->tick($save, 1);

    expect(weeksLeft(GameBuilder::wrestler($save, 'Edge')))->toBe(51);
});

it('at 0 releases the wrestler to Free Agency, vacates titles with contract_expired and voids briefcases', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Trish Stratus'], smackdown: ['Batista'], length: ContractLength::FiveWeeks);
    $edge = GameBuilder::wrestler($save, 'Edge');
    GameBuilder::giveTitle($save, 'intercontinental', ['Edge']);

    MoneyInTheBank::create([
        'save_id' => $save->id, 'season' => 1, 'brand' => Brand::Raw, 'save_wrestler_id' => $edge->id,
        'won_week' => 1, 'expires_week' => 53, 'status' => MitbStatus::Held,
    ]);

    resolveWeeks($save, 5);

    $edge->refresh();
    $reigns = TitleReign::where('save_id', $save->id)->orderBy('id')->get();

    expect($edge->brand)->toBeNull()
        ->and(Contract::where('save_wrestler_id', $edge->id)->value('status'))->toBe(ContractStatus::Expired)
        ->and($reigns->first()->end_reason)->toBe(ReignEndReason::ContractExpired)
        ->and($reigns->first()->lost_week)->toBe(5)
        ->and($reigns->last()->isVacant())->toBeTrue()
        ->and(MoneyInTheBank::first()->status)->toBe(MitbStatus::Voided);
});

it('resets weeks_remaining and clears the contract extension complaint on renegotiation', function () {
    $save = GameBuilder::inSeason(raw: ['John Cena'], smackdown: ['Batista'], length: ContractLength::ThreeMonths);
    $cena = GameBuilder::wrestler($save, 'John Cena');
    resolveWeeks($save, 3);

    MoraleComplaint::create([
        'save_id' => $save->id, 'save_wrestler_id' => $cena->id, 'type' => MoraleComplaintType::ContractExtension,
        'level' => ComplaintLevel::Unsatisfied, 'opened_week' => 3,
    ]);
    $cena->update(['morale' => Morale::Unsatisfied]);

    app(ContractService::class)->renegotiate($cena->refresh(), ContractLength::SixMonths, 4);

    $cena->refresh();

    expect(weeksLeft($cena))->toBe(24)
        ->and($cena->complaints()->where('type', MoraleComplaintType::ContractExtension)->whereNull('resolved_week')->count())->toBe(0)
        ->and($cena->morale)->not->toBe(Morale::Unsatisfied)
        ->and(Contract::where('save_wrestler_id', $cena->id)->where('status', ContractStatus::Superseded)->count())->toBe(1);
});

it('sends the digest only when someone has 4 or fewer weeks left, listing all of them sorted with champions flagged', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $edge = GameBuilder::wrestler($save, 'Edge');
    $kane = GameBuilder::wrestler($save, 'Kane');
    GameBuilder::giveTitle($save, 'wwe', ['Kane']);

    app(WeekResolver::class)->resolve($save->refresh());
    expect(Email::where('template', EmailTemplate::ExpiringContracts)->count())->toBe(0);

    Contract::where('save_wrestler_id', $edge->id)->update(['weeks_remaining' => 5]);
    Contract::where('save_wrestler_id', $kane->id)->update(['weeks_remaining' => 2]);

    app(WeekResolver::class)->resolve($save->refresh());

    $digests = Email::where('template', EmailTemplate::ExpiringContracts)->get();
    $raw = $digests->firstWhere('brand', Brand::Raw);

    expect($digests)->toHaveCount(1)
        ->and($raw->subject)->toBe('Contracts expiring soon — 2 superstar(s)')
        ->and($raw->sender)->toBe('JR')
        ->and(array_column($raw->payload['rows'], 'name'))->toBe(['Kane', 'Edge'])
        ->and(array_column($raw->payload['rows'], 'weeks_left'))->toBe([1, 4])
        ->and($raw->payload['rows'][0]['champion'])->toBeTrue()
        ->and($raw->payload['rows'][0]['expires_this_week'])->toBeTrue()
        ->and($raw->payload['rows'][1]['champion'])->toBeFalse();
});

it('uses Tazz as the SmackDown sender', function () {
    // The rival GM would renew Batista before the digest goes out.
    config(['gm.ai.roster.renew_at_weeks_left' => 0]);
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    Contract::where('save_wrestler_id', GameBuilder::wrestler($save, 'Batista')->id)->update(['weeks_remaining' => 3]);

    app(WeekResolver::class)->resolve($save->refresh());

    expect(Email::where('template', EmailTemplate::ExpiringContracts)->first()->sender)->toBe('Tazz');
});

it('lists exactly the wrestlers who expired that week in the Contract Expired email', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
    Contract::where('save_wrestler_id', GameBuilder::wrestler($save, 'Edge')->id)->update(['weeks_remaining' => 1]);
    Contract::where('save_wrestler_id', GameBuilder::wrestler($save, 'Kane')->id)->update(['weeks_remaining' => 1]);
    Contract::where('save_wrestler_id', GameBuilder::wrestler($save, 'Carlito')->id)->update(['weeks_remaining' => 2]);

    app(WeekResolver::class)->resolve($save->refresh());

    $expired = Email::where('template', EmailTemplate::ContractExpired)->get();

    expect($expired)->toHaveCount(1)
        ->and(collect($expired->first()->payload['rows'])->pluck('name')->sort()->values()->all())->toBe(['Edge', 'Kane']);
});

it('lets GMs release and sign at any time, with Free Agency signings joining the following week', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $contracts = app(ContractService::class);
    $kane = GameBuilder::wrestler($save, 'Kane');
    $foley = GameBuilder::wrestler($save, 'Mick Foley');

    $contracts->release($kane, 1);
    expect($kane->refresh()->brand)->toBeNull();

    $contracts->signFreeAgent($save, $foley, Brand::Raw, ContractLength::SixMonths, 1);
    expect($foley->refresh()->brand)->toBeNull();

    app(WeekResolver::class)->resolve($save->refresh());

    expect($foley->refresh()->brand)->toBe(Brand::Raw)
        ->and($foley->joined_brand_week)->toBe(2)
        ->and(weeksLeft($foley))->toBe(24);

    app(WeekResolver::class)->resolve($save->refresh());
    expect(weeksLeft($foley))->toBe(23);
});

it('charges weekly upkeep to Financials in the same step', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

    app(WeekResolver::class)->resolve($save->refresh());

    $line = LedgerEntry::where('save_id', $save->id)->where('brand', Brand::Raw)->where('category', LedgerCategory::ContractUpkeep)->first();

    expect($line->amount)->toBe(-16_000)
        ->and($line->week_number)->toBe(1);
});
