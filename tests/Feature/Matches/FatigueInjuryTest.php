<?php

use App\Enums\InjurySeverity;
use App\Enums\InjuryZone;
use App\Enums\ReignEndReason;
use App\Models\FatigueLog;
use App\Models\Injury;
use App\Models\InjuryType;
use App\Models\TitleReign;
use App\Services\Fatigue\FatigueZones;
use App\Services\Injuries\InjuryService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| §6 Fatigue & Injury.
*/

beforeEach(function () {
    config(['gm.outcomes.clean_finish_chance' => 1.0]);
});

function airWeek($save): void
{
    app(WeekResolver::class)->resolve($save->refresh());
}

/** Fatigue right after the shows aired, before end-of-week recovery. */
function afterShows($save, string $name): int
{
    return FatigueLog::where('save_wrestler_id', GameBuilder::wrestler($save, $name)->id)->sole()->after_shows;
}

it('maps fatigue to the three injury zones', function (int $fatigue, InjuryZone $zone) {
    expect(FatigueZones::zoneFor($fatigue))->toBe($zone);
})->with([
    [0, InjuryZone::Safe],
    [70, InjuryZone::Safe],
    [80, InjuryZone::MinorRisk],
    [90, InjuryZone::MinorRisk],
    [100, InjuryZone::SeriousRisk],
]);

it('adds the match fatigue cost in bars and caps at 100%', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 90]);
    $show = Card::show($save);
    Card::book($save, $show, 1, Card::type('ladder', 'Single'), [['Edge'], ['Kane']]);
    config(['gm.injuries.escalation_chance' => 0.0]);

    airWeek($save);

    expect(afterShows($save, 'Edge'))->toBe(100)
        ->and(afterShows($save, 'Kane'))->toBe(30)
        // Then everyone recovers 2 bars at the end of the week.
        ->and(GameBuilder::wrestler($save, 'Edge')->fatigue)->toBe(80);
});

it('adds both costs when a wrestler is double-booked on one card', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
    $show = Card::show($save);
    Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kane']]);
    Card::book($save, $show, 2, Card::type('hardcore', 'Single'), [['Edge'], ['Carlito']]);

    airWeek($save);

    // Singles (1 bar) + Hardcore (3 bars).
    expect(afterShows($save, 'Edge'))->toBe(40);
});

it('gives a guaranteed serious injury to a wrestler going in at 100% in a match that adds fatigue', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 100]);
    $show = Card::show($save);
    Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kane']]);

    airWeek($save);

    $injury = Injury::where('save_wrestler_id', GameBuilder::wrestler($save, 'Edge')->id)->first();

    expect($injury?->severity)->toBe(InjurySeverity::Serious)
        ->and($injury->durationWeeks() - 1)->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(52);
});

it('judges injuries on the fatigue right after the match: ending at 80–100% always injures', function (int $goingIn, float $seriousShare, InjurySeverity $severity) {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    config(['gm.injuries.serious_share' => $seriousShare]);
    GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => $goingIn]);
    // A 3-bar ladder match.
    Card::book($save, Card::show($save), 1, Card::type('ladder', 'Single'), [['Edge'], ['Kane']]);

    airWeek($save);

    $injury = Injury::where('save_wrestler_id', GameBuilder::wrestler($save, 'Edge')->id)->sole();

    expect($injury->severity)->toBe($severity)
        ->and($injury->fatigue_after)->toBe($goingIn + 30);
})->with([
    'safe going in, 80% after: minor' => [50, 0.0, InjurySeverity::Minor],
    '60% → 90%: minor' => [60, 0.0, InjurySeverity::Minor],
    '70% → 100%: the serious share' => [70, 1.0, InjurySeverity::Serious],
]);

it('gives a serious injury when a match would push fatigue over 100%, whatever the odds', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    config(['gm.injuries.serious_share' => 0.0]);
    GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 80]);
    Card::book($save, Card::show($save), 1, Card::type('ladder', 'Single'), [['Edge'], ['Kane']]);

    airWeek($save);

    $injury = Injury::where('save_wrestler_id', GameBuilder::wrestler($save, 'Edge')->id)->sole();

    expect($injury->severity)->toBe(InjurySeverity::Serious)
        ->and($injury->fatigue_after)->toBe(110)
        // Fatigue itself still caps at 100%.
        ->and(afterShows($save, 'Edge'))->toBe(100);
});

it('never injures a wrestler who ends the match at 70% or less', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    config(['gm.injuries.serious_share' => 1.0]);
    GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 60]);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);

    airWeek($save);

    expect(Injury::count())->toBe(0);
});

it('shows the uncapped total and the injury on the results fatigue card', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 80]);
    Card::book($save, Card::show($save), 1, Card::type('ladder', 'Single'), [['Edge'], ['Kane']]);

    airWeek($save);

    $label = Injury::with('type')->where('save_wrestler_id', GameBuilder::wrestler($save, 'Edge')->id)->sole()->type->label;

    $this->get(route('gm.results', [$save, 1]))
        ->assertInertia(fn ($page) => $page
            ->where('shows.0.fatigue.0.name', 'Edge')
            ->where('shows.0.fatigue.0.after_shows', 110)
            ->where('shows.0.fatigue.0.injury', ['severity' => 'serious', 'label' => $label])
            ->where('shows.0.fatigue.1.injury', null));
});

it('escalates a minor injury to its serious label with a fresh duration in that range', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $edge = GameBuilder::wrestler($save, 'Edge');
    $wrist = InjuryType::where('label', 'Sprained Wrist')->firstOrFail();
    app(InjuryService::class)->injure($edge, InjurySeverity::Minor, 1, $save->rng('test'), type: $wrist);
    config(['gm.injuries.escalation_chance' => 1.0]);

    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);
    airWeek($save);

    $current = Injury::with('type')->where('save_wrestler_id', $edge->id)->whereNull('healed_week')->latest('id')->firstOrFail();

    expect($current->type->label)->toBe('Broken Wrist')
        ->and($current->severity)->toBe(InjurySeverity::Serious)
        ->and($current->escalated_from_minor)->toBeTrue()
        ->and($current->durationWeeks() - 1)->toBeGreaterThanOrEqual(6)->toBeLessThanOrEqual(12);
});

it('never escalates a laceration', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $edge = GameBuilder::wrestler($save, 'Edge');
    $cut = InjuryType::where('label', 'Laceration (Stitches)')->firstOrFail();
    $injury = app(InjuryService::class)->injure($edge, InjurySeverity::Minor, 1, $save->rng('test'), type: $cut);

    expect(app(InjuryService::class)->escalate($injury->load('type'), 1, $save->rng('esc')))->toBeNull();
});

it('lets wrestlers recover fatigue at the end of the week', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 60]);

    airWeek($save);

    expect(GameBuilder::wrestler($save, 'Edge')->fatigue)->toBe(40);
});

it('vacates a champion’s title as soon as a serious injury is assigned', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);

    app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Edge'), InjurySeverity::Serious, 1, $save->rng('test'));

    expect(TitleReign::where('save_id', $save->id)->orderBy('id')->first()->end_reason)->toBe(ReignEndReason::Injured);
});

it('keeps the title through a minor injury', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);

    app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Edge'), InjurySeverity::Minor, 1, $save->rng('test'));

    expect(TitleReign::where('save_id', $save->id)->count())->toBe(1);
});
