<?php

use App\Enums\Brand;
use App\Enums\ComplaintLevel;
use App\Enums\ContractLength;
use App\Enums\ContractStatus;
use App\Enums\MoraleComplaintType;
use App\Models\Championship;
use App\Models\Contract;
use App\Models\MatchParticipant;
use App\Models\MatchSlot;
use App\Models\PromoType;
use App\Models\Save;
use App\Models\TitleReign;
use App\Services\Ai\AiContractJudge;
use App\Services\Ai\AiRosterManager;
use App\Services\Promos\PromoService;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| The rival GM judges each expiring contract on how the superstar did during
| it (house rule, 2026-11-17, `AiContractJudge`). The player runs SmackDown,
| so the rival is Raw. Each contract here is 12 weeks (3 months), signed in
| week 1 and judged in week 12, its final week.
*/

const RENEW_MEN = ['Edge', 'Kane', 'Carlito', 'Chris Masters', 'Kurt Angle', 'John Cena', 'Big Show', 'Eugene'];

function renewSave(): Save
{
    GameBuilder::passiveRival();
    config([
        'gm.ai.roster.renewal.decide_at_weeks_left' => 1,
        // Every roll comes out the way the rule leans, unless a test says otherwise.
        'gm.ai.roster.renewal.title_wins_keep' => 1.0,
        'gm.ai.roster.renewal.complaint_expire' => 1.0,
        'gm.ai.roster.renewal.busy_keep' => 1.0,
        'gm.ai.roster.renewal.idle_expire' => 1.0,
        'gm.ai.roster.renewal.default_keep' => 1.0,
    ]);

    $save = GameBuilder::inSeason(raw: RENEW_MEN, smackdown: ['Batista', 'Rey Mysterio'], player: Brand::Smackdown);
    Contract::where('save_id', $save->id)->update(['length_weeks' => 12, 'starts_week' => 1, 'signed_week' => 1, 'weeks_remaining' => 1]);

    return $save;
}

/**
 * A decided 1 vs 1 on Raw in `$week`, `$winner` over `$loser`.
 */
function decided(Save $save, int $week, string $winner, string $loser): void
{
    $show = Card::show($save, Brand::Raw, week: $week);
    $position = MatchSlot::where('show_id', $show->id)->whereNotNull('match_type_id')->count() + 1;
    $slot = Card::book($save, $show, $position, Card::singles(), [[$winner], [$loser]]);

    MatchParticipant::where('match_slot_id', $slot->id)->where('save_wrestler_id', GameBuilder::wrestler($save, $winner)->id)->update(['result' => 'win']);
    MatchParticipant::where('match_slot_id', $slot->id)->where('save_wrestler_id', GameBuilder::wrestler($save, $loser)->id)->update(['result' => 'loss']);
}

function standing(Save $save, string $name): array
{
    return app(AiContractJudge::class)->standing($save, GameBuilder::wrestler($save, $name)->load('activeContract'), 12);
}

function renewed(Save $save, string $name): ?Contract
{
    return Contract::where('save_wrestler_id', GameBuilder::wrestler($save, $name)->id)->where('status', ContractStatus::Active)->where('starts_week', 12)->first();
}

it('always re-signs a champion for 6 or 3 months, even with a losing record', function () {
    $save = renewSave();
    GameBuilder::giveTitle($save, 'intercontinental', ['Carlito']);
    decided($save, 2, 'Kane', 'Carlito');
    decided($save, 3, 'Edge', 'Carlito');

    expect(standing($save, 'Carlito')['rule'])->toBe(AiContractJudge::RULE_CHAMPION);

    app(AiRosterManager::class)->renew($save->refresh(), Brand::Raw, 12);

    expect(renewed($save, 'Carlito')?->length_weeks)->toBeIn([ContractLength::SixMonths->value, ContractLength::ThreeMonths->value]);
});

it('lets a superstar with a losing record go, on a roll (house rule, 2026-11-17)', function () {
    config(['gm.ai.roster.renewal.losing_expire' => 1.0]);
    $save = renewSave();
    decided($save, 2, 'Kane', 'Eugene');
    decided($save, 3, 'Edge', 'Eugene');
    decided($save, 4, 'Eugene', 'Big Show');

    expect(standing($save, 'Eugene')['rule'])->toBe(AiContractJudge::RULE_LOSING);

    app(AiRosterManager::class)->renew($save->refresh(), Brand::Raw, 12);

    expect(renewed($save, 'Eugene'))->toBeNull();
});

it('sometimes keeps a superstar with a losing record (house rule, 2026-11-17)', function () {
    config(['gm.ai.roster.renewal.losing_expire' => 0.0]);
    $save = renewSave();
    decided($save, 2, 'Kane', 'Eugene');
    decided($save, 3, 'Edge', 'Eugene');

    app(AiRosterManager::class)->renew($save->refresh(), Brand::Raw, 12);

    expect(renewed($save, 'Eugene'))->not->toBeNull();
});

it('does not count an even record as losing', function () {
    $save = renewSave();
    decided($save, 2, 'Kane', 'Eugene');
    decided($save, 3, 'Eugene', 'Edge');

    expect(standing($save, 'Eugene')['rule'])->not->toBe(AiContractJudge::RULE_LOSING);
});

it('re-signs a superstar who won enough titles for the contract length', function () {
    $save = renewSave();
    $title = Championship::where('key', 'intercontinental')->firstOrFail();

    // Two reigns won and lost during a 3-month contract.
    foreach ([2, 6] as $week) {
        TitleReign::create([
            'save_id' => $save->id, 'championship_id' => $title->id, 'brand' => Brand::Raw, 'reign_number' => $week,
            'holder_type' => 'wrestler', 'save_wrestler_id' => GameBuilder::wrestler($save, 'Kane')->id,
            'holder_snapshot' => ['ids' => [GameBuilder::wrestler($save, 'Kane')->id], 'names' => ['Kane']],
            'season' => 1, 'won_week' => $week, 'lost_week' => $week + 1, 'end_reason' => 'lost_match',
        ]);
    }

    expect(standing($save, 'Kane'))->toMatchArray(['rule' => AiContractJudge::RULE_TITLES, 'title_wins' => 2]);

    // One title on a 3-month contract isn't enough.
    TitleReign::where('save_id', $save->id)->where('won_week', 6)->delete();
    expect(standing($save, 'Kane')['rule'])->not->toBe(AiContractJudge::RULE_TITLES);
});

it('lets a superstar who complains most of the time go', function () {
    $save = renewSave();
    GameBuilder::wrestler($save, 'Chris Masters')->complaints()->create([
        'save_id' => $save->id, 'type' => MoraleComplaintType::TitleMatch, 'level' => ComplaintLevel::Unsatisfied, 'opened_week' => 3,
    ]);

    expect(standing($save, 'Chris Masters')['rule'])->toBe(AiContractJudge::RULE_COMPLAINS);

    app(AiRosterManager::class)->renew($save->refresh(), Brand::Raw, 12);

    expect(renewed($save, 'Chris Masters'))->toBeNull();
});

it('re-signs a busy superstar and lets a rarely used one go, counting promos as bookings', function () {
    $save = renewSave();

    // Kurt Angle works 10 of the 12 weeks (8 matches he wins, 2 promos).
    foreach (range(1, 8) as $week) {
        decided($save, $week, 'Kurt Angle', 'Big Show');
    }

    foreach ([9, 10] as $week) {
        $show = Card::show($save, Brand::Raw, week: $week);
        app(PromoService::class)->book($show, 1, PromoType::where('key', 'interview_space')->firstOrFail(), GameBuilder::wrestler($save, 'Kurt Angle')->id);
    }

    // John Cena wrestles twice in 12 weeks and wins both.
    decided($save, 2, 'John Cena', 'Edge');
    decided($save, 9, 'John Cena', 'Kane');

    expect(standing($save, 'Kurt Angle'))->toMatchArray(['rule' => AiContractJudge::RULE_BUSY, 'booked_weeks' => 10])
        ->and(standing($save, 'John Cena'))->toMatchArray(['rule' => AiContractJudge::RULE_IDLE, 'booked_weeks' => 2]);

    app(AiRosterManager::class)->renew($save->refresh(), Brand::Raw, 12);

    expect(renewed($save, 'Kurt Angle'))->not->toBeNull()
        ->and(renewed($save, 'John Cena'))->toBeNull();
});

it('falls back to an 80% re-sign for everyone else', function () {
    $save = renewSave();

    foreach ([1, 3, 5, 7, 9, 11] as $week) {
        decided($save, $week, 'Edge', 'Big Show');
    }

    expect(standing($save, 'Edge')['rule'])->toBe(AiContractJudge::RULE_DEFAULT);

    config(['gm.ai.roster.renewal.default_keep' => 0.0]);
    app(AiRosterManager::class)->renew($save->refresh(), Brand::Raw, 12);
    expect(renewed($save, 'Edge'))->toBeNull();
});

it('decides nothing before the contract’s final week', function () {
    $save = renewSave();
    Contract::where('save_id', $save->id)->update(['weeks_remaining' => 2]);

    app(AiRosterManager::class)->renew($save->refresh(), Brand::Raw, 11);

    expect(Contract::where('save_id', $save->id)->where('status', ContractStatus::Superseded)->count())->toBe(0);
});

it('takes a shorter deal for a champion when money is tight', function () {
    $save = renewSave();
    GameBuilder::giveTitle($save, 'intercontinental', ['Carlito']);
    // Enough for 1 month ($40,000), not 3 or 6.
    $save->brand(Brand::Raw)->update(['budget' => 45_000]);

    app(AiRosterManager::class)->renew($save->refresh(), Brand::Raw, 12);

    expect(renewed($save, 'Carlito')?->length_weeks)->toBe(ContractLength::FiveWeeks->value);
});
