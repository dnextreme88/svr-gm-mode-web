<?php

use App\Enums\Brand;
use App\Enums\ComplaintLevel;
use App\Enums\ContractLength;
use App\Enums\MoraleComplaintType;
use App\Enums\ShowType;
use App\Enums\Stipulation;
use App\Models\Championship;
use App\Models\MatchResult;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Models\TitleShot;
use App\Models\Wrestler;
use App\Services\Ai\AiGm;
use App\Services\Ai\AiRosterManager;
use App\Services\Ai\AiStipulationPlanner;
use App\Services\Roster\RosterService;
use App\Services\Week\ShowScheduler;
use App\Services\Week\WeekResolver;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| The rival GM's plans (house rule, 2026-11-13): Loser Leaves Show and Last
| Chance on an interval, a #1 Contender's Match before its own PPVs, morale
| fixes (a Book to Win for a losing streak), a full roster with Legends,
| and its books on the Financials page. The player runs SmackDown, so the
| rival is Raw; Backlash (week 4) is Raw's own PPV.
*/

const PLAN_MEN = ['Big Show', 'Lance Cade', 'Trevor Murdoch', 'Carlito', 'Chris Masters', 'Edge', 'Eugene', 'The Hurricane', 'John Cena', 'Kane', 'Kurt Angle', 'Rene Dupree'];

function planSave(): Save
{
    GameBuilder::passiveRival();

    return GameBuilder::inSeason(raw: PLAN_MEN, smackdown: ['Batista', 'Rey Mysterio'], player: Brand::Smackdown);
}

/**
 * @return Collection<int, MatchSlot>
 */
function planCard(Save $save, int $week = 1, ShowType $type = ShowType::Weekly): Collection
{
    $show = Card::show($save, Brand::Raw, $type, $week);
    app(AiGm::class)->bookShow($show);

    return MatchSlot::with(['participants', 'matchType'])->where('show_id', $show->id)->orderBy('position')->get()
        ->filter(fn (MatchSlot $s): bool => $s->isBooked())->values()->toBase();
}

function complain(SaveWrestler $wrestler, MoraleComplaintType $type, ComplaintLevel $level): void
{
    $wrestler->complaints()->create(['save_id' => $wrestler->save_id, 'type' => $type, 'level' => $level, 'opened_week' => 1]);
}

describe('interval rolls', function () {
    it('books on a won roll, then waits a full interval', function () {
        $save = planSave();
        $planner = app(AiStipulationPlanner::class);
        config(['gm.ai.stipulations.loser_leaves.chance' => 1.0]);

        expect($planner->wants($save, Brand::Raw, AiStipulationPlanner::LOSER_LEAVES, 3))->toBeTrue()
            // A won roll keeps trying until it's booked.
            ->and($planner->wants($save, Brand::Raw, AiStipulationPlanner::LOSER_LEAVES, 4))->toBeTrue();

        $planner->booked($save, Brand::Raw, AiStipulationPlanner::LOSER_LEAVES, 4);

        expect($planner->wants($save, Brand::Raw, AiStipulationPlanner::LOSER_LEAVES, 29))->toBeFalse()
            ->and($planner->wants($save, Brand::Raw, AiStipulationPlanner::LOSER_LEAVES, 30))->toBeTrue();
    });

    it('skips a lost roll for a full interval', function () {
        $save = planSave();
        $planner = app(AiStipulationPlanner::class);
        config(['gm.ai.stipulations.last_chance.chance' => 0.0]);

        expect($planner->wants($save, Brand::Raw, AiStipulationPlanner::LAST_CHANCE, 1))->toBeFalse()
            ->and($save->brand(Brand::Raw)->ai_plans['last_chance']['due'])->toBe(18);
    });

    it('books Loser Leaves Show between its two least valuable superstars', function () {
        $save = planSave();
        config(['gm.ai.stipulations.loser_leaves.chance' => 1.0]);
        GameBuilder::giveTitle($save, 'wwe', ['John Cena']);

        $slot = planCard($save)->firstWhere('stipulation', Stipulation::LoserLeavesShow);

        expect($slot)->not->toBeNull()
            ->and($slot->competitorIds())->not->toContain(GameBuilder::wrestler($save, 'John Cena')->id)
            ->and($save->brand(Brand::Raw)->ai_plans['loser_leaves'])->toBe(['pending' => false, 'due' => 27]);
    });

    it('keeps a won Last Chance roll waiting until someone qualifies', function () {
        $save = planSave();
        config(['gm.ai.stipulations.last_chance.chance' => 1.0]);
        GameBuilder::giveTitle($save, 'wwe', ['John Cena']);

        expect(planCard($save)->firstWhere('stipulation', Stipulation::LastChance))->toBeNull()
            ->and($save->brand(Brand::Raw)->ai_plans['last_chance']['pending'])->toBeTrue();
    });
});

describe('#1 Contender\'s Match before its own PPV', function () {
    it('books one in the 3 weeks before the PPV and holds it for the PPV', function () {
        $save = planSave();
        config(['gm.ai.stipulations.number_one_contender_ppv.weeks_before' => 3]);
        app(ShowScheduler::class)->ensureWeek($save, 4);
        GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
        GameBuilder::giveTitle($save, 'intercontinental', ['Kurt Angle']);

        $slot = planCard($save)->firstWhere('stipulation', Stipulation::NumberOneContender);

        expect($slot)->not->toBeNull()
            ->and(app(AiStipulationPlanner::class)->contenderBookedFor($save, Brand::Raw, Show::where('save_id', $save->id)->where('week_number', 4)->where('brand', Brand::Raw)->where('show_type', ShowType::Ppv)->sole()))->toBeTrue();
    });

    it('pays the contendership off at the PPV and keeps the title off the weekly show before it', function () {
        $save = planSave();
        config(['gm.ai.stipulations.number_one_contender_ppv.weeks_before' => 3, 'gm.ai.titles.main_event_chance.weekly' => 1.0, 'gm.ai.titles.other_slot_chance' => 1.0]);
        app(ShowScheduler::class)->ensureWeek($save, 4);
        GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
        $edge = GameBuilder::wrestler($save, 'Edge');
        $wwe = Championship::where('key', 'wwe')->sole();
        $earned = Card::show($save, Brand::Smackdown);
        TitleShot::create(['save_id' => $save->id, 'brand' => Brand::Raw, 'championship_id' => $wwe->id, 'kind' => 'number_one_contender', 'status' => 'pending', 'champion_ids' => [GameBuilder::wrestler($save, 'John Cena')->id], 'contender_ids' => [$edge->id], 'earned_week' => 1, 'earned_match_id' => MatchSlot::where('show_id', $earned->id)->where('position', 1)->firstOrFail()->id]);

        // Held on the weekly show.
        expect(planCard($save)->contains(fn (MatchSlot $s): bool => $s->title_championship_id === $wwe->id))->toBeFalse();

        // Paid off at Backlash.
        $save->update(['current_week' => 4]);
        $payoff = planCard($save->refresh(), 4, ShowType::Ppv)->firstWhere('title_championship_id', $wwe->id);

        expect($payoff)->not->toBeNull()
            ->and($payoff->competitorIds())->toContain($edge->id);
    });
});

describe('morale', function () {
    it('books an Upset superstar who has gone unused', function () {
        $save = planSave();
        config(['gm.ai.morale.fix_chance' => ['content' => 0.0, 'unsatisfied' => 0.0, 'upset' => 1.0], 'gm.card.match_slots' => 6]);
        $eugene = GameBuilder::wrestler($save, 'Eugene');
        complain($eugene, MoraleComplaintType::Inactivity, ComplaintLevel::Upset);

        expect(planCard($save)->contains(fn (MatchSlot $s): bool => in_array($eugene->id, $s->competitorIds(), true)))->toBeTrue();
    });

    it('books a slumping superstar to win, and the simulated show honours it', function () {
        $save = planSave();
        config(['gm.ai.morale.fix_chance' => ['content' => 1.0, 'unsatisfied' => 1.0, 'upset' => 1.0]]);
        $eugene = GameBuilder::wrestler($save, 'Eugene');
        complain($eugene, MoraleComplaintType::LosingStreak, ComplaintLevel::Unsatisfied);

        $slot = planCard($save)->first(fn (MatchSlot $s): bool => $s->booked_side !== null);

        expect($slot)->not->toBeNull()
            ->and($slot->sides()[$slot->booked_side])->toContain($eugene->id);

        app(WeekResolver::class)->resolveWeek($save->refresh(), 1);

        expect(MatchResult::where('match_slot_id', $slot->id)->sole()->winner_side)->toBe($slot->booked_side);
    });

    it('renews a contract a superstar is demanding', function () {
        $save = planSave();
        config(['gm.ai.morale.fix_chance' => ['content' => 1.0, 'unsatisfied' => 1.0, 'upset' => 1.0]]);
        $cena = GameBuilder::wrestler($save, 'John Cena');
        complain($cena, MoraleComplaintType::ContractExtension, ComplaintLevel::Content);
        $before = $cena->activeContract->weeks_remaining;
        $cena->activeContract->update(['weeks_remaining' => 10]);

        app(AiRosterManager::class)->answerContractDemands($save, Brand::Raw, 1);

        expect($cena->refresh()->activeContract->weeks_remaining)->toBe(ContractLength::from((int) config('gm.ai.roster.renew_length'))->value)
            ->and($cena->activeComplaints()->where('type', MoraleComplaintType::ContractExtension)->exists())->toBeFalse()
            ->and($before)->toBeGreaterThan(0);
    });
});

describe('roster', function () {
    it('fills up to 30, signing Legends first up to 3, within budget', function () {
        $save = planSave();
        config(['gm.ai.roster.target_size' => 30, 'gm.ai.roster.legend_chance' => 1.0]);

        app(AiRosterManager::class)->sign($save, Brand::Raw, 1);

        $manager = app(AiRosterManager::class);

        expect(app(RosterService::class)->rosterCount($save, Brand::Raw))->toBe(30)
            ->and($manager->legendCount($save, Brand::Raw))->toBe(3);
    });

    it('doesn\'t sign a Legend it can\'t afford', function () {
        $save = planSave();
        config(['gm.ai.roster.target_size' => 30, 'gm.ai.roster.legend_chance' => 1.0]);
        $save->brand(Brand::Raw)->update(['budget' => (int) config('gm.ai.roster.cash_reserve') + 1]);

        app(AiRosterManager::class)->sign($save, Brand::Raw, 1);

        expect(app(AiRosterManager::class)->legendCount($save, Brand::Raw))->toBe(0)
            ->and(Wrestler::where('roster_type', 'legend')->exists())->toBeTrue();
    });
});

it('shows the rival\'s books on the Financials page', function () {
    $save = planSave();

    $this->get(route('gm.financials', [$save, 'brand' => 'raw']))
        ->assertInertia(fn (Assert $page) => $page->where('brand', 'raw')->where('balance', $save->brand(Brand::Raw)->budget));

    $this->get(route('gm.financials', $save))
        ->assertInertia(fn (Assert $page) => $page->where('brand', 'smackdown'));
});
