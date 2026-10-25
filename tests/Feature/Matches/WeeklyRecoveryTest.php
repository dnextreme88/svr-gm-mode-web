<?php

use App\Enums\PromoTypeKey;
use App\Models\FatigueLog;
use App\Models\PromoType;
use App\Services\Promos\PromoService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| Weekly fatigue (house rule, 2026-10-20): only wrestlers in matches and
| fatiguing promos gain fatigue; at the end of the week everyone recovers
| 2 bars (20%), booked or not. A successful Inactive Superstar promo adds
| 2 more for its target. Fatigue never goes below 0.
*/

function airRecoveryWeek($save): void
{
    app(WeekResolver::class)->resolve($save->refresh());
}

function logFor($save, string $name, int $week = 1): FatigueLog
{
    return FatigueLog::where('save_wrestler_id', GameBuilder::wrestler($save, $name)->id)->where('week_number', $week)->sole();
}

it('adds match fatigue only to the booked wrestler, then recovers 20% for everyone', function () {
    // Superstar A (Edge): 30% + a 50% match = 80%, then 80% - 20% = 60%.
    // Superstar B (Carlito): 40%, not booked: 40% + 0% = 40%, then 20%.
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
    GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 30]);
    GameBuilder::wrestler($save, 'Carlito')->update(['fatigue' => 40]);
    // Iron Man costs 5 bars (50%).
    Card::book($save, Card::show($save), 1, Card::type('iron_man', 'Single'), [['Edge'], ['Kane']]);

    airRecoveryWeek($save);

    expect(logFor($save, 'Edge'))
        ->start->toBe(30)
        ->after_shows->toBe(80)
        ->end->toBe(60)
        ->recovered_bars->toBe(2)
        ->and(GameBuilder::wrestler($save, 'Edge')->fatigue)->toBe(60)
        ->and(logFor($save, 'Carlito'))
        ->start->toBe(40)
        ->after_shows->toBe(40)
        ->end->toBe(20)
        ->and(GameBuilder::wrestler($save, 'Carlito')->fatigue)->toBe(20);
});

it('starts the next week from the recovered fatigue', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
    GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 30]);
    Card::book($save, Card::show($save), 1, Card::type('iron_man', 'Single'), [['Edge'], ['Kane']]);

    airRecoveryWeek($save);
    airRecoveryWeek($save);

    // Week 1 ends at 60%; unbooked in week 2, it goes 60% -> 40%.
    expect(logFor($save, 'Edge', 2))
        ->start->toBe(60)
        ->after_shows->toBe(60)
        ->end->toBe(40);
});

it('recovers wrestlers on both brands, booked or not', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 60]);
    GameBuilder::wrestler($save, 'Batista')->update(['fatigue' => 60]);

    airRecoveryWeek($save);

    expect(GameBuilder::wrestler($save, 'Edge')->fatigue)->toBe(40)
        ->and(GameBuilder::wrestler($save, 'Batista')->fatigue)->toBe(40);
});

it('gives an Inactive Superstar target 2 extra bars, never below 0: 3 bars - 2 - 2 = 0', function () {
    config(['gm.promos.success_rates.inactive_superstar' => 1.0]);
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
    GameBuilder::wrestler($save, 'Carlito')->update(['fatigue' => 30]);
    $show = Card::show($save);
    Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kane']]);
    app(PromoService::class)->book($show, 1, PromoType::where('key', PromoTypeKey::InactiveSuperstar)->firstOrFail(), GameBuilder::wrestler($save, 'Carlito')->id);

    airRecoveryWeek($save);

    expect(GameBuilder::wrestler($save, 'Carlito')->fatigue)->toBe(0)
        ->and(FatigueLog::where('save_wrestler_id', GameBuilder::wrestler($save, 'Carlito')->id)->sole()->recovered_bars)->toBe(4);
});

it('gives no extra recovery when Inactive Superstar fails', function () {
    config(['gm.promos.success_rates.inactive_superstar' => 0.0]);
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
    GameBuilder::wrestler($save, 'Carlito')->update(['fatigue' => 60]);
    $show = Card::show($save);
    app(PromoService::class)->book($show, 1, PromoType::where('key', PromoTypeKey::InactiveSuperstar)->firstOrFail(), GameBuilder::wrestler($save, 'Carlito')->id);

    airRecoveryWeek($save);

    expect(GameBuilder::wrestler($save, 'Carlito')->fatigue)->toBe(40);
});

it('fizzles Inactive Superstar if its target gets booked on the card afterwards', function () {
    config(['gm.promos.success_rates.inactive_superstar' => 1.0]);
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $show = Card::show($save);
    $slot = app(PromoService::class)->book($show, 1, PromoType::where('key', PromoTypeKey::InactiveSuperstar)->firstOrFail(), GameBuilder::wrestler($save, 'Edge')->id);
    Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kane']]);

    airRecoveryWeek($save);

    expect($slot->refresh())
        ->succeeded->toBeFalse()
        ->outcome_note->toBe('Booked on the card');
});

it('logs one fatigue row per wrestler per week, even if resolution is retried', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

    airRecoveryWeek($save);
    airRecoveryWeek($save->refresh()->forceFill(['current_week' => 1]));

    expect(FatigueLog::where('save_id', $save->id)->where('week_number', 1)->where('save_wrestler_id', GameBuilder::wrestler($save, 'Edge')->id)->count())->toBe(1);
});
