<?php

use App\Enums\Brand;
use App\Enums\InjurySeverity;
use App\Enums\ShowStatus;
use App\Enums\ShowType;
use App\Models\Injury;
use App\Models\PromoType;
use App\Models\Rivalry;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Injuries\InjuryService;
use App\Services\Promos\PromoService;
use App\Services\Rivalries\RivalryService;
use App\Services\Week\WeekResolver;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| House rules (2026-11-20): a rival who manages or referees a match the other
| is in keeps the rivalry going (a full meeting, `gm.rivalries.meeting_roles`);
| Captain Fall captains are competitors; Continue Rivalry tires the rivals but
| never injures them (`gm.promos.injury_exempt`).
*/

function rolesSave(): Save
{
    GameBuilder::passiveRival();

    return GameBuilder::inSeason(
        raw: ['Edge', 'Kane', 'Carlito', 'Chris Masters', 'Shelton Benjamin', 'Snitsky', 'Ric Flair', 'Trish Stratus'],
        smackdown: ['Batista'],
    );
}

function rolesRivalry(Save $save, string $a, string $b, int $heat): Rivalry
{
    $rivalry = app(RivalryService::class)->start($save, GameBuilder::wrestler($save, $a)->id, GameBuilder::wrestler($save, $b)->id, 1);
    $rivalry->update(['heat' => $heat]);

    return $rivalry;
}

it('counts a rival managing the other’s opponent as a meeting', function () {
    $save = rolesSave();
    $rivalry = rolesRivalry($save, 'Edge', 'Kane', 30);
    $show = Card::show($save, Brand::Raw, ShowType::Weekly, 2);
    Card::book($save, $show, 1, Card::type('single', 'Single vs. w/ Manager'), [['Edge'], ['Carlito']], [1 => 'Kane']);
    $show->update(['status' => ShowStatus::Aired]);

    app(RivalryService::class)->settleWeek($save, 2);

    expect($rivalry->refresh()->heat)->toBe(40)->and($rivalry->active)->toBeTrue();
});

it('counts a rival as the Special Referee as a meeting', function () {
    $save = rolesSave();
    $rivalry = rolesRivalry($save, 'Edge', 'Kane', 30);
    $show = Card::show($save, Brand::Raw, ShowType::Weekly, 2);
    Card::book($save, $show, 1, Card::type('special_referee', 'Single'), [['Edge'], ['Carlito']], referee: 'Kane');
    $show->update(['status' => ShowStatus::Aired]);

    app(RivalryService::class)->settleWeek($save, 2);

    expect($rivalry->refresh()->heat)->toBe(40);
});

it('counts rivals captaining a Captain Fall against each other as a meeting', function () {
    $save = rolesSave();
    $rivalry = rolesRivalry($save, 'Edge', 'Kane', 30);
    $show = Card::show($save, Brand::Raw, ShowType::Weekly, 2);
    Card::book($save, $show, 1, Card::type('six_man_tag', 'Captain Fall Tornado 6-Man Tag Elimination'), [
        ['Edge', 'Carlito', 'Chris Masters'],
        ['Kane', 'Shelton Benjamin', 'Snitsky'],
    ]);
    $show->update(['status' => ShowStatus::Aired]);

    app(RivalryService::class)->settleWeek($save, 2);

    expect($rivalry->refresh()->heat)->toBe(40);
});

it('still decays a week a rival only manages someone else’s match', function () {
    $save = rolesSave();
    $rivalry = rolesRivalry($save, 'Edge', 'Kane', 30);
    $show = Card::show($save, Brand::Raw, ShowType::Weekly, 2);
    Card::book($save, $show, 1, Card::type('single', 'Single vs. w/ Manager'), [['Carlito'], ['Chris Masters']], [1 => 'Kane']);
    $show->update(['status' => ShowStatus::Aired]);

    app(RivalryService::class)->settleWeek($save, 2);

    expect($rivalry->refresh()->heat)->toBe(10);
});

it('lists the rivals’ matches together newest first, managers and referees included', function () {
    $save = rolesSave();
    $rivalry = rolesRivalry($save, 'Edge', 'Kane', 30);
    $two = Card::show($save, Brand::Raw, ShowType::Weekly, 2);
    $first = Card::book($save, $two, 1, Card::singles(), [['Edge'], ['Kane']]);
    $managed = Card::book($save, $two, 2, Card::type('single', 'Single vs. w/ Manager'), [['Edge'], ['Carlito']], [1 => 'Kane']);
    Card::book($save, $two, 3, Card::singles(), [['Edge'], ['Snitsky']]);
    $two->update(['status' => ShowStatus::Aired]);
    $three = Card::show($save, Brand::Raw, ShowType::Weekly, 3);
    $refereed = Card::book($save, $three, 1, Card::type('special_referee', 'Single'), [['Edge'], ['Carlito']], referee: 'Kane');
    $three->update(['status' => ShowStatus::Aired]);
    // Not aired yet: not part of the history.
    Card::book($save, Card::show($save, Brand::Raw, ShowType::Weekly, 4), 1, Card::singles(), [['Edge'], ['Kane']]);

    expect(app(RivalryService::class)->matchSlotIds($rivalry))
        ->toBe([$refereed->id, $managed->id, $first->id]);
});

it('tires both rivals in a Continue Rivalry but never injures them', function () {
    config(['gm.promos.success_rates.continue_rivalry' => 1.0]);
    $save = rolesSave();
    rolesRivalry($save, 'Edge', 'Kane', 30);
    $edge = GameBuilder::wrestler($save, 'Edge');
    $kane = GameBuilder::wrestler($save, 'Kane');
    // 100%: a 1-bar promo would injure them anywhere else.
    $edge->update(['fatigue' => 100]);
    // A minor injury can't escalate in it either.
    app(InjuryService::class)->injure($kane, InjurySeverity::Minor, 1, $save->rng('t'));
    $kane->update(['fatigue' => 100]);
    config(['gm.injuries.escalation_chance' => 1.0]);

    $show = Card::show($save);
    Card::book($save, $show, 1, Card::singles(), [['Carlito'], ['Chris Masters']]);
    app(PromoService::class)->book($show, 1, PromoType::where('key', 'continue_rivalry')->firstOrFail(), $edge->id, $kane->id);
    app(WeekResolver::class)->resolve($save->refresh());

    expect(Injury::where('save_wrestler_id', $edge->id)->exists())->toBeFalse()
        ->and(Injury::where('save_wrestler_id', $kane->id)->where('severity', 'serious')->exists())->toBeFalse()
        // Recovered 3 bars from the 100% cap.
        ->and(SaveWrestler::find($edge->id)->fatigue)->toBe(70);
});

it('sends each rivalry’s matches together to the Rivalries page, with week, PPV and outcome', function () {
    $save = rolesSave();
    rolesRivalry($save, 'Edge', 'Kane', 30);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);
    app(WeekResolver::class)->resolve($save->refresh());

    $this->get(route('gm.rivalries', $save))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('heat_colors', [50, 80])
            ->has('rivalries.0.matches', 1)
            ->where('rivalries.0.matches.0.season', 1)
            ->where('rivalries.0.matches.0.season_week', 1)
            ->where('rivalries.0.matches.0.ppv', null)
            ->where('rivalries.0.matches.0.outcome.match_type', fn ($type) => $type !== null)
            ->where('rivalries.0.matches.0.link', fn (string $link) => str_contains($link, 'match=')));
});
