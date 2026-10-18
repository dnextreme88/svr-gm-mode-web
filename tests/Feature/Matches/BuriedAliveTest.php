<?php

use App\Enums\Brand;
use App\Enums\ContractStatus;
use App\Enums\InjurySeverity;
use App\Enums\LedgerCategory;
use App\Enums\ShowType;
use App\Exceptions\GameRuleException;
use App\Models\Contract;
use App\Models\Injury;
use App\Models\LedgerEntry;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| House rule: Buried Alive is SmackDown's No Mercy exclusive. The loser is
| out for a month, can't be injured by the match, and their contract pauses.
*/

function noMercyWeek(): int
{
    return (int) config('gm.calendar.ppv_weeks.no_mercy');
}

/**
 * A SmackDown save on No Mercy week with Undertaker vs Kane booked Buried Alive.
 */
function buriedAliveCard(string $winner = 'Undertaker'): Save
{
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Undertaker', 'Kane', 'Batista'], player: Brand::Smackdown);
    $save->update(['current_week' => noMercyWeek()]);

    Card::book($save, Card::show($save, Brand::Smackdown, ShowType::Ppv, noMercyWeek()), 1, Card::type('buried_alive', 'Single'), [['Undertaker'], ['Kane']]);
    FixedOutcomes::queue($winner);

    return $save;
}

function buryWeek(Save $save): void
{
    app(WeekResolver::class)->resolve($save->refresh());
}

function activeWeeksLeft(SaveWrestler $wrestler): int
{
    return (int) Contract::where('save_wrestler_id', $wrestler->id)->where('status', ContractStatus::Active)->value('weeks_remaining');
}

function upkeepFor(Save $save, int $week): int
{
    return (int) LedgerEntry::where('save_id', $save->id)->where('brand', Brand::Smackdown)
        ->where('category', LedgerCategory::ContractUpkeep)->where('week_number', $week)->sum('amount');
}

it('sits the loser out for a month', function () {
    $save = buriedAliveCard();
    buryWeek($save);

    $kane = GameBuilder::wrestler($save, 'Kane');
    $taker = GameBuilder::wrestler($save, 'Undertaker');
    $week = noMercyWeek();

    expect($kane->buried_week)->toBe($week)
        ->and(collect(range($week + 1, $week + 4))->every(fn (int $w) => $kane->isSittingOut($w)))->toBeTrue()
        ->and($kane->isSittingOut($week + 5))->toBeFalse()
        ->and($taker->isSittingOut($week + 1))->toBeFalse();

    expect(fn () => Card::book($save->refresh(), Card::show($save, Brand::Smackdown), 1, Card::singles(), [['Kane'], ['Batista']]))
        ->toThrow(GameRuleException::class);
});

it('never injures the loser, even at 100% fatigue, but the winner can still get hurt', function () {
    $save = buriedAliveCard();
    GameBuilder::wrestler($save, 'Kane')->update(['fatigue' => 100]);
    GameBuilder::wrestler($save, 'Undertaker')->update(['fatigue' => 100]);

    buryWeek($save);

    expect(Injury::where('save_wrestler_id', GameBuilder::wrestler($save, 'Kane')->id)->exists())->toBeFalse()
        ->and(Injury::where('save_wrestler_id', GameBuilder::wrestler($save, 'Undertaker')->id)->value('severity'))->toBe(InjurySeverity::Serious);
});

it("pauses the loser's contract and upkeep while buried, then resumes", function () {
    $save = buriedAliveCard();
    $kane = GameBuilder::wrestler($save, 'Kane');
    $taker = GameBuilder::wrestler($save, 'Undertaker');
    $kaneBefore = activeWeeksLeft($kane);
    $takerBefore = activeWeeksLeft($taker);

    // No Mercy week itself still counts: Kane worked it.
    buryWeek($save);
    expect(activeWeeksLeft($kane))->toBe($kaneBefore - 1);

    foreach (range(noMercyWeek() + 1, noMercyWeek() + 4) as $week) {
        $expected = expectedUpkeep($save, $week, except: $kane);
        buryWeek($save);
        expect(upkeepFor($save, $week))->toBe($expected);
    }

    expect(activeWeeksLeft($kane))->toBe($kaneBefore - 1)
        ->and(activeWeeksLeft($taker))->toBe($takerBefore - 5);

    $back = noMercyWeek() + 5;
    $expected = expectedUpkeep($save, $back);
    buryWeek($save);

    expect(activeWeeksLeft($kane))->toBe($kaneBefore - 2)
        ->and(upkeepFor($save, $back))->toBe($expected);
});

/**
 * What the SmackDown upkeep line should be for a week, from the contracts
 * active going into it.
 */
function expectedUpkeep(Save $save, int $week, ?SaveWrestler $except = null): int
{
    return -(int) Contract::where('save_id', $save->id)->where('brand', Brand::Smackdown)
        ->where('status', ContractStatus::Active)->where('starts_week', '<=', $week)
        ->when($except, fn ($q) => $q->where('save_wrestler_id', '!=', $except?->id))
        ->sum('weekly_cost');
}

it('buries whoever loses, whichever side that is', function () {
    $save = buriedAliveCard(winner: 'Kane');
    buryWeek($save);

    expect(GameBuilder::wrestler($save, 'Undertaker')->buried_week)->toBe(noMercyWeek())
        ->and(GameBuilder::wrestler($save, 'Kane')->buried_week)->toBeNull();
});
