<?php

use App\Enums\FinishEvent;
use App\Enums\MatchMethod;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Services\Matches\FinishText;
use App\Services\Matches\MatchResolver;
use App\Support\Rng\SeededRng;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| Finish text (house rule, 2026-10-25): the summary follows the match type's
| win condition and names the one wrestler the finish happens to.
*/

function finishSlot(MatchSlot $slot): MatchSlot
{
    return $slot->fresh(['matchType', 'participants.saveWrestler.wrestler.specialties', 'show']);
}

/**
 * Only this finish event can roll (or a clean finish, with null).
 */
function onlyFinish(?FinishEvent $event): void
{
    config(['gm.outcomes.clean_finish_chance' => $event === null ? 1.0 : 0.0]);

    if ($event !== null) {
        config(['gm.outcomes.finish_event_weights' => [$event->value => 1]]);
    }
}

it('has finish text for every seeded win condition', function () {
    GameBuilder::seedCatalog();

    $conditions = MatchType::whereNotNull('win_condition')->distinct()->pluck('win_condition')->all();

    expect(array_diff($conditions, FinishText::conditions()))->toBe([]);
});

it('names who goes through the table in a Triple Threat', function () {
    $save = GameBuilder::inSeason(raw: ['Shelton Benjamin', 'Rob Van Dam', 'Kane'], smackdown: ['Batista']);
    onlyFinish(null);
    $slot = finishSlot(Card::book($save, Card::show($save), 1, Card::type('table', 'Triple Threat'), [['Shelton Benjamin'], ['Rob Van Dam'], ['Kane']]));

    $result = app(MatchResolver::class)->resolve($slot, new SeededRng('table'), collect(), 0);
    $loser = $result->loserSide === 1 ? 'Rob Van Dam' : 'Kane';

    expect($result->method)->toBe(MatchMethod::MatchSpecific)
        ->and($result->summary)->toBe("Shelton Benjamin puts {$loser} through a table.");
});

it('ends a weapon shot in a Table match through a table, not with a cover', function () {
    $save = GameBuilder::inSeason(raw: ['Shelton Benjamin', 'Edge'], smackdown: ['Batista']);
    onlyFinish(FinishEvent::ForeignObject);
    $slot = finishSlot(Card::book($save, Card::show($save), 1, Card::type('table', 'Single'), [['Shelton Benjamin'], ['Edge']]));

    $results = collect(range(1, 40))->map(fn (int $i) => app(MatchResolver::class)->resolve($slot, new SeededRng("fo-{$i}"), collect()));

    $results->each(fn ($r) => expect($r->method)->toBe(MatchMethod::MatchSpecific)
        ->and($r->summary)->toEndWith('and puts him through a table.')
        ->and($r->summary)->not->toContain('cover'));

    // Every weapon turns up.
    foreach (['steel chair', 'sledgehammer', 'ring bell', 'steel steps'] as $weapon) {
        expect($results->contains(fn ($r) => str_contains($r->summary, $weapon)))->toBeTrue();
    }
});

it('names one member of each team: the winner who scores it and the one it happens to', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito', 'Chris Masters'], smackdown: ['Batista']);
    onlyFinish(null);
    $slot = finishSlot(Card::book($save, Card::show($save), 1, Card::type('table', 'Tornado Tag'), [['Edge', 'Kane'], ['Carlito', 'Chris Masters']]));

    $summary = app(MatchResolver::class)->resolve($slot, new SeededRng('tag'), collect(), 0)->summary;

    expect($summary)->toMatch('/^(Edge|Kane) puts (Carlito|Chris Masters) through a table\.$/');
});

it('gives every winner the same odds of scoring the finish (house rule)', function () {
    $save = GameBuilder::inSeason(raw: ['Rob Conway', 'Rene Dupree', 'Tajiri', 'The Hurricane'], smackdown: ['Batista']);
    onlyFinish(null);
    $slot = finishSlot(Card::book($save, Card::show($save), 1, Card::type('handicap', 'One on Three (Tornado)'), [['The Hurricane'], ['Rob Conway', 'Rene Dupree', 'Tajiri']]));

    $scorers = collect(range(1, 300))
        ->map(fn (int $i) => app(MatchResolver::class)->resolve($slot, new SeededRng("score-{$i}"), collect(), 1)->summary)
        ->map(fn (string $summary) => collect(['Rob Conway', 'Rene Dupree', 'Tajiri'])->filter(fn (string $n) => str_contains($summary, $n))->sole())
        ->countBy();

    // Each of the three scores about a third of the time, and the finish
    // never credits the whole team.
    expect($scorers->keys()->sort()->values()->all())->toBe(['Rene Dupree', 'Rob Conway', 'Tajiri'])
        ->and($scorers->every(fn (int $n) => $n >= 70 && $n <= 130))->toBeTrue();
});

it('ends a Battle Royal with the last elimination', function () {
    $save = GameBuilder::inSeason(raw: ['Triple H', 'Big Show', 'Kane', 'Edge'], smackdown: ['Batista']);
    onlyFinish(null);
    $slot = finishSlot(Card::book($save, Card::show($save), 1, Card::type('battle_royal', '4-Man'), [['Triple H'], ['Big Show'], ['Kane'], ['Edge']]));

    $summary = app(MatchResolver::class)->resolve($slot, new SeededRng('royal'), collect(), 0)->summary;

    expect($summary)->toMatch('/^Triple H last eliminates (Big Show|Kane|Edge) via (pinfall|submission)\.$/');
});

it('ends a Last Man Standing match on the 10-count', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    onlyFinish(null);
    $slot = finishSlot(Card::book($save, Card::show($save), 1, Card::type('last_man_standing', 'Single'), [['Edge'], ['Kane']]));

    $summary = app(MatchResolver::class)->resolve($slot, new SeededRng('lms'), collect(), 1)->summary;

    expect($summary)->toBe("Kane pummels Edge so hard on the mat that he can't answer the 10-count.");
});

it('tells how long a buried superstar is gone', function () {
    $text = new FinishText('Batista', false, 'Kane', false, ['{weeks}' => (string) config('gm.buried_alive.sit_out_weeks')]);

    expect($text->clean('Buried alive'))->toBe('Batista buries Kane alive. He is nowhere to be seen (out for 4 weeks).')
        ->and($text->clause('Buried alive'))->toBe('buries him alive. He is nowhere to be seen (out for 4 weeks)');
});

it('uses plural verbs for a team and her for a Diva', function () {
    $text = new FinishText('Edge & Kane', true, 'Trish Stratus', true);

    expect($text->clean('Put through a table'))->toBe('Edge & Kane put Trish Stratus through a table.')
        ->and($text->clean('10-count'))->toBe("Edge & Kane pummel Trish Stratus so hard on the mat that she can't answer the 10-count.");
});

describe('Captain Fall', function () {
    function captainSlot(): MatchSlot
    {
        $save = GameBuilder::inSeason(raw: ['Tommy Dreamer', 'Kane', 'Kurt Angle', 'Christian', 'Rhino', 'Spike Dudley'], smackdown: ['Batista']);

        return finishSlot(Card::book($save, Card::show($save), 1, Card::type('six_man_tag', 'Captain Fall Tornado 6-Man Tag Elimination'), [
            ['Tommy Dreamer', 'Kane', 'Kurt Angle'],
            ['Christian', 'Rhino', 'Spike Dudley'],
        ]));
    }

    it('has one of the winners eliminate the other captain', function () {
        onlyFinish(null);

        expect(app(MatchResolver::class)->resolve(captainSlot(), new SeededRng('cf'), collect(), 0)->summary)
            ->toMatch('/^(Tommy Dreamer|Kane|Kurt Angle) (pins Christian, the captain,|makes Christian, the captain, tap out) to eliminate his team\.$/');
    });

    it('eliminates the team whose captain is disqualified', function () {
        onlyFinish(FinishEvent::RefereeAttacked);

        $result = app(MatchResolver::class)->resolve(captainSlot(), new SeededRng('cf-dq'), collect());
        [$winner, $loser] = $result->winnerSide === 0 ? ['Tommy Dreamer', 'Christian'] : ['Christian', 'Tommy Dreamer'];

        expect($result->method)->toBe(MatchMethod::Dq)
            ->and($result->summary)->toBe("{$winner}'s team wins because {$loser}, the captain of the opposing team, shoves the referee and is disqualified.");
    });

    it('eliminates the team whose captain is counted out', function () {
        onlyFinish(FinishEvent::Countout);

        expect(app(MatchResolver::class)->resolve(captainSlot(), new SeededRng('cf-co'), collect())->summary)
            ->toMatch("/^(Tommy Dreamer|Christian)'s team wins as (Christian|Tommy Dreamer), the captain of the opposing team, deliberately gets counted-out\\.$/");
    });

    it('names the weapon behind a captain’s DQ', function () {
        onlyFinish(FinishEvent::ForeignObject);

        expect(app(MatchResolver::class)->resolve(captainSlot(), new SeededRng('cf-fo'), collect())->summary)
            ->toMatch('/the captain of the opposing team, causes a DQ by using (a steel chair|a sledgehammer|the ring bell|the steel steps)\\.$/');
    });
});
