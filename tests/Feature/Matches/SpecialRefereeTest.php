<?php

use App\Enums\InjurySeverity;
use App\Enums\ParticipantRole;
use App\Exceptions\GameRuleException;
use App\Http\Presenters\CardPresenter;
use App\Models\MatchType;
use App\Services\Card\CardRandomizer;
use App\Services\Injuries\InjuryService;
use App\Services\Week\WeekResolver;
use App\Support\Rng\SeededRng;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| §10 Special Referee: bookable with a non-competing referee from the roster.
*/

function specialRef(string $variant = 'Single'): MatchType
{
    return Card::type('special_referee', $variant);
}

it('seeds every Special Referee type as GM-bookable on weekly shows', function () {
    GameBuilder::seedCatalog();

    $types = MatchType::where('family', 'special_referee')->get();

    expect($types)->toHaveCount(5)
        ->and($types->every(fn (MatchType $t): bool => $t->gm_bookable && ! $t->ppv_only))->toBeTrue();
});

it('stores the referee as a non-competing participant', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Ric Flair'], smackdown: ['Batista']);

    $slot = Card::book($save, Card::show($save), 1, specialRef(), [['Edge'], ['Kane']], referee: 'Ric Flair');

    $referee = $slot->participants->firstWhere('role', ParticipantRole::Referee);
    expect($referee?->save_wrestler_id)->toBe(GameBuilder::wrestler($save, 'Ric Flair')->id)
        ->and($slot->competitors())->toHaveCount(2)
        ->and(CardPresenter::slot($slot)['referee_id'])->toBe($referee?->save_wrestler_id);
});

it('requires a referee for Special Referee matches', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

    Card::book($save, Card::show($save), 1, specialRef(), [['Edge'], ['Kane']]);
})->throws(GameRuleException::class, 'needs a special referee');

it('rejects a referee on other match types', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Ric Flair'], smackdown: ['Batista']);

    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']], referee: 'Ric Flair');
})->throws(GameRuleException::class, 'has no special referee');

it('does not let a competitor referee their own match', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

    Card::book($save, Card::show($save), 1, specialRef(), [['Edge'], ['Kane']], referee: 'Kane');
})->throws(GameRuleException::class, 'can’t compete in or manage');

it('does not let a seriously injured wrestler referee', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Ric Flair'], smackdown: ['Batista']);
    app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Ric Flair'), InjurySeverity::Serious, 1, $save->rng('t'));

    Card::book($save, Card::show($save), 1, specialRef(), [['Edge'], ['Kane']], referee: 'Ric Flair');
})->throws(GameRuleException::class, 'seriously injured');

it('gives the referee no fatigue or win/loss record', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Ric Flair'], smackdown: ['Batista']);
    Card::book($save, Card::show($save), 1, specialRef(), [['Edge'], ['Kane']], referee: 'Ric Flair');
    FixedOutcomes::queue('Edge');

    app(WeekResolver::class)->resolve($save->refresh());

    $ref = GameBuilder::wrestler($save, 'Ric Flair');
    expect($ref->fatigue)->toBe(0)
        ->and($ref->wins + $ref->losses)->toBe(0)
        ->and(GameBuilder::wrestler($save, 'Edge')->wins)->toBe(1);
});

it('has the randomizer pick a referee who is not in the match', function () {
    $save = GameBuilder::inSeason(size: 8);
    $show = Card::show($save);

    foreach (range(1, 20) as $seed) {
        $proposal = app(CardRandomizer::class)->proposeSlot($show, 1, specialRef(), new SeededRng("ref{$seed}"));

        expect($proposal->booking->refereeId)->not->toBeNull()
            ->and($proposal->booking->competitorIds())->not->toContain($proposal->booking->refereeId);
    }
});
