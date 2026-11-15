<?php

use App\Enums\Brand;
use App\Models\Contract;
use App\Models\DraftPick;
use App\Models\MatchSlot;
use App\Models\RoyalRumbleEntry;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Season;
use App\Models\Show;
use App\Services\Ai\AiGm;
use App\Services\Draft\DraftService;
use App\Services\Game\NewGameService;
use App\Services\Ppv\PpvCardService;
use App\Services\Roster\SuperstarCareer;
use App\Services\Week\ShowScheduler;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| House rules (2026-11-15): every Royal Rumble entrant's entry number,
| eliminations and result; the rival GM's Rumble entrants (non-champions
| first); and its initial draft picks signed for random lengths.
*/

const RECORD_RUMBLE_WEEK = 44;

function rumbleSave(int $size = 16): Save
{
    $save = GameBuilder::inSeason(size: $size);
    $save->update(['current_week' => RECORD_RUMBLE_WEEK]);
    app(ShowScheduler::class)->ensureWeek($save, RECORD_RUMBLE_WEEK);

    return $save->refresh();
}

function rumbleShow(Save $save): Show
{
    return Show::with('ppvEvent')->where('save_id', $save->id)->where('week_number', RECORD_RUMBLE_WEEK)->whereNull('brand')->firstOrFail();
}

it('records every entrant’s entry number and eliminations, the winner last', function () {
    $save = rumbleSave();
    $rumble = rumbleShow($save);
    $cards = app(PpvCardService::class);
    $cards->randomEntrants($rumble, Brand::Raw, $save->rng('test.entrants'));
    $winner = SaveWrestler::find(MatchSlot::where('show_id', $rumble->id)->sole()->participants()->first()->save_wrestler_id);
    FixedOutcomes::queue($winner->name());

    app(WeekResolver::class)->resolve($save->refresh());

    $entries = RoyalRumbleEntry::where('save_id', $save->id)->get()->keyBy('save_wrestler_id');
    $order = $entries->whereNotNull('elimination_order')->sortBy('elimination_order')->values();

    expect($entries)->toHaveCount(30)
        ->and($entries->pluck('entry_number')->sort()->values()->all())->toBe(range(1, 30))
        ->and($entries->where('won', true)->sole()->save_wrestler_id)->toBe($winner->id)
        ->and(Season::where('save_id', $save->id)->where('number', 1)->value('royal_rumble_winner_id'))->toBe($winner->id)
        ->and($entries->sum('eliminations'))->toBe(29)
        ->and($order)->toHaveCount(29)
        ->and($order->last()->eliminated_by_id)->toBe($winner->id)
        ->and($entries[$winner->id]->eliminated_by_id)->toBeNull();

    // Whoever throws someone out is in the ring: already entered, not yet out.
    foreach ($order as $victim) {
        $by = $entries[$victim->eliminated_by_id];

        expect($by->entry_number)->toBeLessThanOrEqual(max($victim->entry_number, $by->entry_number))
            ->and($by->won || $by->elimination_order > $victim->elimination_order)->toBeTrue();
    }

    $rows = app(SuperstarCareer::class)->rumbles($save, $winner);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['won'])->toBeTrue()
        ->and($rows[0]['entrants'])->toBe(30)
        ->and($rows[0]['entry_number'])->toBe($entries[$winner->id]->entry_number)
        ->and($rows[0]['ppv'])->toBe('Royal Rumble');
});

it('shows the Royal Rumble on the superstar profile', function () {
    $save = rumbleSave();
    app(PpvCardService::class)->randomEntrants(rumbleShow($save), Brand::Raw, $save->rng('test.entrants'));
    app(WeekResolver::class)->resolve($save->refresh());
    $entry = RoyalRumbleEntry::where('save_id', $save->id)->firstOrFail();

    $this->get(route('gm.rosters.show', [$save, $entry->save_wrestler_id]))
        ->assertInertia(fn ($page) => $page->has('royal_rumbles', 1)->where('royal_rumbles.0.entry_number', $entry->entry_number));
});

it('has the rival enter non-champions first', function () {
    $save = rumbleSave(size: 17);
    GameBuilder::giveTitle($save, 'world_heavyweight', [SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Smackdown)->orderBy('id')->first()->name()]);
    $champion = SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Smackdown)->orderBy('id')->first();
    $rumble = rumbleShow($save);

    app(PpvCardService::class)->aiEntrants($rumble, Brand::Smackdown, $save->rng('test.ai'));

    $entered = MatchSlot::where('show_id', $rumble->id)->sole()->participants()->pluck('save_wrestler_id')->all();

    expect($entered)->toHaveCount(15)->and($entered)->not->toContain($champion->id);
});

it('adds champions only when it’s short, unbooked ones before booked ones', function () {
    $save = rumbleSave(size: 16);
    $smackdown = SaveWrestler::with('wrestler')->where('save_id', $save->id)->where('brand', Brand::Smackdown)->orderBy('id')->get();
    // 16 superstars, 3 of them champions: 13 non-champions, so 2 champions go in.
    GameBuilder::giveTitle($save, 'world_heavyweight', [$smackdown[0]->name()]);
    GameBuilder::giveTitle($save, 'united_states', [$smackdown[1]->name()]);
    GameBuilder::giveTitle($save, 'cruiserweight', [$smackdown[2]->name()]);
    $ppv = Show::where('save_id', $save->id)->where('week_number', RECORD_RUMBLE_WEEK)->where('brand', Brand::Smackdown)->where('show_type', 'ppv')->firstOrFail();
    // The world champion is booked elsewhere on the Royal Rumble night.
    Card::book($save, $ppv, 1, Card::singles(), [[$smackdown[0]->name()], [$smackdown[5]->name()]]);
    $rumble = rumbleShow($save);

    app(PpvCardService::class)->aiEntrants($rumble, Brand::Smackdown, $save->rng('test.ai'));

    $entered = MatchSlot::where('show_id', $rumble->id)->sole()->participants()->pluck('save_wrestler_id')->all();

    expect($entered)->toHaveCount(15)
        ->and($entered)->toContain($smackdown[1]->id, $smackdown[2]->id)
        ->and($entered)->not->toContain($smackdown[0]->id);
});

it('signs the rival’s draft picks for random lengths', function () {
    GameBuilder::seedCatalog();
    $ai = app(AiGm::class);
    $save = app(NewGameService::class)->create('X', Brand::Raw, 'lengths');

    $lengths = collect(range(1, 30))->map(fn (int $n) => $ai->draftLength($save, Brand::Smackdown, $n))->unique();

    expect($lengths->count())->toBeGreaterThan(2);

    foreach (range(1, 6) as $i) {
        $save = app(NewGameService::class)->create('X', Brand::Raw, "lengths-{$i}");

        if ($save->draft_order[0] === 'smackdown') {
            $pick = app(DraftService::class)->aiStep($save)['pick'];
            $contract = Contract::where('save_wrestler_id', $pick->save_wrestler_id)->sole();

            expect($contract->length_weeks)->toBe($ai->draftLength($save, Brand::Smackdown, 1)->value)
                ->and(DraftPick::where('save_id', $save->id)->count())->toBe(1);

            return;
        }
    }

    $this->markTestSkipped('No seed had Smackdown pick first.');
});
