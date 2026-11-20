<?php

use App\Enums\Brand;
use App\Enums\NewsEventType;
use App\Enums\PromoTypeKey;
use App\Enums\ShowStatus;
use App\Enums\ShowType;
use App\Models\MatchSlot;
use App\Models\NewsEvent;
use App\Models\PromoSlot;
use App\Models\Rivalry;
use App\Models\Save;
use App\Models\Show;
use App\Services\Ai\AiGm;
use App\Services\Ai\AiPromoPlanner;
use App\Services\Ai\AiRivalryPlanner;
use App\Services\Rivalries\RivalryService;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| House rule (2026-11-20): the rival GM keeps its rivalries going (a 1v1 on
| each card, else a Continue Rivalry promo) and blows off a hot one (60+
| heat, 4+ matches together) at its next PPV: 60% a 1v1 on the PPV, else on
| the weekly show after it. The rivalry ends once that match airs. The player
| runs SmackDown, so the rival is Raw; Backlash (week 4) is Raw's own PPV.
*/

const FEUD_MEN = ['Big Show', 'Lance Cade', 'Trevor Murdoch', 'Carlito', 'Chris Masters', 'Edge', 'Eugene', 'The Hurricane', 'John Cena', 'Kane', 'Kurt Angle', 'Rene Dupree'];

function feudSave(): Save
{
    GameBuilder::passiveRival();
    config([
        'gm.ai.rivalries.rivalry_match_chance' => 1.0,
        'gm.ai.rivalries.continue_unpaired' => true,
        'gm.ai.rivalries.blowoff_min_heat' => 60,
        'gm.ai.rivalries.blowoff_min_matches' => 4,
    ]);

    return GameBuilder::inSeason(raw: FEUD_MEN, smackdown: ['Batista', 'Rey Mysterio'], player: Brand::Smackdown);
}

/** Edge vs Kane, started week 1, with $matches aired 1v1s before week 4. */
function feud(Save $save, int $heat, int $matches): Rivalry
{
    $rivalry = app(RivalryService::class)->start($save, GameBuilder::wrestler($save, 'Edge')->id, GameBuilder::wrestler($save, 'Kane')->id, 1);
    $rivalry->update(['heat' => $heat]);

    $shows = [];

    for ($i = 0; $i < $matches; $i++) {
        $show = $shows[] = Card::show($save, Brand::Raw, ShowType::Weekly, 1 + intdiv($i, 2));
        Card::book($save, $show, 1 + ($i % 2), Card::singles(), [['Edge'], ['Kane']]);
    }

    foreach ($shows as $show) {
        $show->update(['status' => ShowStatus::Aired]);
    }

    // Reset their fatigue so the rival can book them.
    GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 0]);
    GameBuilder::wrestler($save, 'Kane')->update(['fatigue' => 0]);

    return $rivalry->refresh();
}

/** Their 1v1 on the show, if any (a random multi-man fill that happens to hold both doesn't count). */
function sharesSlot(Show $show, Rivalry $rivalry): ?MatchSlot
{
    return MatchSlot::with('participants')->where('show_id', $show->id)->get()
        ->first(fn (MatchSlot $s): bool => count($s->competitorIds()) === 2 && in_array($rivalry->wrestler_a_id, $s->competitorIds(), true) && in_array($rivalry->wrestler_b_id, $s->competitorIds(), true));
}

it('books each of its rivalries 1v1 on its card', function () {
    $save = feudSave();
    $rivalries = app(RivalryService::class);
    $id = fn (string $n): int => GameBuilder::wrestler($save, $n)->id;
    $a = $rivalries->start($save, $id('Edge'), $id('Kane'), 1);
    $b = $rivalries->start($save, $id('Carlito'), $id('John Cena'), 1);

    $show = Card::show($save, Brand::Raw, ShowType::Weekly, 2);
    app(AiGm::class)->bookShow($show);

    expect(sharesSlot($show, $a))->not->toBeNull()
        ->and(sharesSlot($show, $b))->not->toBeNull();
});

it('gives a rivalry it couldn’t pair a Continue Rivalry promo', function () {
    $save = feudSave();
    $rivalry = app(RivalryService::class)->start($save, GameBuilder::wrestler($save, 'Edge')->id, GameBuilder::wrestler($save, 'Kane')->id, 1);
    $show = Card::show($save, Brand::Raw, ShowType::Weekly, 2);

    $planner = app(AiRivalryPlanner::class);
    $pairs = $planner->unpaired($show, Brand::Raw);
    expect($pairs)->toBe([[$rivalry->wrestler_a_id, $rivalry->wrestler_b_id]]);

    app(AiPromoPlanner::class)->book($show, Brand::Raw, 0, $pairs);

    $promo = PromoSlot::with('promoType')->where('show_id', $show->id)->get()
        ->first(fn (PromoSlot $p): bool => $p->promoType?->key === PromoTypeKey::ContinueRivalry);

    expect($promo)->not->toBeNull()
        ->and($promo->targetIds())->toEqualCanonicalizing([$rivalry->wrestler_a_id, $rivalry->wrestler_b_id]);
});

it('blows off a hot rivalry 1v1 at its next PPV on a 60 roll, and ends it once it airs', function () {
    $save = feudSave();
    config(['gm.ai.rivalries.blowoff_ppv_chance' => 1.0]);
    $rivalry = feud($save, 60, 4);

    $ppv = Card::show($save, Brand::Raw, ShowType::Ppv, 4);
    app(AiGm::class)->bookShow($ppv);

    $slot = sharesSlot($ppv, $rivalry);
    expect($slot)->not->toBeNull()
        ->and($slot->competitorIds())->toHaveCount(2)
        ->and($rivalry->refresh()->blowoff_slot_id)->toBe($slot->id);

    $ppv->update(['status' => ShowStatus::Aired]);
    app(RivalryService::class)->settleWeek($save, 4);

    expect($rivalry->refresh()->active)->toBeFalse()
        ->and($rivalry->ended_week)->toBe(4)
        ->and(NewsEvent::where('type', NewsEventType::RivalryConcluded)->exists())->toBeTrue();
});

it('blows it off on the weekly show after the PPV on a 40 roll', function () {
    $save = feudSave();
    config(['gm.ai.rivalries.blowoff_ppv_chance' => 0.0]);
    $rivalry = feud($save, 60, 4);

    $ppv = Card::show($save, Brand::Raw, ShowType::Ppv, 4);
    app(AiGm::class)->bookShow($ppv);
    expect(sharesSlot($ppv, $rivalry))->toBeNull()
        ->and($save->brand(Brand::Raw)->refresh()->ai_plans['blowoffs'][$rivalry->id]['at'] ?? null)->toBe('weekly');

    $weekly = Card::show($save, Brand::Raw, ShowType::Weekly, 4);
    app(AiGm::class)->bookShow($weekly);

    $slot = sharesSlot($weekly, $rivalry);
    expect($slot)->not->toBeNull()
        ->and($rivalry->refresh()->blowoff_slot_id)->toBe($slot->id);
});

it('doesn’t plan a blow-off below 60 heat or before 4 matches together', function (int $heat, int $matches) {
    $save = feudSave();
    config(['gm.ai.rivalries.blowoff_ppv_chance' => 1.0]);
    $rivalry = feud($save, $heat, $matches);

    app(AiGm::class)->bookShow(Card::show($save, Brand::Raw, ShowType::Ppv, 4));

    expect($rivalry->refresh()->blowoff_slot_id)->toBeNull()
        ->and($save->brand(Brand::Raw)->refresh()->ai_plans['blowoffs'] ?? [])->toBe([]);
})->with([
    'too cool' => [50, 4],
    'not enough matches' => [60, 3],
]);
