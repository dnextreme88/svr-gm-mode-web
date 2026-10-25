<?php

use App\Enums\InjurySeverity;
use App\Enums\MatchMethod;
use App\Enums\NewsEventType;
use App\Models\MatchSlot;
use App\Models\NewsEvent;
use App\Models\Save;
use App\Services\Injuries\InjuryService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| Injured-win headline (house rule, 2026-10-25): a decisive win by a superstar
| who went in with a minor injury, and whose injury is still minor once the
| show is over, gets its own match result headline.
*/

function resultHeadline(MatchSlot $slot): string
{
    return (string) NewsEvent::where('type', NewsEventType::MatchResult)->where('dedupe_key', "slot:{$slot->id}")->value('rendered_text');
}

function hurt(Save $save, string $name): void
{
    app(InjuryService::class)->injure(GameBuilder::wrestler($save, $name), InjurySeverity::Minor, $save->current_week, $save->rng('test-injury', $save->current_week));
}

beforeEach(fn () => config(['gm.injuries.escalation_chance' => 0.0]));

it('praises a winner who went in with a minor injury', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    hurt($save, 'Edge');
    $slot = Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);
    FixedOutcomes::queue('Edge', MatchMethod::Pinfall);

    app(WeekResolver::class)->resolve($save->refresh());

    expect(resultHeadline($slot))->toBe('Handicapped and determined, Edge defeats Kane in a hard-hitting match via pinfall despite suffering a minor injury. Remember, an injury is not a hindrance to winning the match!');
});

it('keeps the usual headlines when the injured superstar loses or wins by DQ', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito', 'Chris Masters'], smackdown: ['Batista']);
    hurt($save, 'Edge');
    hurt($save, 'Carlito');
    $show = Card::show($save);
    $lost = Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kane']]);
    $dq = Card::book($save, $show, 2, Card::singles(), [['Carlito'], ['Chris Masters']]);
    FixedOutcomes::queue('Kane', MatchMethod::Pinfall);
    FixedOutcomes::queue('Carlito', MatchMethod::Dq);

    app(WeekResolver::class)->resolve($save->refresh());

    expect(resultHeadline($lost))->toStartWith('Kane defeats Edge in decisive fashion.')
        ->and(resultHeadline($dq))->toBe('Carlito defeats Chris Masters via disqualification. Not the cleanest win, but a win is a win.');
});

it('drops the injured-win headline when the injury turns serious later in the show', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
    hurt($save, 'Edge');
    GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 60]);
    $show = Card::show($save);
    $first = Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kane']]);
    // 70% going in plus a 5-bar match is 120%: always a serious injury.
    Card::book($save, $show, 2, Card::type('hell_in_a_cell', 'Single'), [['Edge'], ['Carlito']]);
    FixedOutcomes::queue('Edge', MatchMethod::Pinfall);

    app(WeekResolver::class)->resolve($save->refresh());

    expect(resultHeadline($first))->toBe('Edge defeats Kane in decisive fashion. He made sure to get the win via pinfall.');
});
