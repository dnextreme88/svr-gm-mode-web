<?php

use App\Services\Attendance\AttendanceCalculator;

/*
| Fan attendance (house rule, 2026-11-01): the draw score, the crowd and the
| gate, from `gm.attendance`.
*/

/**
 * A full card with nothing else on it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function drawFacts(array $overrides = []): array
{
    return [
        'match_one_booked' => true,
        'main_event_booked' => true,
        'every_slot_booked' => true,
        'main_event' => ['title' => null, 'gimmick' => false, 'rivals' => false],
        'match_one' => ['title' => null, 'gimmick' => false, 'rivals' => false],
        'hot_rivalries' => 0,
        'legends' => 0,
        'rival_promos' => ['booked' => 0, 'succeeded' => 0],
        'other_promos' => ['booked' => 0, 'succeeded' => 0],
        'best_streak' => 0,
        ...$overrides,
    ];
}

function attendance(): AttendanceCalculator
{
    return app(AttendanceCalculator::class);
}

it('scores the worked example at 67, about 15,050 fans for a weekly show', function () {
    // A world title Ladder main event between 65-heat rivals, a tag title
    // match 1, one Legend, a successful Interference, a 6-win streak.
    $factors = attendance()->factors(drawFacts([
        'main_event' => ['title' => 'world', 'gimmick' => true, 'rivals' => true],
        'match_one' => ['title' => 'other', 'gimmick' => false, 'rivals' => false],
        'hot_rivalries' => 1,
        'legends' => 1,
        'rival_promos' => ['booked' => 1, 'succeeded' => 1],
        'best_streak' => 6,
    ]));

    expect($factors)->toBe([
        'full_card' => 10,
        'main_event' => 20,
        'match_one' => 4,
        'hot_rivalries' => 10,
        'legends' => 8,
        'promos' => 10,
        'win_streak' => 5,
    ])
        ->and(attendance()->score($factors))->toBe(67)
        ->and(attendance()->crowd('weekly', 67))->toBe(15_050);
});

it('gives a bare but full card only its full-card points', function () {
    $score = attendance()->score(attendance()->factors(drawFacts()));

    expect($score)->toBe(10)
        ->and(attendance()->crowd('weekly', $score))->toBe(6_500);
});

it('gives the full-card points piece by piece', function () {
    $factors = fn (array $o): int => attendance()->factors(drawFacts($o))['full_card'];

    expect($factors(['every_slot_booked' => false]))->toBe(6)
        ->and($factors(['every_slot_booked' => false, 'match_one_booked' => false]))->toBe(3)
        ->and($factors(['every_slot_booked' => false, 'match_one_booked' => false, 'main_event_booked' => false]))->toBe(0);
});

it('caps hot rivalries at 20 and tiers Legends and win streaks', function () {
    $f = fn (array $o): array => attendance()->factors(drawFacts($o));

    expect($f(['hot_rivalries' => 3])['hot_rivalries'])->toBe(20)
        ->and($f(['legends' => 1])['legends'])->toBe(8)
        ->and($f(['legends' => 4])['legends'])->toBe(15)
        ->and($f(['best_streak' => 4])['win_streak'])->toBe(0)
        ->and($f(['best_streak' => 9])['win_streak'])->toBe(5)
        ->and($f(['best_streak' => 12])['win_streak'])->toBe(10);
});

it('scores rival promos first, then the others, and nothing without promos', function () {
    $f = fn (array $o): int => attendance()->factors(drawFacts($o))['promos'];

    expect($f(['rival_promos' => ['booked' => 2, 'succeeded' => 1], 'other_promos' => ['booked' => 1, 'succeeded' => 1]]))->toBe(5)
        ->and($f(['other_promos' => ['booked' => 2, 'succeeded' => 2]]))->toBe(6)
        ->and($f(['other_promos' => ['booked' => 2, 'succeeded' => 1]]))->toBe(3)
        ->and($f([]))->toBe(0);
});

it('keeps the score within 0 to 100 and the crowd within each range', function () {
    expect(attendance()->score(['a' => 98], 5))->toBe(100)
        ->and(attendance()->score(['a' => 2], -5))->toBe(0)
        ->and(attendance()->crowd('weekly', 0))->toBe(5_000)
        ->and(attendance()->crowd('weekly', 100))->toBe(20_000)
        ->and(attendance()->crowd('brand_ppv', 0))->toBe(10_000)
        ->and(attendance()->crowd('brand_ppv', 100))->toBe(30_000)
        ->and(attendance()->crowd('shared_ppv', 0))->toBe(20_000)
        ->and(attendance()->crowd('shared_ppv', 100))->toBe(50_000);
});

it('prices the gate by the kind of show', function () {
    expect(attendance()->gate('weekly', 15_050))->toBe(451_500)
        ->and(attendance()->gate('brand_ppv', 20_000))->toBe(1_000_000)
        ->and(attendance()->gate('shared_ppv', 40_000))->toBe(3_000_000);
});
