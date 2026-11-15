<?php

use App\Enums\Brand;
use App\Enums\DraftPhase;
use App\Enums\DraftPickType;
use App\Enums\EmailTemplate;
use App\Enums\FixedSlotKind;
use App\Enums\InjurySeverity;
use App\Enums\LedgerCategory;
use App\Enums\MitbStatus;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\RosterType;
use App\Enums\SaveStage;
use App\Enums\ShowType;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\DraftPick;
use App\Models\Email;
use App\Models\InterbrandSeries;
use App\Models\LedgerEntry;
use App\Models\MatchSlot;
use App\Models\MoneyInTheBank;
use App\Models\NewsEvent;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Season;
use App\Models\Show;
use App\Services\Contracts\ContractService;
use App\Services\Injuries\InjuryService;
use App\Services\News\NewsComposer;
use App\Services\News\NewsTemplates;
use App\Services\Ppv\PpvCardService;
use App\Services\Ppv\ReDraftService;
use App\Services\Titles\MitbService;
use App\Services\Week\ShowScheduler;
use App\Services\Week\WeekResolver;
use App\Support\Rng\SeededRng;
use App\Support\Text\StoryText;
use Illuminate\Support\Collection;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| §13 PPV calendar and §23 WrestleMania card.
*/

// Season 1 PPV weeks, matching gm.calendar.ppv_weeks (§13).
const RUMBLE_WEEK = 44;
const SURVIVOR_WEEK = 35;
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

    it('never picks a Diva as a random entrant and doesn’t count Divas toward the 15', function () {
        $save = ppvSave(RUMBLE_WEEK, size: 12);

        foreach (['Trish Stratus', 'Lita', 'Victoria'] as $diva) {
            app(ContractService::class)->signDrafted($save, GameBuilder::wrestler($save, $diva), Brand::Raw, RUMBLE_WEEK);
        }

        $rumble = interbrandShow($save, RUMBLE_WEEK);
        $cards = app(PpvCardService::class);

        expect($cards->rumbleEntrantsFor($rumble, Brand::Raw))->toBe(12);

        $cards->randomEntrants($rumble, Brand::Raw, $save->rng('test.entrants', RUMBLE_WEEK));

        $entrants = MatchSlot::with('participants.saveWrestler.wrestler')->where('show_id', $rumble->id)->sole()->participants;

        expect($entrants)->toHaveCount(12)
            ->and($entrants->filter(fn ($p): bool => $p->saveWrestler->wrestler->roster_type === RosterType::Diva))->toBeEmpty();
    });

    it('refuses a Diva as a Royal Rumble entrant', function () {
        $save = ppvSave(RUMBLE_WEEK);
        $trish = GameBuilder::wrestler($save, 'Trish Stratus');
        app(ContractService::class)->signDrafted($save, $trish, Brand::Raw, RUMBLE_WEEK);

        $ids = [...rosterIds($save, Brand::Raw, 14), $trish->id];

        expect(fn () => app(PpvCardService::class)->setEntrants(interbrandShow($save, RUMBLE_WEEK), Brand::Raw, $ids))
            ->toThrow(GameRuleException::class, 'Royal Rumble entrants are Superstars and Legends.');
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

    it('has Vince email both brands about the re-draft after WrestleMania', function () {
        $save = maniaSave();

        resolveWeek($save);

        $emails = Email::where('save_id', $save->id)->where('template', EmailTemplate::DraftReminder)->get();

        expect($emails)->toHaveCount(2)
            ->and($emails->pluck('sender')->unique()->all())->toBe(['Vince McMahon'])
            ->and($emails[0]->body)->toContain('5 superstars');
    });
});

describe('re-draft', function () {
    function redraftSave(Brand $winner = Brand::Raw, int $size = 16, ?Brand $immunity = null): Save
    {
        $save = GameBuilder::inSeason(size: $size);
        GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
        $save->update(['stage' => SaveStage::Redrafting, 'current_week' => 53, 'current_season' => 2]);
        Season::create(['save_id' => $save->id, 'number' => 2, 'started_week' => 53]);
        InterbrandSeries::create(['save_id' => $save->id, 'season' => 1, 'week_number' => 52, 'match_ids' => [], 'raw_wins' => $winner === Brand::Raw ? 2 : 1, 'smackdown_wins' => $winner === Brand::Raw ? 1 : 2, 'winner_brand' => $winner, 'immunity_brand' => $immunity ?? $winner]);

        return $save->refresh();
    }

    function redraftPicks(Save $save): Collection
    {
        return DraftPick::where('save_id', $save->id)->where('phase', DraftPhase::WrestlemaniaReset)->orderBy('pick_number')->get();
    }

    it('welcomes the player to the new season once the re-draft is done', function () {
        $save = redraftSave();

        app(ReDraftService::class)->run($save);

        $welcome = Email::where('save_id', $save->id)->where('template', EmailTemplate::Welcome)->sole();

        expect($welcome->brand)->toBe(Brand::Raw)
            ->and($welcome->sender)->toBe('JR')
            ->and($welcome->subject)->toBe('Season 2 starts now')
            ->and($save->refresh()->stage)->toBe(SaveStage::InSeason);
    });

    it('swaps 5 non-champions each way, alternating, keeping contracts and roster sizes (house rule, 2026-11-15)', function () {
        $save = redraftSave(Brand::Smackdown);
        $batista = GameBuilder::wrestler($save, 'Batista');
        $sizes = [Brand::Raw->value => SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Raw)->count(), Brand::Smackdown->value => SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Smackdown)->count()];
        $contracts = SaveWrestler::where('save_id', $save->id)->whereNotNull('brand')->with('activeContract')->get()->mapWithKeys(fn ($w) => [$w->id => $w->activeContract->id]);

        app(ReDraftService::class)->run($save);

        $picks = redraftPicks($save);

        expect($picks)->toHaveCount(10)
            ->and($picks->where('skipped', true))->toBeEmpty()
            ->and($picks->pluck('brand')->map->value->all())->toBe(['smackdown', 'raw', 'smackdown', 'raw', 'smackdown', 'raw', 'smackdown', 'raw', 'smackdown', 'raw'])
            ->and($picks->pluck('save_wrestler_id')->unique())->toHaveCount(10)
            ->and($picks->pluck('save_wrestler_id')->contains($batista->id))->toBeFalse()
            ->and($batista->refresh()->brand)->toBe(Brand::Smackdown)
            ->and(SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Raw)->count())->toBe($sizes['raw'])
            ->and(SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Smackdown)->count())->toBe($sizes['smackdown']);

        $picks->each(function (DraftPick $p) use ($contracts) {
            $w = SaveWrestler::with('activeContract')->find($p->save_wrestler_id);
            expect($w->brand)->toBe($p->brand)->and($w->activeContract->id)->toBe($contracts[$w->id]);
        });
    });

    it('swaps even when both rosters are full', function () {
        $save = redraftSave(Brand::Raw, size: 16);

        foreach ([Brand::Raw, Brand::Smackdown] as $brand) {
            $have = SaveWrestler::where('save_id', $save->id)->where('brand', $brand)->count();

            foreach (SaveWrestler::where('save_id', $save->id)->whereNull('brand')->orderBy('id')->limit(30 - $have)->get() as $w) {
                app(ContractService::class)->signDrafted($save, $w, $brand, 1);
            }
        }

        app(ReDraftService::class)->run($save->refresh());

        expect(redraftPicks($save)->where('skipped', false))->toHaveCount(10)
            ->and(SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Raw)->count())->toBe(30)
            ->and(SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Smackdown)->count())->toBe(30);
    });

    it('gives the immunity tag to the brand that drew more fans before WrestleMania, and the manual pick to the series winner', function () {
        $save = redraftSave(Brand::Raw);
        InterbrandSeries::where('save_id', $save->id)->update(['immunity_brand' => null]);

        foreach ([1, 2] as $week) {
            app(ShowScheduler::class)->ensureWeek($save, $week);
            Show::where('save_id', $save->id)->where('week_number', $week)->where('brand', 'smackdown')->where('show_type', ShowType::Weekly)->update(['attendance' => 12_000]);
            Show::where('save_id', $save->id)->where('week_number', $week)->where('brand', 'raw')->where('show_type', ShowType::Weekly)->update(['attendance' => 9_000]);
        }

        $series = app(ReDraftService::class)->prepare($save);

        expect($series->immunity_brand)->toBe(Brand::Smackdown)
            ->and($series->winner_brand)->toBe(Brand::Raw)
            // Smackdown is the rival here: it protects its own at once. Raw (the
            // player) still has to make its manual pick.
            ->and($series->exemption_wrestler_id)->not->toBeNull()
            ->and(SaveWrestler::find($series->exemption_wrestler_id)->brand)->toBe(Brand::Smackdown)
            ->and(app(ReDraftService::class)->phase($save, $series))->toBe('manual');
    });

    it('takes the immunity tag first, then the manual pick, which is the first pick', function () {
        $save = redraftSave(Brand::Raw);
        $service = app(ReDraftService::class);
        $protected = GameBuilder::wrestler($save, 'Edge');
        $target = SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Smackdown)->where('id', '!=', GameBuilder::wrestler($save, 'Batista')->id)->orderBy('id')->firstOrFail();

        expect($service->phase($save, $service->prepare($save)))->toBe('immunity')
            ->and(fn () => $service->setHandpick($save, $target->id))->toThrow(GameRuleException::class, 'immunity tag comes first');

        $service->setImmunity($save, $protected->id);
        expect($service->phase($save, $service->series($save)))->toBe('manual');

        $service->setHandpick($save, $target->id);
        expect($service->phase($save, $service->series($save)))->toBe('picks');

        while ($service->step($save) !== null) {
            // every pick
        }

        $first = redraftPicks($save)->first();

        expect($first->pick_type)->toBe(DraftPickType::Manual)
            ->and($first->save_wrestler_id)->toBe($target->id)
            ->and($first->brand)->toBe(Brand::Raw)
            ->and($protected->refresh()->brand)->toBe(Brand::Raw)
            ->and(redraftPicks($save)->where('pick_type', DraftPickType::Manual))->toHaveCount(1);
    });

    it('refuses a champion for the manual pick', function () {
        $save = redraftSave(Brand::Raw);
        $service = app(ReDraftService::class);
        $service->setImmunity($save, GameBuilder::wrestler($save, 'Edge')->id);

        $service->setHandpick($save, GameBuilder::wrestler($save, 'Batista')->id);
    })->throws(GameRuleException::class, 'non-champions');

    it('refuses a champion for the immunity tag', function () {
        $save = redraftSave(Brand::Raw);
        $champion = GameBuilder::wrestler($save, 'Edge');
        GameBuilder::giveTitle($save, 'intercontinental', ['Edge']);

        app(ReDraftService::class)->setImmunity($save, $champion->id);
    })->throws(GameRuleException::class, 'non-champions');

    it('exempts every champion, singles and tag, while injured non-champions can still be drafted', function () {
        $save = GameBuilder::inSeason(
            raw: ['John Cena', 'Kane', 'Christian', 'Edge', 'Carlito', 'Shelton Benjamin', 'Chris Masters', 'Tajiri'],
            smackdown: ['Batista', 'Paul London', 'Brian Kendrick', 'Randy Orton', 'Chris Benoit', 'Booker T', 'JBL', 'Orlando Jordan', 'Hardcore Holly'],
        );
        $champions = collect([
            GameBuilder::giveTitle($save, 'wwe', ['John Cena']),
            GameBuilder::giveTitle($save, 'world_tag_team', ['Kane', 'Christian']),
            GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']),
            GameBuilder::giveTitle($save, 'wwe_tag_team', ['Paul London', 'Brian Kendrick']),
        ])->flatMap(fn ($reign) => $reign->holderIds())->all();
        $injuries = app(InjuryService::class);
        $minor = GameBuilder::wrestler($save, 'Carlito');
        $serious = GameBuilder::wrestler($save, 'Tajiri');
        $injuries->injure($minor, InjurySeverity::Minor, 50, $save->rng('t', 1));
        $injuries->injure($serious, InjurySeverity::Serious, 50, $save->rng('t', 2));
        $save->update(['stage' => SaveStage::Redrafting, 'current_week' => 53, 'current_season' => 2]);
        Season::create(['save_id' => $save->id, 'number' => 2, 'started_week' => 53]);
        // The rival (Smackdown) holds both tags, so it uses them itself.
        InterbrandSeries::create(['save_id' => $save->id, 'season' => 1, 'week_number' => 52, 'match_ids' => [], 'raw_wins' => 1, 'smackdown_wins' => 2, 'winner_brand' => Brand::Smackdown, 'immunity_brand' => Brand::Smackdown]);
        $service = app(ReDraftService::class);
        $rawNonChampions = $service->eligibleFrom($save->refresh(), Brand::Raw)->pluck('id')->sort()->values()->all();

        expect($service->eligibleFrom($save, Brand::Raw)->pluck('id'))->toContain($minor->id, $serious->id)
            ->and(array_intersect($rawNonChampions, $champions))->toBeEmpty();

        $series = $service->prepare($save);

        expect($champions)->not->toContain($series->exemption_wrestler_id)
            ->and($champions)->not->toContain($series->handpick_wrestler_id)
            ->and(SaveWrestler::find($series->exemption_wrestler_id)->brand)->toBe(Brand::Smackdown)
            ->and(SaveWrestler::find($series->handpick_wrestler_id)->brand)->toBe(Brand::Raw);

        $service->run($save->refresh());

        $picks = redraftPicks($save)->where('skipped', false);

        // Raw has exactly 5 non-champions, so Smackdown drafts every one of
        // them, the injured two included.
        expect($picks->where('brand', Brand::Smackdown)->pluck('save_wrestler_id')->sort()->values()->all())->toBe($rawNonChampions)
            ->and($picks->pluck('save_wrestler_id')->intersect($champions))->toBeEmpty()
            ->and($picks->pluck('save_wrestler_id'))->not->toContain($series->exemption_wrestler_id)
            ->and($minor->refresh()->brand)->toBe(Brand::Smackdown)
            ->and($serious->refresh()->brand)->toBe(Brand::Smackdown);

        foreach ($champions as $id) {
            expect(SaveWrestler::find($id)->brand)->toBe(in_array($id, [GameBuilder::wrestler($save, 'John Cena')->id, GameBuilder::wrestler($save, 'Kane')->id, GameBuilder::wrestler($save, 'Christian')->id], true) ? Brand::Raw : Brand::Smackdown);
        }
    });

    it('lets a rival GM tag its most valuable superstars (house rule, 2026-11-11)', function () {
        $save = redraftSave(Brand::Smackdown);
        $star = GameBuilder::wrestler($save, 'Edge');
        $star->update(['win_streak' => 20]);
        $own = SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Smackdown)->where('id', '!=', GameBuilder::wrestler($save, 'Batista')->id)->orderBy('id')->firstOrFail();
        $own->update(['win_streak' => 20]);

        $series = app(ReDraftService::class)->prepare($save);

        expect($series->handpick_wrestler_id)->toBe($star->id)
            ->and($series->exemption_wrestler_id)->toBe($own->id);
    });

    it('breaks a tie for the most valuable superstar with the RNG', function () {
        $save = redraftSave(Brand::Smackdown);
        $service = app(ReDraftService::class);
        $pool = $service->eligibleFrom($save, Brand::Raw);

        $picked = collect(range(1, 20))->map(fn (int $i) => $service->mostValuable($save, Brand::Smackdown, $pool, new SeededRng("tie-{$i}"))?->id)->unique();

        expect($picked->count())->toBeGreaterThan(1);
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

    it('writes the WrestleMania week WWE headline: picks, tags and series, then the close', function () {
        $save = redraftSave(Brand::Raw);

        app(ReDraftService::class)->run($save);

        $event = NewsEvent::where('save_id', $save->id)->where('type', NewsEventType::RedraftResults)->sole();
        $summary = app(NewsTemplates::class)->summary(NewsEventType::RedraftResults, $event->payload);
        $raw = redraftPicks($save)->where('brand', Brand::Raw)->map(fn ($p) => $p->saveWrestler->name())->values()->all();

        expect($event->week_number)->toBe(52)
            ->and($event->tier)->toBe(NewsTier::Headline)
            ->and(StoryText::paragraphs($summary))->toHaveCount(3)
            ->and(StoryText::fits($summary))->toBeTrue()
            ->and($summary)->toContain('Season 1 re-draft')
            ->and($summary)->toContain('Season 2 starts now')
            ->and($summary)->toContain(...$raw)
            ->and(NewsEvent::where('save_id', $save->id)->where('type', NewsEventType::RedraftPick)->pluck('tier')->unique()->values()->all())->toBe([NewsTier::Ticker]);
    });

    it('puts the re-draft first and each brand’s last WrestleMania match on its card', function () {
        $save = maniaSave();
        resolveWeek($save);
        $service = app(ReDraftService::class);

        $service->run($save->refresh());

        $page = app(NewsComposer::class)->compose($save->refresh(), MANIA_WEEK);
        $main = MatchSlot::where('show_id', interbrandShow($save, MANIA_WEEK)->id)->where('position', 10)->first();
        $mainBrand = $main->owner_brand->value;

        expect($page['headlines']['neutral']['type'])->toBe('redraft_results')
            ->and($page['headlines'][$mainBrand])->not->toBeNull()
            ->and(NewsEvent::find($page['headlines'][$mainBrand]['id'])->dedupe_key)->toBe("slot:{$main->id}");
    });

    it('sends the News to the re-draft while it runs, and finishes into the news', function () {
        $save = redraftSave(Brand::Smackdown, immunity: Brand::Smackdown);

        $this->get(route('gm.news', $save))->assertRedirect(route('gm.redraft.show', $save));
        $this->get(route('gm.redraft.show', $save))->assertInertia(fn ($page) => $page->component('gm/redraft')
            ->where('phase', 'picks')
            ->where('per_brand', 5)
            ->has('order', 10)
            ->where('order.0', 'smackdown'));

        $step = $this->postJson(route('gm.redraft.step', $save))->assertOk()->json();
        expect($step['pick']['pick_number'])->toBe(1)
            ->and($step['pick']['pick_type'])->toBe('manual')
            ->and($step['done'])->toBeFalse();

        while (! $this->postJson(route('gm.redraft.step', $save))->json('done')) {
            // every pick
        }

        $this->post(route('gm.redraft.finish', $save))->assertRedirect(route('gm.news', $save));
        expect($save->refresh()->stage)->toBe(SaveStage::InSeason);
    });
});
