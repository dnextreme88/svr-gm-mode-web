<?php

use App\Enums\Brand;
use App\Enums\DraftPhase;
use App\Enums\DraftPickType;
use App\Enums\FixedSlotKind;
use App\Enums\InjurySeverity;
use App\Enums\LedgerCategory;
use App\Enums\MitbStatus;
use App\Enums\RosterType;
use App\Enums\SaveStage;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\DraftPick;
use App\Models\InterbrandSeries;
use App\Models\LedgerEntry;
use App\Models\MatchSlot;
use App\Models\MoneyInTheBank;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Season;
use App\Models\Show;
use App\Models\TitleReign;
use App\Services\Contracts\ContractService;
use App\Services\Injuries\InjuryService;
use App\Services\Ppv\PpvCardService;
use App\Services\Ppv\ReDraftService;
use App\Services\Titles\MitbService;
use App\Services\Week\ShowScheduler;
use App\Services\Week\WeekResolver;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| §13 PPV calendar and §23 WrestleMania card.
*/

const RUMBLE_WEEK = 43;
const SURVIVOR_WEEK = 34;
const MANIA_WEEK = 52;

/**
 * An in-season save with $size Superstars per brand, moved to a given week.
 */
function ppvSave(int $week, int $size = 16, Brand $player = Brand::Raw): Save
{
    $save = GameBuilder::inSeason(size: $size, player: $player);
    $save->update(['current_week' => $week]);
    app(ShowScheduler::class)->ensureWeek($save, $week);

    return $save->refresh();
}

function interbrandShow(Save $save, int $week): Show
{
    return Show::with('ppvEvent')->where('save_id', $save->id)->where('week_number', $week)->whereNull('brand')->firstOrFail();
}

/**
 * @return list<int>
 */
function rosterIds(Save $save, Brand $brand, int $count): array
{
    return array_values(array_map('intval', SaveWrestler::where('save_id', $save->id)->where('brand', $brand)->orderBy('id')->limit($count)->pluck('id')->all()));
}

function resolveWeek(Save $save): void
{
    app(WeekResolver::class)->resolve($save->refresh());
}

describe('Royal Rumble', function () {
    it('adds an interbrand 30-man match and gives each brand card one fewer slot', function () {
        $save = ppvSave(RUMBLE_WEEK);
        $rumble = interbrandShow($save, RUMBLE_WEEK);
        $rawCard = Show::where('save_id', $save->id)->where('week_number', RUMBLE_WEEK)->where('brand', Brand::Raw)->where('show_type', 'ppv')->firstOrFail();

        expect($rumble->matchSlotCount())->toBe(1)
            ->and(MatchSlot::where('show_id', $rumble->id)->sole()->fixed_kind)->toBe(FixedSlotKind::RoyalRumble)
            ->and($rawCard->matchSlotCount())->toBe(4);
    });

    it('takes 15 entrants per brand and blocks the PPV until you pick them', function () {
        $save = ppvSave(RUMBLE_WEEK);
        $rumble = interbrandShow($save, RUMBLE_WEEK);
        $cards = app(PpvCardService::class);

        expect(fn () => $cards->setEntrants($rumble, Brand::Raw, rosterIds($save, Brand::Raw, 10)))->toThrow(GameRuleException::class, '15')
            ->and(fn () => resolveWeek($save))->toThrow(GameRuleException::class, 'entrants');

        $cards->setEntrants($rumble, Brand::Raw, rosterIds($save, Brand::Raw, 15));
        resolveWeek($save);

        $slot = MatchSlot::with('participants.saveWrestler')->where('show_id', $rumble->id)->sole();

        expect($slot->participants->filter(fn ($p) => $p->saveWrestler->brand === Brand::Raw)->count())->toBe(15)
            ->and($slot->participants->filter(fn ($p) => $p->saveWrestler->brand === Brand::Smackdown)->count())->toBe(15)
            ->and($slot->title_championship_id)->toBeNull()
            ->and(Season::where('save_id', $save->id)->where('number', 1)->value('royal_rumble_winner_id'))->not->toBeNull();
    });

    it('lets a short-handed brand enter everyone it has', function () {
        $save = ppvSave(RUMBLE_WEEK, size: 12);

        expect(app(PpvCardService::class)->rumbleEntrantsFor(interbrandShow($save, RUMBLE_WEEK), Brand::Raw))->toBe(12);
    });
});

describe('Survivor Series', function () {
    it('is a Raw vs SmackDown 3 vs 3 main event with no titles and no Divas', function () {
        $save = ppvSave(SURVIVOR_WEEK);
        $show = interbrandShow($save, SURVIVOR_WEEK);
        $cards = app(PpvCardService::class);
        $diva = SaveWrestler::where('save_id', $save->id)->whereHas('wrestler', fn ($q) => $q->where('roster_type', RosterType::Diva)->where('placeholder_brand', 'raw'))->firstOrFail();
        app(ContractService::class)->signDrafted($save, $diva, Brand::Raw, 1);

        expect(fn () => $cards->setEntrants($show, Brand::Raw, [...rosterIds($save, Brand::Raw, 2), $diva->id]))->toThrow(GameRuleException::class, 'Superstars');

        $cards->setEntrants($show, Brand::Raw, rosterIds($save, Brand::Raw, 3));
        resolveWeek($save);

        $slot = MatchSlot::with(['participants.saveWrestler', 'result'])->where('show_id', $show->id)->sole();

        expect($slot->fixed_kind)->toBe(FixedSlotKind::SurvivorSeriesMainEvent)
            ->and($slot->sides())->toHaveCount(2)
            ->and(collect($slot->sides()[0])->map(fn ($id) => SaveWrestler::find($id)->brand)->unique()->all())->toBe([Brand::Raw])
            ->and(collect($slot->sides()[1])->map(fn ($id) => SaveWrestler::find($id)->brand)->unique()->all())->toBe([Brand::Smackdown])
            ->and($slot->result)->not->toBeNull()
            ->and($slot->title_championship_id)->toBeNull();
    });
});

describe('WrestleMania', function () {
    function maniaSave(?string $rumbleWinner = 'Edge'): Save
    {
        $save = GameBuilder::inSeason(size: 16);
        GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
        GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);

        if ($rumbleWinner !== null) {
            Season::where('save_id', $save->id)->update(['royal_rumble_winner_id' => GameBuilder::wrestler($save, $rumbleWinner)->id]);
        }

        $save->update(['current_week' => MANIA_WEEK]);
        app(ShowScheduler::class)->ensureWeek($save, MANIA_WEEK);

        return $save->refresh();
    }

    it('is one 10-match card: 3 Raw, 3 SmackDown, 3 interbrand series and the main event', function () {
        $save = maniaSave();
        $show = interbrandShow($save, MANIA_WEEK);
        $slots = MatchSlot::where('show_id', $show->id)->orderBy('position')->get();

        expect($slots)->toHaveCount(10)
            ->and($slots->whereIn('position', [1, 2, 3])->pluck('owner_brand')->unique()->values()->all())->toBe([Brand::Raw])
            ->and($slots->whereIn('position', [4, 5, 6])->pluck('owner_brand')->unique()->values()->all())->toBe([Brand::Smackdown])
            ->and($slots->whereIn('position', [7, 8, 9])->pluck('fixed_kind')->unique()->values()->all())->toBe([FixedSlotKind::InterbrandSeries]);
    });

    it('puts the Royal Rumble winner against their brand’s world champion with the title on the line', function () {
        $save = maniaSave('Edge');
        $main = MatchSlot::with('participants.saveWrestler.wrestler')->where('show_id', interbrandShow($save, MANIA_WEEK)->id)->where('position', 10)->sole();

        expect($main->fixed_kind)->toBe(FixedSlotKind::WrestlemaniaMainEvent)
            ->and($main->owner_brand)->toBe(Brand::Raw)
            ->and($main->title_championship_id)->toBe(Championship::where('key', 'wwe')->value('id'))
            ->and($main->participants->map(fn ($p) => $p->saveWrestler->wrestler->name)->sort()->values()->all())->toBe(['Edge', 'John Cena']);
    });

    it('falls back to a normal slot for the winner’s brand when either is seriously injured', function () {
        $save = GameBuilder::inSeason(size: 16);
        GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
        Season::where('save_id', $save->id)->update(['royal_rumble_winner_id' => GameBuilder::wrestler($save, 'Edge')->id]);
        app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'John Cena'), InjurySeverity::Serious, 50, $save->rng('t'));
        $save->update(['current_week' => MANIA_WEEK]);
        app(ShowScheduler::class)->ensureWeek($save, MANIA_WEEK);

        $main = MatchSlot::where('show_id', interbrandShow($save, MANIA_WEEK)->id)->where('position', 10)->sole();

        expect($main->fixed_kind)->toBeNull()
            ->and($main->owner_brand)->toBe(Brand::Raw)
            ->and($main->participants()->count())->toBe(0);
    });

    it('draws the interbrand series from bookable non-champions only', function () {
        $save = GameBuilder::inSeason(size: 16);
        GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
        GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
        $hurt = GameBuilder::wrestler($save, 'Kane');
        app(InjuryService::class)->injure($hurt, InjurySeverity::Serious, 50, $save->rng('t'));
        $benched = GameBuilder::wrestler($save, 'Edge');
        $benched->update(['unbookable_until_week' => 60]);
        $save->update(['current_week' => MANIA_WEEK]);
        app(ShowScheduler::class)->ensureWeek($save, MANIA_WEEK);

        $series = app(PpvCardService::class)->seriesSlots(interbrandShow($save, MANIA_WEEK));
        $ids = $series->flatMap(fn ($s) => $s->participants->pluck('save_wrestler_id'))->all();
        $excluded = [GameBuilder::wrestler($save, 'John Cena')->id, GameBuilder::wrestler($save, 'Batista')->id, $hurt->id, $benched->id];

        expect($series->map(fn ($s) => $s->participants->count())->all())->toBe([2, 4, 6])
            ->and(array_intersect($ids, $excluded))->toBe([])
            ->and(count($ids))->toBe(count(array_unique($ids)));
    });

    it('scores the series 2 of 3, counting Money in the Bank for the retriever’s brand', function () {
        $save = maniaSave();
        $show = interbrandShow($save, MANIA_WEEK);
        $series = app(PpvCardService::class)->seriesSlots($show);
        $name = fn (int $id) => SaveWrestler::with('wrestler')->find($id)->wrestler->name;
        $sides = fn (int $i) => $series[$i]->sides();

        // Only the WrestleMania card airs in this test.
        Show::where('save_id', $save->id)->where('week_number', MANIA_WEEK)->whereNotNull('brand')->update(['status' => 'aired']);

        FixedOutcomes::queue($name($sides(0)[1][0])); // SmackDown wins 1v1
        FixedOutcomes::queue($name($sides(1)[0][0])); // Raw wins 2v2
        $mitbWinner = $series[2]->participants->first(fn ($p) => $p->saveWrestler->brand === Brand::Smackdown)->save_wrestler_id;
        FixedOutcomes::queue($name($mitbWinner)); // SmackDown retrieves the briefcase

        resolveWeek($save);

        $result = InterbrandSeries::sole();

        expect($result->raw_wins)->toBe(1)
            ->and($result->smackdown_wins)->toBe(2)
            ->and($result->winner_brand)->toBe(Brand::Smackdown)
            ->and(MoneyInTheBank::where('save_wrestler_id', $mitbWinner)->sole()->brand)->toBe(Brand::Smackdown);
    });

    it('resets the season after WrestleMania without a new budget grant and expires last year’s briefcase', function () {
        $save = maniaSave();
        $old = app(MitbService::class)->award($save, GameBuilder::wrestler($save, 'Kane'), 10);

        resolveWeek($save);
        $save->refresh();

        expect($save->stage)->toBe(SaveStage::Redrafting)
            ->and($save->current_week)->toBe(53)
            ->and($save->current_season)->toBe(2)
            ->and(Season::where('save_id', $save->id)->where('number', 1)->value('ended_week'))->toBe(52)
            ->and(Season::where('save_id', $save->id)->where('number', 2)->exists())->toBeTrue()
            ->and(LedgerEntry::where('save_id', $save->id)->where('category', LedgerCategory::StartingBudget)->count())->toBe(2)
            ->and($old->refresh()->status)->toBe(MitbStatus::Expired)
            ->and(fn () => resolveWeek($save))->toThrow(GameRuleException::class);
    });
});

describe('re-draft', function () {
    function redraftSave(Brand $winner = Brand::Raw, int $size = 16): Save
    {
        $save = GameBuilder::inSeason(size: $size);
        GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
        $save->update(['stage' => SaveStage::Redrafting, 'current_week' => 53, 'current_season' => 2]);
        Season::create(['save_id' => $save->id, 'number' => 2, 'started_week' => 53]);
        InterbrandSeries::create(['save_id' => $save->id, 'season' => 1, 'week_number' => 52, 'match_ids' => [], 'raw_wins' => 2, 'smackdown_wins' => 1, 'winner_brand' => $winner]);

        return $save->refresh();
    }

    it('makes up to 10 random picks per brand from the rival’s non-champions, keeping contracts', function () {
        $save = redraftSave();
        $batista = GameBuilder::wrestler($save, 'Batista');
        $contracts = SaveWrestler::where('save_id', $save->id)->whereNotNull('brand')->with('activeContract')->get()->mapWithKeys(fn ($w) => [$w->id => $w->activeContract->id]);
        $budget = $save->brand(Brand::Raw)->budget;

        app(ReDraftService::class)->run($save);

        $picks = DraftPick::where('save_id', $save->id)->where('phase', DraftPhase::WrestlemaniaReset)->where('skipped', false)->get();

        expect($picks->where('brand', Brand::Raw)->count())->toBeLessThanOrEqual(10)
            ->and($picks->where('brand', Brand::Smackdown)->count())->toBeLessThanOrEqual(10)
            ->and($picks->pluck('save_wrestler_id')->contains($batista->id))->toBeFalse()
            ->and($batista->refresh()->brand)->toBe(Brand::Smackdown)
            ->and(TitleReign::where('save_id', $save->id)->whereNotNull('lost_week')->count())->toBe(0)
            ->and($save->refresh()->stage)->toBe(SaveStage::InSeason)
            ->and($save->brand(Brand::Raw)->budget)->toBe($budget);

        $picks->each(fn ($p) => expect(SaveWrestler::with('activeContract')->find($p->save_wrestler_id)->activeContract->id)->toBe($contracts[$p->save_wrestler_id]));
    });

    it('honours the winner’s exemption and replaces one random pick with the hand-pick', function () {
        $save = redraftSave(Brand::Raw);
        $protected = GameBuilder::wrestler($save, 'Edge');
        $target = SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Smackdown)->where('id', '!=', GameBuilder::wrestler($save, 'Batista')->id)->orderBy('id')->firstOrFail();
        app(ReDraftService::class)->setTags($save, $protected->id, $target->id);

        app(ReDraftService::class)->run($save);

        $raw = DraftPick::where('save_id', $save->id)->where('brand', Brand::Raw)->where('skipped', false)->get();

        expect($protected->refresh()->brand)->toBe(Brand::Raw)
            ->and($target->refresh()->brand)->toBe(Brand::Raw)
            ->and($raw->where('pick_type', DraftPickType::Manual)->sole()->save_wrestler_id)->toBe($target->id)
            ->and($raw->where('pick_type', DraftPickType::Random)->count())->toBe(9);
    });

    it('skips picks for a brand already at 30', function () {
        $save = redraftSave(Brand::Raw, size: 16);

        foreach ([Brand::Raw, Brand::Smackdown] as $brand) {
            $have = SaveWrestler::where('save_id', $save->id)->where('brand', $brand)->count();

            foreach (SaveWrestler::where('save_id', $save->id)->whereNull('brand')->orderBy('id')->limit(30 - $have)->get() as $w) {
                app(ContractService::class)->signDrafted($save, $w, $brand, 1);
            }
        }

        app(ReDraftService::class)->run($save->refresh());

        expect(SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Raw)->count())->toBe(30)
            ->and(SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Smackdown)->count())->toBe(30)
            ->and(DraftPick::where('save_id', $save->id)->where('phase', DraftPhase::WrestlemaniaReset)->where('skipped', true)->count())->toBe(20);
    });

    it('voids a drafted briefcase holder’s Money in the Bank', function () {
        $save = redraftSave(Brand::Raw, size: 3);
        $briefcases = SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Smackdown)->where('id', '!=', GameBuilder::wrestler($save, 'Batista')->id)->get()
            ->map(fn ($w) => app(MitbService::class)->award($save, $w, 52));

        app(ReDraftService::class)->run($save);

        $briefcases->each(function ($b) {
            $b->refresh();
            expect($b->holder->brand === Brand::Smackdown ? MitbStatus::Held : MitbStatus::Voided)->toBe($b->status);
        });
    });

    it('renders the re-draft and PPV entrant screens', function () {
        $save = redraftSave();
        $this->actingAs($save->user)->get(route('gm.redraft.show', $save))->assertInertia(fn ($page) => $page->component('gm/redraft')->where('you_won', true));

        $rumble = ppvSave(RUMBLE_WEEK);
        $this->actingAs($rumble->user)->get(route('gm.ppv.entrants', [$rumble, interbrandShow($rumble, RUMBLE_WEEK)]))
            ->assertInertia(fn ($page) => $page->component('gm/ppv-entrants')->where('mode', 'rumble')->where('needed', 15));
    });
});
