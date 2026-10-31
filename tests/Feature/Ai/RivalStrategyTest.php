<?php

use App\Enums\Brand;
use App\Enums\FixedSlotKind;
use App\Enums\PromoTypeKey;
use App\Enums\RosterType;
use App\Enums\TradeStatus;
use App\Models\MatchSlot;
use App\Models\PromoSlot;
use App\Models\Rivalry;
use App\Models\Save;
use App\Models\SaveBrand;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Ai\AiGm;
use App\Services\Ai\AiRivalryPlanner;
use App\Services\Calendar\CalendarService;
use App\Services\Contracts\ContractService;
use App\Services\Rivalries\RivalryService;
use App\Services\Roster\TradeService;
use App\Services\Titles\MitbService;
use App\Services\Week\WeekResolver;
use App\Services\Week\WeekSimulator;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| The rival GM's promos, rivalries, trades, briefcase and draft (house rules,
| gm.ai). The player runs SmackDown, so the rival is Raw.
*/

const STRATEGY_RAW = ['Big Show', 'Lance Cade', 'Trevor Murdoch', 'Carlito', 'Chris Masters', 'Edge', 'Eugene', 'The Hurricane', 'John Cena', 'Kane', 'Kurt Angle', 'Rene Dupree', 'Ric Flair', 'Rob Conway', 'Rob Van Dam', 'Shawn Michaels', 'Trish Stratus', 'Lita', 'Mickie James', 'Victoria'];

function strategySave(string $seed = GameBuilder::SEED): Save
{
    return GameBuilder::inSeason(raw: STRATEGY_RAW, smackdown: ['Batista', 'Rey Mysterio', 'Booker T', 'JBL'], player: Brand::Smackdown, seed: $seed);
}

function bookRival(Save $save): Show
{
    $show = Card::show($save, Brand::Raw);
    app(AiGm::class)->bookShow($show);

    return $show;
}

/**
 * @return list<PromoTypeKey>
 */
function rivalPromoKeys(Show $show): array
{
    return PromoSlot::with('promoType')->where('show_id', $show->id)->orderBy('position')->get()
        ->map(fn (PromoSlot $p): ?PromoTypeKey => $p->promoType?->key)
        ->filter()
        ->values()
        ->all();
}

/**
 * Weeks the rival ran a promo type, from week 1 to the save's current week.
 *
 * @return list<int>
 */
function rivalPromoWeeks(Save $save, PromoTypeKey $key): array
{
    return PromoSlot::query()
        ->join('shows', 'shows.id', '=', 'promo_slots.show_id')
        ->join('promo_types', 'promo_types.id', '=', 'promo_slots.promo_type_id')
        ->where('shows.save_id', $save->id)
        ->where('shows.brand', Brand::Raw)
        ->where('promo_types.key', $key)
        ->orderBy('shows.week_number')
        ->pluck('shows.week_number')
        ->map(fn ($w): int => (int) $w)
        ->all();
}

function startRivalry(Save $save, string $a, string $b, int $week = 1): Rivalry
{
    return app(RivalryService::class)->start($save, GameBuilder::wrestler($save, $a)->id, GameBuilder::wrestler($save, $b)->id, $week);
}

describe('promos', function () {
    it('hypes only a title that is on the line on the card', function () {
        GameBuilder::passiveRival();
        config(['gm.ai.promos.situational_weights' => ['title_match_hype' => 1], 'gm.ai.titles.main_event_chance.weekly' => 1.0, 'gm.ai.booking.careful_chance' => 1.0]);
        $save = strategySave();
        GameBuilder::giveTitle($save, 'wwe', ['Edge']);

        $show = bookRival($save);
        $hype = PromoSlot::with('promoType')->where('show_id', $show->id)->get()->first(fn (PromoSlot $p): bool => $p->promoType?->key === PromoTypeKey::TitleMatchHype);
        $onCard = MatchSlot::where('show_id', $show->id)->whereNotNull('title_championship_id')->pluck('title_championship_id')->all();

        expect($hype)->not->toBeNull()
            ->and($onCard)->toContain($hype->target_championship_id);

        // No champions, no title matches, no Title Match Hype.
        expect(rivalPromoKeys(bookRival(strategySave('no-titles'))))->not->toContain(PromoTypeKey::TitleMatchHype);
    });

    it('runs New Superstar only for a recent arrival', function () {
        // Keep Interference, Slander and Start a Rivalry from taking both slots.
        GameBuilder::passiveRival();
        config(['gm.ai.promos.situational_weights' => ['new_superstar' => 1]]);
        $save = strategySave();
        $save->update(['current_week' => 10]);
        SaveWrestler::where('save_id', $save->id)->update(['joined_brand_week' => 1]);
        GameBuilder::wrestler($save, 'Carlito')->update(['joined_brand_week' => 9]);

        $show = Card::show($save, Brand::Raw, week: 10);
        app(AiGm::class)->bookShow($show);
        $promo = PromoSlot::with('promoType')->where('show_id', $show->id)->get()->first(fn (PromoSlot $p): bool => $p->promoType?->key === PromoTypeKey::NewSuperstar);

        expect($promo?->target_wrestler_id)->toBe(GameBuilder::wrestler($save, 'Carlito')->id);
    });

    it('puts Advertising first when low on cash', function () {
        $save = strategySave();
        SaveBrand::where('save_id', $save->id)->where('brand', Brand::Raw)->update(['budget' => 100_000]);

        expect(rivalPromoKeys(bookRival($save)))->toBe([PromoTypeKey::Advertising, PromoTypeKey::MerchandiseSponsorship]);
    });

    it('spaces Interference 4 weeks apart, Slander by its rolled gaps, and PPV Hype 1–2 weeks before its own PPVs', function () {
        $save = strategySave('season-promos');
        $result = app(WeekSimulator::class)->simulateTo($save, 16);
        expect($result['stopped'])->toBeNull();

        $interference = rivalPromoWeeks($save, PromoTypeKey::InterferencePromotion);
        $slander = rivalPromoWeeks($save, PromoTypeKey::SlanderPromotion);
        $hype = rivalPromoWeeks($save, PromoTypeKey::PpvHype);

        expect($interference)->not->toBeEmpty()
            ->and($slander)->not->toBeEmpty()
            ->and($hype)->not->toBeEmpty();

        foreach (array_map(null, array_slice($interference, 0, -1), array_slice($interference, 1)) as [$a, $b]) {
            expect($b - $a)->toBe(4);
        }

        foreach (array_map(null, [0, ...array_slice($slander, 0, -1)], $slander) as [$a, $b]) {
            $gap = (int) $save->rng("ai.slander.raw.{$a}", 0)->weightedPick([3 => 0.1, 5 => 0.3, 6 => 0.6]);

            expect($gap)->toBeIn([3, 5, 6])
                ->and($b - $a)->toBeGreaterThanOrEqual($gap);
        }

        $calendar = app(CalendarService::class);

        foreach ($hype as $week) {
            $ahead = collect([1, 2])->filter(fn (int $n): bool => $calendar->ppvsForWeek($week + $n, Brand::Raw)->contains(fn ($p): bool => ! $p->isShared()));

            expect($ahead)->not->toBeEmpty();
        }
    });
});

describe('rivalries', function () {
    it('never starts a rivalry at the cap of 7', function () {
        $save = strategySave();

        foreach ([['Big Show', 'Kane'], ['Lance Cade', 'Trevor Murdoch'], ['Carlito', 'Chris Masters'], ['Edge', 'John Cena'], ['Eugene', 'The Hurricane'], ['Kurt Angle', 'Rene Dupree'], ['Trish Stratus', 'Lita']] as [$a, $b]) {
            startRivalry($save, $a, $b);
        }

        $show = bookRival($save);

        expect(rivalPromoKeys($show))->not->toContain(PromoTypeKey::StartRivalry)
            ->and(app(AiRivalryPlanner::class)->brandRivalries($show, Brand::Raw))->toHaveCount(7);
    });

    it('waits 2 weeks after dropping below 7 before starting another', function () {
        $save = strategySave();
        $save->update(['current_week' => 6]);

        foreach ([['Big Show', 'Kane'], ['Lance Cade', 'Trevor Murdoch'], ['Carlito', 'Chris Masters'], ['Edge', 'John Cena'], ['Eugene', 'The Hurricane'], ['Kurt Angle', 'Rene Dupree']] as [$a, $b]) {
            startRivalry($save, $a, $b);
        }

        $ended = startRivalry($save, 'Ric Flair', 'Rob Conway');
        $ended->update(['active' => false, 'ended_week' => 5]);
        $plan = fn (int $week): int => app(AiRivalryPlanner::class)->plan(Card::show($save, Brand::Raw, week: $week), Brand::Raw, [], true)['promo_starts'];

        expect($plan(6))->toBe(0);

        $save->update(['current_week' => 7]);

        expect($plan(7))->toBe(1);
    });

    it('makes at least 4 rivalries in a week that starts with none', function () {
        $save = strategySave();

        app(WeekResolver::class)->resolve($save->refresh());

        expect(app(AiRivalryPlanner::class)->brandRivalries(Card::show($save, Brand::Raw, week: 1), Brand::Raw)->count())->toBeGreaterThanOrEqual(4);

        $sameGender = Rivalry::with(['wrestlerA.wrestler', 'wrestlerB.wrestler'])->where('save_id', $save->id)->get()
            ->every(fn (Rivalry $r): bool => $r->wrestlerA->wrestler->isDiva() === $r->wrestlerB->wrestler->isDiva());

        expect($sameGender)->toBeTrue();
    });
});

describe('trades, briefcase and draft', function () {
    it('accepts a fair trade and declines a lopsided one', function () {
        config(['gm.ai.trades.fair_accept' => 1.0, 'gm.ai.trades.fair_ahead_accept' => [], 'gm.ai.trades.unfair_accept' => 0.0]);
        $save = strategySave();
        $trades = app(TradeService::class);
        $id = fn (string $name): int => GameBuilder::wrestler($save, $name)->id;

        $fair = $trades->propose($save, Brand::Smackdown, [$id('Booker T')], [$id('Eugene')]);
        $lopsided = $trades->propose($save->refresh(), Brand::Smackdown, [$id('JBL')], [$id('Edge'), $id('Kane'), $id('John Cena')]);

        expect($fair->status)->toBe(TradeStatus::Accepted)
            ->and($lopsided->status)->toBe(TradeStatus::Rejected);
    });

    it('cashes in Money in the Bank when the world champion is worn down', function () {
        $save = strategySave();
        GameBuilder::giveTitle($save, 'wwe', ['Edge']);
        GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 70]);
        app(MitbService::class)->award($save, GameBuilder::wrestler($save, 'Kane'), 1);

        $show = bookRival($save);

        expect(MatchSlot::where('show_id', $show->id)->where('fixed_kind', FixedSlotKind::MitbCashIn)->count())->toBe(1);
    });

    it('stops chasing its focus class after max_focus_picks', function () {
        config(['gm.draft.ai_focus_from' => 1, 'gm.ai.draft.teammate_chance' => 0]);

        $draft = function (int $cap): int {
            config(['gm.ai.draft.max_focus_picks' => $cap]);
            $save = GameBuilder::newSave(Brand::Smackdown, "draft-cap-{$cap}");

            foreach (range(1, 8) as $n) {
                $pick = app(AiGm::class)->draftPick($save, Brand::Raw, $n);
                app(ContractService::class)->signDrafted($save, $pick, Brand::Raw, 0);
            }

            return SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Raw)
                ->whereHas('wrestler', fn ($q) => $q->where('roster_type', RosterType::Diva))
                ->count();
        };

        expect($draft(100))->toBe(8)
            ->and($draft(3))->toBeLessThan(6);
    });
    it('drafts a teammate of someone it already has', function () {
        config(['gm.ai.draft.teammate_chance' => 100]);

        foreach (range(1, 5) as $seed) {
            $save = GameBuilder::newSave(Brand::Smackdown, "draft-mate-{$seed}");
            app(ContractService::class)->signDrafted($save, GameBuilder::wrestler($save, 'Jeff Hardy'), Brand::Raw, 0);

            $pick = app(AiGm::class)->draftPick($save->refresh(), Brand::Raw, 2);

            expect($pick->wrestler->name)->toBeIn(['Matt Hardy', 'Lita']);
        }
    });

    it('falls back to anyone when every teammate is taken', function () {
        config(['gm.ai.draft.teammate_chance' => 100]);
        $save = GameBuilder::newSave(Brand::Smackdown, 'draft-mate-taken');
        $contracts = app(ContractService::class);
        $contracts->signDrafted($save, GameBuilder::wrestler($save, 'Jeff Hardy'), Brand::Raw, 0);
        $contracts->signDrafted($save, GameBuilder::wrestler($save, 'Matt Hardy'), Brand::Smackdown, 0);
        $contracts->signDrafted($save, GameBuilder::wrestler($save, 'Lita'), Brand::Smackdown, 0);

        $pick = app(AiGm::class)->draftPick($save->refresh(), Brand::Raw, 2);

        expect($pick)->not->toBeNull()
            ->and($pick->brand)->toBeNull();
    });

    it('has no teammate to look for before its first pick', function () {
        config(['gm.ai.draft.teammate_chance' => 100, 'gm.draft.ai_focus_from' => 1]);
        $save = GameBuilder::newSave(Brand::Smackdown, 'draft-mate-first');

        $pick = app(AiGm::class)->draftPick($save, Brand::Raw, 1);

        expect($pick->wrestler->roster_type)->toBe(RosterType::Diva);
    });
});
