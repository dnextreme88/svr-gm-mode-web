<?php

use App\Enums\MitbCashInTiming;
use App\Enums\MitbStatus;
use App\Exceptions\GameRuleException;
use App\Models\MatchSlot;
use App\Models\MoneyInTheBank;
use App\Models\Save;
use App\Services\Titles\MitbService;
use App\Services\Titles\TitleService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| Money in the Bank during and after a match (house rule, 2026-10-31): the
| holder joins a world title match (it grows by one wrestler), or a new
| title match goes on right after one.
*/

/**
 * Raw: Edge is WWE Champion, Carlito holds the briefcase. Match 1 is the
 * world title match, match 2 a plain singles match.
 *
 * @return array{0: Save, 1: MatchSlot, 2: MatchSlot}
 */
function cashInWeek(string $titleType = 'Single'): array
{
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito', 'Shelton Benjamin', 'Big Show'], smackdown: ['Batista', 'JBL']);
    $reign = GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    app(MitbService::class)->award($save, GameBuilder::wrestler($save, 'Carlito'), 1);
    $show = Card::show($save);
    $sides = $titleType === 'Triple Threat' ? [['Edge'], ['Kane'], ['Big Show']] : [['Edge'], ['Kane']];
    $title = Card::book($save, $show, 1, Card::type('hardcore', $titleType), $sides, [], $reign->championship_id);
    $other = Card::book($save, $show, 2, Card::singles(), [['Shelton Benjamin'], ['Big Show']]);

    return [$save, $title, $other];
}

function briefcase(Save $save): MoneyInTheBank
{
    return MoneyInTheBank::where('save_id', $save->id)->sole();
}

describe('during a world title match', function () {
    it('adds the holder and grows a Hardcore Single into a Hardcore Triple Threat', function () {
        [$save, $title] = cashInWeek();
        app(WeekResolver::class)->begin($save);

        app(WeekResolver::class)->cashInDuring($save, $title->id);
        $title->refresh();

        expect($title->matchType->variant)->toBe('Triple Threat')
            ->and($title->matchType->family->value)->toBe('hardcore')
            ->and($title->mitb_cash_in)->toBe(MitbCashInTiming::During)
            ->and($title->competitorIds())->toContain(GameBuilder::wrestler($save, 'Carlito')->id)
            ->and(briefcase($save)->cash_in_match_id)->toBe($title->id);
    });

    it('grows a Triple Threat into a Fatal 4-Way', function () {
        [$save, $title] = cashInWeek('Triple Threat');
        app(WeekResolver::class)->begin($save);

        app(WeekResolver::class)->cashInDuring($save, $title->id);

        expect($title->refresh()->matchType->variant)->toBe('Fatal 4-Way');
    });

    it('wins the title if the holder wins, and is spent either way', function () {
        [$save, $title] = cashInWeek();
        app(WeekResolver::class)->begin($save);
        app(WeekResolver::class)->cashInDuring($save, $title->id);

        app(WeekResolver::class)->decide($save, $title->id, 2);

        expect(app(TitleService::class)->titlesHeldBy(GameBuilder::wrestler($save, 'Carlito')))->toBe(['WWE Championship'])
            ->and(briefcase($save)->status)->toBe(MitbStatus::CashedInWon);
    });

    it('is lost when someone else wins, even if the title changes hands', function () {
        [$save, $title] = cashInWeek();
        app(WeekResolver::class)->begin($save);
        app(WeekResolver::class)->cashInDuring($save, $title->id);

        app(WeekResolver::class)->decide($save, $title->id, 1);

        expect(app(TitleService::class)->titlesHeldBy(GameBuilder::wrestler($save, 'Kane')))->toBe(['WWE Championship'])
            ->and(briefcase($save)->status)->toBe(MitbStatus::CashedInLost);
    });

    it('is only for world title matches', function () {
        [$save, , $other] = cashInWeek();
        app(WeekResolver::class)->begin($save);
        $first = MatchSlot::where('show_id', $other->show_id)->where('position', 1)->firstOrFail();
        app(WeekResolver::class)->decide($save, $first->id, 0);
        app(WeekResolver::class)->answerCashIn($save, false);

        expect(fn () => app(WeekResolver::class)->cashInDuring($save, $other->id))
            ->toThrow(GameRuleException::class, 'can’t be cashed in');
    });
});

describe('after a world title match', function () {
    it('waits for an answer, then puts the cash-in match on right after it', function () {
        [$save, $title, $other] = cashInWeek();
        app(WeekResolver::class)->begin($save);

        app(WeekResolver::class)->decide($save, $title->id, 0);

        expect(app(WeekResolver::class)->cashInOffer($save)?->id)->toBe($title->id)
            ->and(fn () => app(WeekResolver::class)->decide($save, $other->id))
            ->toThrow(GameRuleException::class, 'Answer the Money in the Bank cash-in first');

        app(WeekResolver::class)->answerCashIn($save, true);

        $cashIn = MatchSlot::where('show_id', $title->show_id)->where('position', 2)->firstOrFail();

        expect($cashIn->mitb_cash_in)->toBe(MitbCashInTiming::After)
            ->and($cashIn->competitorIds())->toEqualCanonicalizing([GameBuilder::wrestler($save, 'Edge')->id, GameBuilder::wrestler($save, 'Carlito')->id])
            ->and($other->refresh()->position)->toBe(3)
            ->and(app(WeekResolver::class)->cashInOffer($save))->toBeNull();

        app(WeekResolver::class)->decide($save, $cashIn->id, 1);

        expect(app(TitleService::class)->titlesHeldBy(GameBuilder::wrestler($save, 'Carlito')))->toBe(['WWE Championship']);
    });

    it('carries on with the card when the player says not now', function () {
        [$save, $title, $other] = cashInWeek();
        app(WeekResolver::class)->begin($save);
        app(WeekResolver::class)->decide($save, $title->id, 0);

        app(WeekResolver::class)->answerCashIn($save, false);
        app(WeekResolver::class)->decide($save, $other->id);

        expect(briefcase($save)->status)->toBe(MitbStatus::Held)
            ->and($other->refresh()->result)->not->toBeNull();
    });

    it('lets Simulate all pass on the offer', function () {
        [$save, $title] = cashInWeek();
        app(WeekResolver::class)->begin($save);
        app(WeekResolver::class)->decide($save, $title->id, 0);

        app(WeekResolver::class)->simulateAll($save);

        expect($save->refresh()->current_week)->toBe(2)
            ->and(briefcase($save)->status)->toBe(MitbStatus::Held);
    });
});

describe('before the show', function () {
    it('goes where the GM puts it, moving the later matches down', function () {
        [$save, $title, $other] = cashInWeek();
        $show = Card::show($save);
        MatchSlot::where('id', $title->id)->update(['title_championship_id' => null]);

        $slot = app(MitbService::class)->cashIn($show, briefcase($save), 2);

        expect($slot->position)->toBe(2)
            ->and($slot->mitb_cash_in)->toBe(MitbCashInTiming::Before)
            ->and($other->refresh()->position)->toBe(3)
            ->and(MatchSlot::where('show_id', $show->id)->count())->toBe(7);
    });

    it('goes after the Main Event by default, and a card takes only one extra match', function () {
        [$save] = cashInWeek();
        $show = Card::show($save);

        $slot = app(MitbService::class)->cashIn($show, briefcase($save));

        expect($slot->position)->toBe(7)
            ->and(fn () => app(MitbService::class)->cashIn($show, briefcase($save)->refresh()))
            ->toThrow(GameRuleException::class);
    });
});
