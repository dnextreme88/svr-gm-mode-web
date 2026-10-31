<?php

use App\Enums\Brand;
use App\Enums\PpvKey;
use App\Enums\ShowType;
use App\Models\MatchSlot;
use App\Models\PpvEvent;
use App\Models\Save;
use App\Models\Show;
use App\Services\Attendance\SummerSlamMainEvent;
use App\Services\Week\ShowScheduler;
use App\Services\Week\Steps\AdvanceWeek;
use App\Services\Week\WeekContext;
use Tests\Support\GameBuilder;

/*
| House rule (2026-11-01): the brand whose weekly shows drew more fans this
| season, up to SummerSlam week, gets SummerSlam's main event. SummerSlam is
| one card: that brand books Matches 1, 3 and 5, the other Matches 2 and 4.
| A tie goes to the higher draw score, then a seeded coin flip.
*/

function summerSlamWeek(): int
{
    return (int) PpvEvent::where('key', PpvKey::Summerslam)->value('season_week');
}

/**
 * Give each brand's weekly show in weeks 1–2 a crowd and a draw score.
 *
 * @param  array<string, array{0: int, 1: int}>  $nights  brand => [crowd, score] per show
 */
function drawCrowds(Save $save, array $nights): void
{
    foreach ([1, 2] as $week) {
        app(ShowScheduler::class)->ensureWeek($save, $week);

        foreach ($nights as $brand => [$crowd, $score]) {
            Show::where('save_id', $save->id)->where('week_number', $week)->where('brand', $brand)->where('show_type', ShowType::Weekly)
                ->update(['attendance' => $crowd, 'attendance_breakdown' => json_encode(['kind' => 'weekly', 'factors' => [], 'noise' => 0, 'score' => $score, 'gate' => 0])]);
        }
    }
}

it('gives the main event to the brand that drew more fans', function () {
    $save = GameBuilder::inSeason();
    drawCrowds($save, ['raw' => [9_000, 30], 'smackdown' => [12_000, 40]]);

    expect(app(SummerSlamMainEvent::class)->headliner($save, 1, summerSlamWeek()))->toBe(Brand::Smackdown);
});

it('breaks a tie on crowds with the draw score', function () {
    $save = GameBuilder::inSeason();
    drawCrowds($save, ['raw' => [10_000, 45], 'smackdown' => [10_000, 40]]);

    expect(app(SummerSlamMainEvent::class)->headliner($save, 1, summerSlamWeek()))->toBe(Brand::Raw);
});

it('settles a dead heat with the same seeded coin flip every time', function () {
    $save = GameBuilder::inSeason();
    drawCrowds($save, ['raw' => [10_000, 40], 'smackdown' => [10_000, 40]]);
    $summerSlam = app(SummerSlamMainEvent::class);

    expect($summerSlam->headliner($save, 1, summerSlamWeek()))->toBe($summerSlam->headliner($save, 1, summerSlamWeek()));
});

it('makes SummerSlam one card: Matches 1, 3 and the main event for the bigger draw, 2 and 4 for the other', function () {
    $save = GameBuilder::inSeason();
    drawCrowds($save, ['raw' => [15_000, 60], 'smackdown' => [8_000, 20]]);
    $week = summerSlamWeek();

    $cards = app(ShowScheduler::class)->ensureWeek($save, $week)->filter(fn (Show $s): bool => $s->isPpv())->values();
    $owners = MatchSlot::where('show_id', $cards[0]->id)->orderBy('position')->pluck('owner_brand', 'position')->map(fn (Brand $b): string => $b->value)->all();

    expect($cards)->toHaveCount(1)
        ->and($cards[0]->brand)->toBeNull()
        ->and($cards[0]->name)->toBe('SummerSlam')
        ->and($owners)->toBe([1 => 'raw', 2 => 'smackdown', 3 => 'raw', 4 => 'smackdown', 5 => 'raw'])
        ->and(app(SummerSlamMainEvent::class)->cardHeadliner($cards[0]))->toBe(Brand::Raw);
});

it('has the rival book its SummerSlam matches as soon as the week opens', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian', 'Chris Jericho', 'Shawn Michaels', 'Carlito'], smackdown: ['Batista', 'Rey Mysterio', 'Booker T', 'JBL', 'Chris Benoit', 'Orlando Jordan', 'Kurt Angle', 'Randy Orton']);
    drawCrowds($save, ['raw' => [15_000, 60], 'smackdown' => [8_000, 20]]);
    $week = summerSlamWeek();
    $save->update(['current_week' => $week - 1]);

    app(AdvanceWeek::class)->handle(new WeekContext($save->refresh(), $week - 1, 1));

    $card = Show::where('save_id', $save->id)->where('week_number', $week)->whereNull('brand')->sole();
    $booked = MatchSlot::with('participants')->where('show_id', $card->id)->orderBy('position')->get()
        ->mapWithKeys(fn (MatchSlot $s): array => [$s->position => $s->isBooked()])->all();

    // The rival (SmackDown) has Matches 2 and 4; the player's are left to book.
    expect($booked)->toBe([1 => false, 2 => true, 3 => false, 4 => true, 5 => false]);
});
