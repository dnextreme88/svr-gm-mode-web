<?php

use App\Enums\Brand;
use App\Enums\FinishEvent;
use App\Enums\MatchMethod;
use App\Enums\ShowType;
use App\Models\MatchResult;
use App\Models\MatchSlot;
use App\Models\Rivalry;
use App\Services\Card\Booking;
use App\Services\Card\CardService;
use App\Services\Matches\MatchResolver;
use App\Services\Rivalries\RivalryService;
use App\Services\Week\WeekResolver;
use App\Support\Rng\SeededRng;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| §20 Match Outcomes and §21 Match Specialties.
*/

function loadSlot(MatchSlot $slot): MatchSlot
{
    return $slot->fresh(['matchType', 'participants.saveWrestler.wrestler.specialties', 'show']);
}

it('never rolls DQ or count-out finishes where the match type disables them', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    config(['gm.outcomes.clean_finish_chance' => 0.0]);
    $slot = loadSlot(Card::book($save, Card::show($save), 1, Card::type('hardcore', 'Single'), [['Edge'], ['Kane']]));

    $events = collect(range(1, 300))->map(fn (int $i) => app(MatchResolver::class)->resolve($slot, new SeededRng("hc-{$i}"), collect()));

    expect($events->pluck('finishEvent')->filter()->map->value->unique()->sort()->values()->all())->toBe(['cheap_rollup', 'foreign_object'])
        ->and($events->pluck('method')->contains(MatchMethod::Dq))->toBeFalse()
        ->and($events->pluck('method')->contains(MatchMethod::Countout))->toBeFalse();
});

it('rolls every finish event a normal singles match allows, with matching methods', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
    config(['gm.outcomes.clean_finish_chance' => 0.0]);
    $slot = loadSlot(Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]));
    $rivalries = collect([
        app(RivalryService::class)->start($save, GameBuilder::wrestler($save, 'Carlito')->id, GameBuilder::wrestler($save, 'Edge')->id, 1),
        app(RivalryService::class)->start($save, GameBuilder::wrestler($save, 'Carlito')->id, GameBuilder::wrestler($save, 'Kane')->id, 1),
    ]);

    $results = collect(range(1, 400))->map(fn (int $i) => app(MatchResolver::class)->resolve($slot, new SeededRng("s-{$i}"), $rivalries));

    expect($results->pluck('finishEvent')->filter()->map->value->unique()->sort()->values()->all())
        ->toBe(['cheap_rollup', 'countout', 'foreign_object', 'referee_attacked', 'rival_interference']);

    $results->each(function ($r) {
        match ($r->finishEvent) {
            FinishEvent::RefereeAttacked => expect($r->method)->toBe(MatchMethod::Dq),
            FinishEvent::Countout => expect($r->method)->toBe(MatchMethod::Countout),
            FinishEvent::CheapRollup => expect($r->method)->toBe(MatchMethod::Pinfall),
            FinishEvent::RivalInterference => expect($r->interfererId)->not->toBeNull(),
            default => null,
        };
    });
});

it('only uses Rival Interference when the loser has an active rival outside the match', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
    config(['gm.outcomes.clean_finish_chance' => 0.0]);
    $slot = loadSlot(Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]));
    // A rivalry between the two competitors themselves never produces an interferer.
    $inside = collect([app(RivalryService::class)->start($save, GameBuilder::wrestler($save, 'Edge')->id, GameBuilder::wrestler($save, 'Kane')->id, 1)]);

    $events = collect(range(1, 200))->map(fn (int $i) => app(MatchResolver::class)->resolve($slot, new SeededRng("r-{$i}"), $inside)->finishEvent);
    $none = collect(range(1, 200))->map(fn (int $i) => app(MatchResolver::class)->resolve($slot, new SeededRng("n-{$i}"), collect())->finishEvent);

    expect($events->contains(FinishEvent::RivalInterference))->toBeFalse()
        ->and($none->contains(FinishEvent::RivalInterference))->toBeFalse();
});

it('uses the match type’s own win condition on clean finishes', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    config(['gm.outcomes.clean_finish_chance' => 1.0]);
    $slot = loadSlot(Card::book($save, Card::show($save), 1, Card::type('ladder', 'Single'), [['Edge'], ['Kane']]));

    $result = app(MatchResolver::class)->resolve($slot, new SeededRng('ladder'), collect());

    expect($result->method)->toBe(MatchMethod::MatchSpecific)
        ->and($result->summary)->toContain('briefcase retrieval');
});

it('produces the same results for the same seed', function () {
    $run = function (): array {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito', 'Kurt Angle'], smackdown: ['Batista'], seed: 'replay');
        $show = Card::show($save);
        Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kane']]);
        Card::book($save, $show, 2, Card::type('single', 'Normal Triple Threat'), [['Carlito'], ['Kurt Angle'], ['Edge']]);
        app(WeekResolver::class)->resolve($save->refresh());

        return MatchResult::whereIn('match_slot_id', $show->matchSlots()->pluck('id'))->orderBy('match_slot_id')
            ->get(['winner_side', 'method', 'finish_event', 'summary'])->toArray();
    };

    expect($run())->toBe($run());
});

it('updates wins, losses and streaks after the show', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);

    app(WeekResolver::class)->resolve($save->refresh());

    $edge = GameBuilder::wrestler($save, 'Edge');
    $kane = GameBuilder::wrestler($save, 'Kane');

    expect($edge->wins + $kane->wins)->toBe(1)
        ->and($edge->losses + $kane->losses)->toBe(1)
        ->and($edge->win_streak + $kane->win_streak)->toBe(1);
});

it('starts a rivalry after repeated singles meetings', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

    foreach ([1, 2] as $week) {
        Card::book($save->refresh(), Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);
        app(WeekResolver::class)->resolve($save->refresh());
    }

    expect(Rivalry::where('save_id', $save->id)->where('active', true)->count())->toBe(1);
});

describe('specialties (§21)', function () {
    it('gives a specialist a bonus win weight in their specialty', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        $slot = loadSlot(Card::book($save, Card::show($save), 1, Card::type('ladder', 'Single'), [['Edge'], ['Kane']]));

        expect(app(MatchResolver::class)->sideWeights($slot))->toBe([0 => 2.0, 1 => 1.0]);
    });

    it('cancels the bonus when both sides have the specialty', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Jeff Hardy'], smackdown: ['Batista']);
        $slot = loadSlot(Card::book($save, Card::show($save), 1, Card::type('tlc', 'Single'), [['Edge'], ['Jeff Hardy']]));

        expect(app(MatchResolver::class)->sideWeights($slot))->toBe([0 => 1.0, 1 => 1.0]);
    });

    it('applies once per specialist on tag teams', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Jeff Hardy', 'Kane', 'Big Show'], smackdown: ['Batista']);
        $slot = loadSlot(Card::book($save, Card::show($save), 1, Card::type('ladder', 'Tornado Tag'), [['Edge', 'Jeff Hardy'], ['Kane', 'Big Show']]));

        expect(app(MatchResolver::class)->sideWeights($slot))->toBe([0 => 3.0, 1 => 1.0]);
    });

    it('covers Money in the Bank as a Ladder variant', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Big Show', 'Carlito', 'Kurt Angle', 'Ric Flair'], smackdown: ['Batista']);
        $show = Card::show($save, Brand::Raw, ShowType::Weekly);
        $slot = MatchSlot::where('show_id', $show->id)->where('position', 1)->firstOrFail();
        $slot->update(['match_type_id' => Card::type('ladder', 'Money in the Bank')->id]);
        app(CardService::class)->writeParticipants($slot, new Booking(
            Card::type('ladder', 'Money in the Bank'),
            array_map(fn (string $n) => [GameBuilder::wrestler($save, $n)->id], ['Edge', 'Kane', 'Big Show', 'Carlito', 'Kurt Angle', 'Ric Flair']),
        ));

        expect(app(MatchResolver::class)->sideWeights(loadSlot($slot))[0])->toBe(2.0);
    });
});
