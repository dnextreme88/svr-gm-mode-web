<?php

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Enums\InjurySeverity;
use App\Enums\LedgerCategory;
use App\Enums\MatchMethod;
use App\Enums\MitbStatus;
use App\Enums\ReignEndReason;
use App\Enums\Stipulation;
use App\Enums\TitleShotStatus;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\LedgerEntry;
use App\Models\MatchType;
use App\Models\MoneyInTheBank;
use App\Models\TitleReign;
use App\Models\TitleShot;
use App\Services\Card\Booking;
use App\Services\Card\CardService;
use App\Services\Contracts\ContractService;
use App\Services\Injuries\InjuryService;
use App\Services\Titles\LastChanceService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| §22 Special Stipulations.
*/

function weekly($save)
{
    return Card::show($save->refresh());
}

function airIt($save): void
{
    app(WeekResolver::class)->resolve($save->refresh());
}

function titleId(string $key): int
{
    return (int) Championship::where('key', $key)->value('id');
}

function cvc($save, string $champion, string $contender, string $key = 'wwe'): void
{
    app(CardService::class)->book(weekly($save), 1, new Booking(
        Card::singles(),
        [[GameBuilder::wrestler($save, $champion)->id], [GameBuilder::wrestler($save, $contender)->id]],
        [],
        null,
        Stipulation::ChampionVsContender,
        titleId($key),
    ));
}

describe('Champion vs. Contender', function () {
    it('earns the contender a title shot on the next card when they win', function () {
        $save = GameBuilder::inSeason(raw: ['Batista', 'Undertaker'], smackdown: ['Edge']);
        GameBuilder::giveTitle($save, 'wwe', ['Batista']);
        cvc($save, 'Batista', 'Undertaker');
        FixedOutcomes::queue('Undertaker');

        airIt($save);

        $shot = TitleShot::sole();

        expect($shot->status)->toBe(TitleShotStatus::Pending)
            ->and($shot->due_week)->toBe(2)
            ->and($shot->contender_ids)->toBe([GameBuilder::wrestler($save, 'Undertaker')->id])
            ->and(TitleReign::where('save_id', $save->id)->count())->toBe(1);
    });

    it('treats a contender DQ or count-out win as a champion win', function () {
        $save = GameBuilder::inSeason(raw: ['Batista', 'Undertaker'], smackdown: ['Edge']);
        GameBuilder::giveTitle($save, 'wwe', ['Batista']);
        cvc($save, 'Batista', 'Undertaker');
        FixedOutcomes::queue('Undertaker', MatchMethod::Dq);

        airIt($save);

        expect(TitleShot::count())->toBe(0);
    });

    it('is 1v1 for singles titles with the title not on the line', function () {
        $save = GameBuilder::inSeason(raw: ['Batista', 'Undertaker', 'Kane'], smackdown: ['Edge']);
        GameBuilder::giveTitle($save, 'wwe', ['Batista']);

        expect(fn () => app(CardService::class)->book(weekly($save), 1, new Booking(
            Card::type('triple_threat', 'Normal Triple Threat'),
            [[GameBuilder::wrestler($save, 'Batista')->id], [GameBuilder::wrestler($save, 'Undertaker')->id], [GameBuilder::wrestler($save, 'Kane')->id]],
            [], null, Stipulation::ChampionVsContender, titleId('wwe'),
        )))->toThrow(GameRuleException::class, '1 vs 1');

        expect(fn () => app(CardService::class)->book(weekly($save), 1, new Booking(
            Card::singles(),
            [[GameBuilder::wrestler($save, 'Batista')->id], [GameBuilder::wrestler($save, 'Undertaker')->id]],
            [], titleId('wwe'), Stipulation::ChampionVsContender, titleId('wwe'),
        )))->toThrow(GameRuleException::class, 'not on the line');
    });

    it('blocks the card from airing until the earned shot is booked, then fulfils it', function () {
        $save = GameBuilder::inSeason(raw: ['Batista', 'Undertaker'], smackdown: ['Edge']);
        GameBuilder::giveTitle($save, 'wwe', ['Batista']);
        cvc($save, 'Batista', 'Undertaker');
        FixedOutcomes::queue('Undertaker');
        airIt($save);

        expect(fn () => airIt($save))->toThrow(GameRuleException::class, 'title shot');

        Card::book($save->refresh(), weekly($save), 1, Card::singles(), [['Batista'], ['Undertaker']], [], titleId('wwe'));
        FixedOutcomes::queue('Batista');
        airIt($save);

        expect(TitleShot::sole()->status)->toBe(TitleShotStatus::Fulfilled);
    });

    it('delays the shot while the contender is seriously injured', function () {
        $save = GameBuilder::inSeason(raw: ['Batista', 'Undertaker'], smackdown: ['Edge']);
        GameBuilder::giveTitle($save, 'wwe', ['Batista']);
        cvc($save, 'Batista', 'Undertaker');
        FixedOutcomes::queue('Undertaker');
        airIt($save);

        $injury = app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Undertaker'), InjurySeverity::Serious, 2, $save->rng('t'));
        airIt($save);

        expect(TitleShot::sole()->due_week)->toBe($injury->ends_week);
    });

    it('voids the shot if the title becomes vacant first', function () {
        $save = GameBuilder::inSeason(raw: ['Batista', 'Undertaker'], smackdown: ['Edge']);
        GameBuilder::giveTitle($save, 'wwe', ['Batista']);
        cvc($save, 'Batista', 'Undertaker');
        FixedOutcomes::queue('Undertaker');
        airIt($save);

        app(ContractService::class)->release(GameBuilder::wrestler($save, 'Batista'), 2);

        expect(TitleShot::sole()->status)->toBe(TitleShotStatus::Voided);
    });
});

describe('Loser Leaves Show', function () {
    it('only allows 1v1 and 2v2 matches', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);

        Card::book($save, weekly($save), 1, Card::type('triple_threat', 'Normal Triple Threat'), [['Edge'], ['Kane'], ['Carlito']], [], null, Stipulation::LoserLeavesShow);
    })->throws(GameRuleException::class, '1v1 or 2v2');

    it('releases the loser with a refund, vacates titles, voids the briefcase and locks re-signing for 4 weeks', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        $edge = GameBuilder::wrestler($save, 'Edge');
        GameBuilder::giveTitle($save, 'intercontinental', ['Edge']);
        MoneyInTheBank::create(['save_id' => $save->id, 'season' => 1, 'brand' => Brand::Raw, 'save_wrestler_id' => $edge->id, 'won_week' => 1, 'expires_week' => 52, 'status' => MitbStatus::Held]);
        Card::book($save, weekly($save), 1, Card::singles(), [['Edge'], ['Kane']], [], null, Stipulation::LoserLeavesShow);
        FixedOutcomes::queue('Kane');

        airIt($save);

        $edge->refresh();
        $refund = LedgerEntry::where('category', LedgerCategory::LoserLeavesRefund)->sole();

        expect($edge->brand)->toBeNull()
            ->and($refund->amount)->toBe(52 * 8_000)
            ->and($refund->memo)->toContain('Loser Leaves Show refund')
            ->and(TitleReign::where('save_id', $save->id)->orderBy('id')->first()->end_reason)->toBe(ReignEndReason::LoserLeaves)
            ->and(MoneyInTheBank::sole()->status)->toBe(MitbStatus::Voided)
            ->and($edge->unsignable_until_week)->toBe(6)
            ->and(fn () => app(ContractService::class)->signFreeAgent($save, $edge, Brand::Raw, ContractLength::FiveWeeks, 5))->toThrow(GameRuleException::class, 'Loser Leaves Show');
    });

    it('sends the whole losing team away in a 2v2', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito', 'Big Show'], smackdown: ['Batista']);
        Card::book($save, weekly($save), 1, Card::type('tag_team', 'Normal Tag'), [['Edge', 'Kane'], ['Carlito', 'Big Show']], [], null, Stipulation::LoserLeavesShow);
        FixedOutcomes::queue('Carlito');

        airIt($save);

        expect(GameBuilder::wrestler($save, 'Edge')->brand)->toBeNull()
            ->and(GameBuilder::wrestler($save, 'Kane')->brand)->toBeNull()
            ->and(GameBuilder::wrestler($save, 'Carlito')->brand)->toBe(Brand::Raw);
    });
});

describe('Last Chance', function () {
    function defend($save, array $sides, string $winner, ?MatchType $type = null): void
    {
        Card::book($save->refresh(), weekly($save), 1, $type ?? Card::singles(), $sides, [], titleId('wwe'));
        FixedOutcomes::queue($winner);
        airIt($save);
    }

    it('becomes available after two title wins against exactly the same opponent, in any match types', function () {
        $save = GameBuilder::inSeason(raw: ['Batista', 'Undertaker', 'Edge'], smackdown: ['Kane']);
        $reign = GameBuilder::giveTitle($save, 'wwe', ['Batista']);
        $taker = [GameBuilder::wrestler($save, 'Undertaker')->id];

        defend($save, [['Batista'], ['Undertaker']], 'Batista', Card::type('tlc', 'Single'));
        expect(app(LastChanceService::class)->isAvailable($reign->refresh(), $taker))->toBeFalse();

        defend($save, [['Batista'], ['Undertaker']], 'Batista', Card::type('hardcore', 'Single'));
        expect(app(LastChanceService::class)->isAvailable($reign->refresh(), $taker))->toBeTrue();

        Card::book($save->refresh(), weekly($save), 1, Card::type('submission', 'Single'), [['Batista'], ['Undertaker']], [], titleId('wwe'), Stipulation::LastChance);
        expect(weekly($save)->matchSlots()->where('position', 1)->first()->stipulation)->toBe(Stipulation::LastChance);
    });

    it('does not count a meeting with extra participants', function () {
        $save = GameBuilder::inSeason(raw: ['Batista', 'Undertaker', 'Edge'], smackdown: ['Kane']);
        $reign = GameBuilder::giveTitle($save, 'wwe', ['Batista']);

        defend($save, [['Batista'], ['Undertaker']], 'Batista', Card::type('tlc', 'Single'));
        defend($save, [['Batista'], ['Undertaker'], ['Edge']], 'Batista', Card::type('triple_threat', 'Normal Triple Threat'));

        expect(app(LastChanceService::class)->isAvailable($reign->refresh(), [GameBuilder::wrestler($save, 'Undertaker')->id]))->toBeFalse()
            ->and(fn () => Card::book($save->refresh(), weekly($save), 1, Card::singles(), [['Batista'], ['Undertaker']], [], titleId('wwe'), Stipulation::LastChance))
            ->toThrow(GameRuleException::class, 'Last Chance');
    });

    it('counts DQ wins by the contender as champion wins', function () {
        $save = GameBuilder::inSeason(raw: ['Batista', 'Undertaker'], smackdown: ['Kane']);
        $reign = GameBuilder::giveTitle($save, 'wwe', ['Batista']);

        defend($save, [['Batista'], ['Undertaker']], 'Batista');
        Card::book($save->refresh(), weekly($save), 1, Card::singles(), [['Batista'], ['Undertaker']], [], titleId('wwe'));
        FixedOutcomes::queue('Undertaker', MatchMethod::Dq);
        airIt($save);

        expect(app(LastChanceService::class)->isAvailable($reign->refresh(), [GameBuilder::wrestler($save, 'Undertaker')->id]))->toBeTrue();
    });

    it('locks the loser out of title matches against that champion until the title changes hands', function () {
        $save = GameBuilder::inSeason(raw: ['Batista', 'Undertaker', 'Edge'], smackdown: ['Kane']);
        GameBuilder::giveTitle($save, 'wwe', ['Batista']);

        defend($save, [['Batista'], ['Undertaker']], 'Batista');
        defend($save, [['Batista'], ['Undertaker']], 'Batista');
        Card::book($save->refresh(), weekly($save), 1, Card::singles(), [['Batista'], ['Undertaker']], [], titleId('wwe'), Stipulation::LastChance);
        FixedOutcomes::queue('Batista');
        airIt($save);

        expect(fn () => Card::book($save->refresh(), weekly($save), 1, Card::singles(), [['Batista'], ['Undertaker']], [], titleId('wwe')))
            ->toThrow(GameRuleException::class, 'Last Chance loser');

        // Edge beats Batista: the lockout lapses and Undertaker can challenge Edge.
        defend($save, [['Batista'], ['Edge']], 'Edge');
        Card::book($save->refresh(), weekly($save), 1, Card::singles(), [['Edge'], ['Undertaker']], [], titleId('wwe'));

        expect(weekly($save)->matchSlots()->where('position', 1)->value('title_championship_id'))->toBe(titleId('wwe'));
    });
});
