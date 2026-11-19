<?php

use App\Enums\Brand;
use App\Enums\FixedSlotKind;
use App\Exceptions\GameRuleException;
use App\Http\Presenters\CardPresenter;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Models\SaveWrestler;
use App\Models\Season;
use App\Models\Show;
use App\Services\Ppv\PpvCardService;
use App\Services\Week\BookingOrder;
use App\Services\Week\ShowScheduler;
use Tests\Support\GameBuilder;

/*
| House rules (2026-11-15): WrestleMania is 8 matches (the Rumble winner's
| brand books 1 and 3, the other brand 2 and 4, the series 5–7, then the
| main event). Each brand picks its own series entrants, and the brand with
| the main event picks its match type.
*/

const WM_TEST_WEEK = 52;

function wmCard(string $rumbleWinner = 'Edge', ?Brand $player = Brand::Raw): array
{
    $save = GameBuilder::inSeason(size: 16, player: $player);
    GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
    GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
    Season::where('save_id', $save->id)->update(['royal_rumble_winner_id' => GameBuilder::wrestler($save, $rumbleWinner)->id]);
    $save->update(['current_week' => WM_TEST_WEEK]);
    app(ShowScheduler::class)->ensureWeek($save, WM_TEST_WEEK);
    $show = Show::with('ppvEvent')->where('save_id', $save->id)->where('week_number', WM_TEST_WEEK)->whereNull('brand')->sole();

    return [$save->refresh(), $show];
}

function wmSlot(Show $show, int $position): MatchSlot
{
    return MatchSlot::with('participants.saveWrestler')->where('show_id', $show->id)->where('position', $position)->sole();
}

it('has the player pick their series entrants, refusing champions and doubles', function () {
    [$save, $show] = wmCard();
    $cards = app(PpvCardService::class);
    $options = $cards->seriesOptions($show, Brand::Raw);
    $mitb = $options[7];

    expect(array_keys($options))->toBe([5, 6, 7])
        ->and($mitb['current'])->toBe([null, null, null])
        ->and($mitb['eligible'])->not->toContain(GameBuilder::wrestler($save, 'John Cena')->id);

    $picks = array_slice($mitb['eligible'], 0, 3);
    $this->putJson(route('gm.card.series', [$save, $show, 7]), ['ids' => $picks])->assertNoContent();

    $raw = wmSlot($show, 7)->participants->filter(fn ($p) => $p->saveWrestler->brand === Brand::Raw);

    expect($raw->pluck('save_wrestler_id')->sort()->values()->all())->toBe(collect($picks)->sort()->values()->all())
        ->and($raw->pluck('side')->sort()->values()->all())->toBe([0, 1, 2])
        // The rival's three are still there.
        ->and(wmSlot($show, 7)->participants)->toHaveCount(6);

    expect(fn () => $cards->setSeriesEntrants($show, 5, Brand::Raw, [GameBuilder::wrestler($save, 'John Cena')->id]))->toThrow(GameRuleException::class, 'can’t be in this match')
        ->and(fn () => $cards->setSeriesEntrants($show, 5, Brand::Raw, [$picks[0]]))->toThrow(GameRuleException::class, 'can’t be in this match');
});

it('won’t air WrestleMania until every series spot is picked', function () {
    [$save, $show] = wmCard();

    expect(fn () => app(BookingOrder::class)->assertReadyToAir($save, WM_TEST_WEEK))->toThrow(GameRuleException::class, 'interbrand series');
});

it('lets the player pick the main event’s match type, one on one with the title on the line', function () {
    [$save, $show] = wmCard();
    $cards = app(PpvCardService::class);
    $types = $cards->mainEventTypes($show, Brand::Raw);
    $ladder = $types->first(fn (MatchType $t) => $t->family->value === 'ladder');

    expect(wmSlot($show, 8)->matchType->name)->toContain('Single')
        ->and($types->every(fn (MatchType $t) => $t->isOneOnOne() && $t->titles_allowed && ! $t->hasManagers()))->toBeTrue();

    $this->putJson(route('gm.card.main-event-type', [$save, $show]), ['match_type_id' => $ladder->id])->assertNoContent();

    $slot = wmSlot($show, 8);
    $tag = MatchType::where('sides', json_encode([2, 2]))->first() ?? MatchType::all()->first(fn (MatchType $t) => $t->isTwoOnTwo());

    expect($slot->match_type_id)->toBe($ladder->id)
        ->and($slot->fixed_kind)->toBe(FixedSlotKind::WrestlemaniaMainEvent)
        ->and($slot->participants)->toHaveCount(2)
        ->and(fn () => $cards->setMainEventType($show, Brand::Raw, $tag->id))->toThrow(GameRuleException::class, 'one-on-one');
});

it('has the rival pick a main event type that keeps both out of injury range', function () {
    // The player is Raw; Smackdown (the rival) wins the Rumble.
    $probe = GameBuilder::inSeason(size: 16);
    $name = SaveWrestler::with('wrestler')->where('save_id', $probe->id)->where('brand', Brand::Smackdown)->orderByDesc('id')->first()->name();
    [$save, $show] = wmCard($name);
    $slot = wmSlot($show, 8);
    $fatigue = $slot->participants->max(fn ($p) => $p->saveWrestler->fatigue);

    expect($slot->owner_brand)->toBe(Brand::Smackdown)
        ->and($fatigue + $slot->matchType->fatigueCost())->toBeLessThanOrEqual(70)
        ->and(fn () => app(PpvCardService::class)->setMainEventType($show, Brand::Raw, $slot->match_type_id))->toThrow(GameRuleException::class, 'Only the brand');
});

it('marks each series side with its brand and colours interbrand slots by owner', function () {
    [$save, $show] = wmCard();

    $this->get(route('gm.card.show', [$save, $show]))->assertInertia(fn ($page) => $page
        ->where('slots.6.side_brands', ['raw', 'raw', 'raw', 'smackdown', 'smackdown', 'smackdown'])
        ->where('slots.4.side_brands', ['raw', 'smackdown'])
        ->where('slots.0.owner_brand', 'raw')
        ->where('slots.1.owner_brand', 'smackdown')
        ->where('slots.0.side_brands', null)
        ->has('series.7')
        ->has('main_event_types'));
});

it('labels each superstar’s brand in the results of the series and the Survivor Series 5 vs 5', function () {
    [$save, $show] = wmCard();
    app(PpvCardService::class)->fillSeries($show, Brand::Raw, $save->rng('test.series'));
    $slot = MatchSlot::with(['participants.saveWrestler.wrestler', 'matchType'])->where('show_id', $show->id)->where('position', 5)->sole();
    $brands = CardPresenter::pending($slot, true, [])['brands'];
    $raw = $slot->participants->firstWhere('side', 0)->saveWrestler->wrestler->name;
    $smackdown = $slot->participants->firstWhere('side', 1)->saveWrestler->wrestler->name;

    expect($brands)->toBe([$raw => 'Raw', $smackdown => 'Smackdown'])
        ->and(CardPresenter::pending(wmSlot($show, 1)->load('matchType'), true, [])['brands'])->toBeNull();

    $survivor = new MatchSlot(['fixed_kind' => FixedSlotKind::SurvivorSeriesMainEvent, 'position' => 7]);

    expect(CardPresenter::sideBrand($survivor, 0))->toBe(Brand::Raw)
        ->and(CardPresenter::sideBrand($survivor, 1))->toBe(Brand::Smackdown);
});
