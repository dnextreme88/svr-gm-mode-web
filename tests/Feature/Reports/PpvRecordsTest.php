<?php

use App\Enums\Brand;
use App\Enums\MatchMethod;
use App\Enums\ShowType;
use App\Models\MatchResult;
use App\Models\MatchSlot;
use App\Models\Show;
use App\Services\Ppv\PpvRecords;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| The Records book (house rule, 2026-11-21): PPV results only, both brands.
*/

function decide(MatchSlot $slot, int $winnerSide): void
{
    MatchResult::create(['match_slot_id' => $slot->id, 'winner_side' => $winnerSide, 'method' => MatchMethod::Pinfall, 'booked' => false, 'summary' => 'x', 'rng_seed_step' => 0]);
    $slot->show->update(['status' => 'aired']);
}

function ppvShow($save): Show
{
    // Backlash (Raw) is season week 4.
    return Card::show($save, Brand::Raw, ShowType::Ppv, 4);
}

it('ranks PPV main events, openers, wins and losses', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Batista', 'Triple H'], smackdown: ['Rey Mysterio']);
    $show = ppvShow($save);

    decide(Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kane']]), 0);
    decide(Card::book($save, $show, 2, Card::singles(), [['Edge'], ['Triple H']]), 0);

    $leaders = collect(app(PpvRecords::class)->build($save)['leaders'])->keyBy('key');
    $first = fn (string $key): array => $leaders[$key]['rows'][0];

    expect($first('main_events'))->toMatchArray(['name' => 'Edge', 'count' => 1])
        ->and(collect($leaders['main_events']['rows'])->pluck('name')->all())->toEqualCanonicalizing(['Edge', 'Triple H'])
        ->and(collect($leaders['openers']['rows'])->pluck('name')->all())->toEqualCanonicalizing(['Edge', 'Kane'])
        ->and($first('wins'))->toMatchArray(['name' => 'Edge', 'count' => 2])
        ->and(collect($leaders['losses']['rows'])->pluck('name')->all())->toEqualCanonicalizing(['Kane', 'Triple H']);
});

it('leaves weekly shows out', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Rey Mysterio']);
    decide(Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]), 0);

    $records = app(PpvRecords::class)->build($save);

    expect(collect($records['leaders'])->every(fn (array $b): bool => $b['rows'] === []))->toBeTrue();
});

it('puts the Records book on the Reports page', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Rey Mysterio']);
    decide(Card::book($save, ppvShow($save), 1, Card::singles(), [['Edge'], ['Kane']]), 0);

    $this->get(route('gm.reports', $save))
        ->assertInertia(fn (Assert $page) => $page
            ->has('records.leaders', 5)
            ->has('records.ppv_shows', 1)
            ->has('records.rumble_winners')
            ->has('records.mitb'));
});
