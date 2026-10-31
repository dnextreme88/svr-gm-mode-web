<?php

use App\Enums\Brand;
use App\Enums\Stipulation;
use App\Models\MatchSlot;
use App\Models\Save;
use App\Models\Show;
use App\Services\Ai\AiGm;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| House rule (2026-11-01): the rival GM books Winner Takes All and #1
| Contender's Match (gm.ai.titles). The player runs SmackDown, so the rival
| is Raw.
*/

const STIP_RAW = ['Big Show', 'Carlito', 'Chris Masters', 'Edge', 'Eugene', 'The Hurricane', 'John Cena', 'Kane', 'Kurt Angle', 'Rene Dupree', 'Rob Conway', 'Rob Van Dam', 'Shawn Michaels', 'Trish Stratus', 'Lita', 'Mickie James', 'Victoria'];

function stipSave(): Save
{
    $save = GameBuilder::inSeason(raw: STIP_RAW, smackdown: ['Batista', 'Rey Mysterio', 'Booker T', 'JBL'], player: Brand::Smackdown);
    GameBuilder::passiveRival();

    // Only the roll under test fires; every match is careful.
    config([
        'gm.ai.booking.careful_chance' => 1.0,
        'gm.ai.booking.intergender_chance' => 0.0,
        'gm.ai.titles.main_event_chance' => ['weekly' => 0.0, 'ppv' => 0.0],
        'gm.ai.titles.other_slot_chance' => 0.0,
        'gm.ai.titles.champion_vs_contender_chance' => 0.0,
        'gm.ai.titles.number_one_contender_chance' => 0.0,
        'gm.ai.titles.winner_takes_all_chance' => ['weekly' => 0.0, 'ppv' => 0.0],
    ]);

    return $save;
}

function bookRivalCard(Save $save): Show
{
    $show = Card::show($save, Brand::Raw);
    app(AiGm::class)->bookShow($show);

    return $show;
}

/**
 * @return list<string>
 */
function competitorNames(MatchSlot $slot): array
{
    return $slot->competitors()->map(fn ($p): string => $p->saveWrestler->wrestler->name)->sort()->values()->all();
}

it('books a Winner Takes All between its singles champions when the roll hits', function () {
    $save = stipSave();
    config(['gm.ai.titles.winner_takes_all_chance.weekly' => 1.0, 'gm.ai.titles.winner_takes_all_triple_chance' => 0.0]);
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    GameBuilder::giveTitle($save, 'intercontinental', ['Carlito']);

    $show = bookRivalCard($save);
    $slot = MatchSlot::with('participants.saveWrestler.wrestler')->where('show_id', $show->id)->where('stipulation', Stipulation::WinnerTakesAll)->first();
    $mainEvent = MatchSlot::where('show_id', $show->id)->max('position');

    expect($slot)->not->toBeNull()
        ->and(competitorNames($slot))->toBe(['Carlito', 'Edge'])
        ->and($slot->position)->not->toBe($mainEvent)
        ->and($slot->title_championship_id)->toBeNull();
});

it('skips Winner Takes All with fewer than two singles champions', function () {
    $save = stipSave();
    config(['gm.ai.titles.winner_takes_all_chance.weekly' => 1.0]);
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    GameBuilder::giveTitle($save, 'womens', ['Trish Stratus']);

    $show = bookRivalCard($save);

    expect(MatchSlot::where('show_id', $show->id)->where('stipulation', Stipulation::WinnerTakesAll)->exists())->toBeFalse();
});

it('turns a fitting non-title match into a #1 Contender’s Match when the roll hits', function () {
    $save = stipSave();
    config(['gm.ai.titles.number_one_contender_chance' => 1.0]);
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    GameBuilder::giveTitle($save, 'womens', ['Victoria']);

    // Every card is drawn from its own stream: over a few weeks one fits.
    $slots = collect(range(1, 4))->flatMap(fn (int $week) => MatchSlot::with('participants.saveWrestler.wrestler')
        ->where('show_id', tap(Card::show($save, Brand::Raw, week: $week), fn (Show $show) => app(AiGm::class)->bookShow($show))->id)
        ->where('stipulation', Stipulation::NumberOneContender)
        ->get());

    expect($slots)->not->toBeEmpty();

    foreach ($slots as $slot) {
        expect($slot->stipulation_championship_id)->not->toBeNull()
            ->and($slot->title_championship_id)->toBeNull()
            ->and(competitorNames($slot))->not->toContain('Edge')
            ->and(competitorNames($slot))->not->toContain('Victoria');
    }

    // One #1 Contender's Match per title per card.
    expect($slots->groupBy('show_id')->every(fn ($onCard) => $onCard->pluck('stipulation_championship_id')->duplicates()->isEmpty()))->toBeTrue();
});

it('books no #1 Contender’s Match when the roll misses', function () {
    $save = stipSave();
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);

    $show = bookRivalCard($save);

    expect(MatchSlot::where('show_id', $show->id)->where('stipulation', Stipulation::NumberOneContender)->exists())->toBeFalse();
});
