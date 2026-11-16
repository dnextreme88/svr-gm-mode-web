<?php

use App\Enums\Brand;
use App\Enums\InjurySeverity;
use App\Enums\LedgerCategory;
use App\Models\LedgerEntry;
use App\Models\Show;
use App\Services\Injuries\InjuryService;
use App\Services\Week\Steps\PayShowIncome;
use App\Services\Week\Steps\TrackDeficit;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| Weekly money (house rule, 2026-11-15): each night's gate is its brands'
| show income (the flat $250,000 is gone), the week's bigger crowd earns a
| $100,000 bonus, every night costs production, and injured superstars cost
| medical rehab every week.
*/

function weekLedgerOf(int $saveId, LedgerCategory $category)
{
    return LedgerEntry::where('save_id', $saveId)->where('category', $category)->get();
}

it('pays each night’s gate as show income, and no flat amount', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista', 'JBL']);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);

    app(WeekResolver::class)->resolve($save->refresh());

    $raw = Show::where('save_id', $save->id)->where('week_number', 1)->where('brand', Brand::Raw)->sole();
    $gate = weekLedgerOf($save->id, LedgerCategory::ShowIncome)->firstWhere('brand', Brand::Raw);

    expect(config('gm.finances.weekly_show_income'))->toBeNull()
        ->and($gate->amount)->toBe($raw->attendance_breakdown['gate'])
        ->and($gate->show_id)->toBe($raw->id)
        ->and($gate->memo)->toStartWith("Gate: {$raw->name} (");
});

it('gives the brand with the bigger crowd that week a $100,000 bonus', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista', 'JBL']);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);

    app(WeekResolver::class)->resolve($save->refresh());

    $crowds = Show::where('save_id', $save->id)->where('week_number', 1)->get()->mapWithKeys(fn (Show $s): array => [$s->brand->value => (int) $s->attendance]);
    $bonus = weekLedgerOf($save->id, LedgerCategory::AttendanceBonus);

    if ($crowds['raw'] === $crowds['smackdown']) {
        expect($bonus)->toHaveCount(0);

        return;
    }

    expect($bonus)->toHaveCount(1)
        ->and($bonus->sole()->amount)->toBe(100_000)
        ->and($bonus->sole()->brand->value)->toBe($crowds['raw'] > $crowds['smackdown'] ? 'raw' : 'smackdown');
});

it('pays no bonus on a tie', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    app(WeekResolver::class)->resolve($save->refresh());
    LedgerEntry::where('save_id', $save->id)->where('category', LedgerCategory::AttendanceBonus)->delete();
    Show::where('save_id', $save->id)->where('week_number', 1)->update(['attendance' => 10_000]);

    $method = new ReflectionMethod(PayShowIncome::class, 'payBonus');
    $method->invoke(app(PayShowIncome::class), new WeekContext($save->refresh(), 1, 1), ['raw' => 10_000, 'smackdown' => 10_000]);

    expect(weekLedgerOf($save->id, LedgerCategory::AttendanceBonus))->toHaveCount(0);
});

it('charges $50,000 production per weekly show, once a night', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    app(WeekResolver::class)->resolve($save->refresh());

    $production = weekLedgerOf($save->id, LedgerCategory::ShowProduction);

    expect($production)->toHaveCount(2)
        ->and($production->every(fn (LedgerEntry $e): bool => $e->amount === -50_000))->toBeTrue();
});

it('charges medical rehab every week for each injured superstar', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian'], smackdown: ['Batista']);
    $injuries = app(InjuryService::class);
    $injuries->injure(GameBuilder::wrestler($save, 'Edge'), InjurySeverity::Minor, 1, $save->rng('t', 1));
    $injuries->injure(GameBuilder::wrestler($save, 'Kane'), InjurySeverity::Serious, 1, $save->rng('t', 1));

    app(WeekResolver::class)->resolve($save->refresh());

    $medical = weekLedgerOf($save->id, LedgerCategory::MedicalRehab)->sole();

    expect($medical->brand)->toBe(Brand::Raw)
        ->and($medical->amount)->toBe(-15_000)
        ->and($medical->memo)->toBe('Medical rehab (1 minor, 1 serious injuries)');
});

it('pays the week’s income before the deficit check', function () {
    $steps = WeekResolver::STEPS;

    expect(array_search(PayShowIncome::class, $steps, true))->toBeLessThan(array_search(TrackDeficit::class, $steps, true));
});
