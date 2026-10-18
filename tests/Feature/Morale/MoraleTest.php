<?php

use App\Enums\Brand;
use App\Enums\ComplaintLevel;
use App\Enums\ContractLength;
use App\Enums\EmailTemplate;
use App\Enums\InjurySeverity;
use App\Enums\Morale;
use App\Enums\MoraleComplaintType;
use App\Models\Championship;
use App\Models\Email;
use App\Models\MoraleComplaint;
use App\Models\SaveWrestler;
use App\Services\Contracts\ContractService;
use App\Services\Injuries\InjuryService;
use App\Services\Morale\MoraleService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| §15 Morale.
*/

beforeEach(function () {
    config(['gm.defection.chance.unsatisfied' => 0.0, 'gm.defection.chance.upset' => 0.0]);
});

function runWeek($save): void
{
    app(WeekResolver::class)->resolve($save->refresh());
}

function complaint(SaveWrestler $w, MoraleComplaintType $type): ?MoraleComplaint
{
    return MoraleComplaint::where('save_wrestler_id', $w->id)->where('type', $type)->whereNull('resolved_week')->first();
}

it('maps each complaint value to its level using the config thresholds', function (MoraleComplaintType $type, int $value, ?ComplaintLevel $level) {
    expect(app(MoraleService::class)->levelFor($type, $value))->toBe($level);
})->with([
    [MoraleComplaintType::LosingStreak, 2, null],
    [MoraleComplaintType::LosingStreak, 3, ComplaintLevel::Content],
    [MoraleComplaintType::LosingStreak, 5, ComplaintLevel::Unsatisfied],
    [MoraleComplaintType::LosingStreak, 7, ComplaintLevel::Upset],
    [MoraleComplaintType::ContractExtension, 10, ComplaintLevel::Content],
    [MoraleComplaintType::ContractExtension, 15, ComplaintLevel::Unsatisfied],
    [MoraleComplaintType::ContractExtension, 18, ComplaintLevel::Upset],
    [MoraleComplaintType::TitleMatch, 4, ComplaintLevel::Content],
    [MoraleComplaintType::TitleMatch, 8, ComplaintLevel::Unsatisfied],
    [MoraleComplaintType::TitleMatch, 12, ComplaintLevel::Upset],
    [MoraleComplaintType::Inactivity, 2, ComplaintLevel::Content],
    [MoraleComplaintType::Inactivity, 4, ComplaintLevel::Unsatisfied],
    [MoraleComplaintType::Inactivity, 8, ComplaintLevel::Upset],
]);

it('is Happy with no complaints and otherwise takes the worst complaint level', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $edge = GameBuilder::wrestler($save, 'Edge');
    $edge->update(['loss_streak' => 5, 'weeks_unused' => 7]);

    runWeek($save);

    $edge->refresh();

    expect(complaint($edge, MoraleComplaintType::LosingStreak)?->level)->toBe(ComplaintLevel::Unsatisfied)
        ->and(complaint($edge, MoraleComplaintType::Inactivity)?->level)->toBe(ComplaintLevel::Upset)
        ->and($edge->morale)->toBe(Morale::Upset);
});

it('sends an email for each new complaint level', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    GameBuilder::wrestler($save, 'Edge')->update(['weeks_unused' => 1]);

    runWeek($save);
    runWeek($save);
    runWeek($save);

    $subjects = Email::where('template', EmailTemplate::MoraleComplaint)->where('brand', 'raw')->pluck('subject')->all();

    expect($subjects)->toContain('Edge wants to be on the card')
        ->and(MoraleComplaint::where('type', MoraleComplaintType::Inactivity)->where('save_wrestler_id', GameBuilder::wrestler($save, 'Edge')->id)->sole()->level)->toBe(ComplaintLevel::Unsatisfied);
});

it('clears the losing streak complaint on a win', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $edge = GameBuilder::wrestler($save, 'Edge');
    $edge->update(['loss_streak' => 3]);
    runWeek($save);
    expect(complaint($edge, MoraleComplaintType::LosingStreak))->not->toBeNull();

    Card::book($save->refresh(), Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);
    FixedOutcomes::queue('Edge');
    runWeek($save);

    expect(complaint($edge, MoraleComplaintType::LosingStreak))->toBeNull();
});

it('keeps the contract extension complaint after the streak ends until the contract is extended', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $edge = GameBuilder::wrestler($save, 'Edge');
    $edge->update(['win_streak' => 10]);
    runWeek($save);

    Card::book($save->refresh(), Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);
    FixedOutcomes::queue('Kane');
    runWeek($save);

    expect(complaint($edge, MoraleComplaintType::ContractExtension))->not->toBeNull();

    app(ContractService::class)->renegotiate($edge->refresh(), ContractLength::OneYear, 3);

    expect(complaint($edge, MoraleComplaintType::ContractExtension))->toBeNull();
});

it('clears the title match complaint when booked in a title match, win or lose', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'John Cena'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
    $edge = GameBuilder::wrestler($save, 'Edge');
    $edge->update(['weeks_since_title_match' => 7]);
    runWeek($save);
    expect(complaint($edge, MoraleComplaintType::TitleMatch)?->level)->toBe(ComplaintLevel::Unsatisfied);

    Card::book($save->refresh(), Card::show($save), 1, Card::singles(), [['John Cena'], ['Edge']], [], Championship::where('key', 'wwe')->value('id'));
    FixedOutcomes::queue('John Cena');
    runWeek($save);

    expect(complaint($edge, MoraleComplaintType::TitleMatch))->toBeNull()
        ->and($edge->refresh()->weeks_since_title_match)->toBe(0);
});

it('only applies the title match demand when the brand has a title the wrestler can hold', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista', 'Melina']);
    $melina = GameBuilder::wrestler($save, 'Melina');
    $melina->update(['weeks_since_title_match' => 20]);

    runWeek($save);

    expect(complaint($melina, MoraleComplaintType::TitleMatch))->toBeNull();
});

it('clears inactivity when booked, including as a manager', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Ric Flair'], smackdown: ['Batista']);
    $flair = GameBuilder::wrestler($save, 'Ric Flair');
    $flair->update(['weeks_unused' => 3]);
    runWeek($save);
    expect(complaint($flair, MoraleComplaintType::Inactivity))->not->toBeNull();

    Card::book($save->refresh(), Card::show($save), 1, Card::type('single', 'No Manager vs. w/Manager'), [['Edge'], ['Kane']], [1 => 'Ric Flair']);
    runWeek($save);

    expect(complaint($flair, MoraleComplaintType::Inactivity))->toBeNull()
        ->and($flair->refresh()->weeks_unused)->toBe(0)
        ->and($flair->fatigue)->toBe(0);
});

it('does not count serious injury or sit-out weeks toward the inactivity and title clocks', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $edge = GameBuilder::wrestler($save, 'Edge');
    $kane = GameBuilder::wrestler($save, 'Kane');
    app(InjuryService::class)->injure($edge, InjurySeverity::Serious, 1, $save->rng('t'));
    $kane->update(['unbookable_until_week' => 4]);

    runWeek($save);
    runWeek($save);

    expect($edge->refresh()->weeks_unused)->toBe(0)
        ->and($edge->weeks_since_title_match)->toBe(0)
        ->and($kane->refresh()->weeks_unused)->toBe(0);
});

it('starts new signings Happy', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $foley = GameBuilder::wrestler($save, 'Mick Foley');
    $foley->update(['morale' => Morale::Upset]);

    app(ContractService::class)->signFreeAgent($save, $foley, Brand::Raw, ContractLength::OneYear, 1);
    runWeek($save);

    expect($foley->refresh()->morale)->toBe(Morale::Happy);
});
