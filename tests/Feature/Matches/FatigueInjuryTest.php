<?php

use App\Enums\InjurySeverity;
use App\Enums\InjuryZone;
use App\Enums\ReignEndReason;
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
    config(['gm.injuries.minor_chance' => 0.0, 'gm.injuries.escalation_chance' => 0.0]);

    airWeek($save);

    expect(GameBuilder::wrestler($save, 'Edge')->fatigue)->toBe(100)
        ->and(GameBuilder::wrestler($save, 'Kane')->fatigue)->toBe(30);
});

it('adds both costs when a wrestler is double-booked on one card', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
    $show = Card::show($save);
    Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kane']]);
    Card::book($save, $show, 2, Card::type('hardcore', 'Single'), [['Edge'], ['Carlito']]);

    airWeek($save);

    // Singles (1 bar) + Hardcore (3 bars).
    expect(GameBuilder::wrestler($save, 'Edge')->fatigue)->toBe(40);
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

it('checks fatigue going into the match: reaching 100% mid-match is safe, the next booking is not', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
    config(['gm.injuries.minor_chance' => 0.0]);
    GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 70]);
    $show = Card::show($save);
    // 70% → 100% in a 3-bar ladder match: safe going in.
    Card::book($save, $show, 1, Card::type('ladder', 'Single'), [['Edge'], ['Kane']]);
    // Second match on the same card: Edge goes in at 100%.
    Card::book($save, $show, 2, Card::singles(), [['Edge'], ['Carlito']]);

    airWeek($save);

    $injury = Injury::where('save_wrestler_id', GameBuilder::wrestler($save, 'Edge')->id)->firstOrFail();

    expect($injury->severity)->toBe(InjurySeverity::Serious)
        ->and($injury->match_slot_id)->toBe($show->matchSlots()->where('position', 2)->value('id'));
});

it('rolls for a minor injury in the 80–90% zone using the configured odds', function (float $chance, bool $injured) {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    config(['gm.injuries.minor_chance' => $chance]);
    GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 80]);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);

    airWeek($save);

    $injury = Injury::where('save_wrestler_id', GameBuilder::wrestler($save, 'Edge')->id)->first();

    expect($injury !== null)->toBe($injured);

    if ($injured) {
        expect($injury->severity)->toBe(InjurySeverity::Minor)
            ->and($injury->durationWeeks() - 1)->toBeLessThanOrEqual(4);
    }
})->with([[1.0, true], [0.0, false]]);

it('never injures wrestlers in the safe zone', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    config(['gm.injuries.minor_chance' => 1.0]);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);

    airWeek($save);

    expect(Injury::count())->toBe(0);
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

it('lets wrestlers rest and recover fatigue in weeks they are not booked', function () {
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
