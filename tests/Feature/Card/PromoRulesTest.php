<?php

use App\Enums\Brand;
use App\Enums\InjurySeverity;
use App\Enums\LedgerCategory;
use App\Enums\PromoTypeKey;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\Injury;
use App\Models\LedgerEntry;
use App\Models\MatchParticipant;
use App\Models\PromoType;
use App\Models\Save;
use App\Services\Card\CardService;
use App\Services\Injuries\InjuryService;
use App\Services\Injuries\InjuryStatus;
use App\Services\Promos\PromoEligibility;
use App\Services\Promos\PromoService;
use App\Services\Rivalries\RivalryService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| §5 Promo availability, targets, placement and promo fatigue (house rules).
*/

/**
 * @return array{available: bool, reason: string|null, wrestler_ids: list<int>, pairs: list<array{0: int, 1: int}>, championship_ids: list<int>}
 */
function rulesFor(Save $save, PromoTypeKey $key): array
{
    return app(PromoEligibility::class)->forShow(Card::show($save->refresh()), Brand::Raw)[$key->value];
}

function idOf(Save $save, string $name): int
{
    return GameBuilder::wrestler($save, $name)->id;
}

function seriouslyInjure(Save $save, string $name, int $week = 1): Injury
{
    return app(InjuryService::class)->injure(GameBuilder::wrestler($save, $name), InjurySeverity::Serious, $week, $save->rng("inj.{$name}"));
}

/**
 * @param  array<string, mixed>  $args
 */
function promo(Save $save, PromoTypeKey $key, array $args = [])
{
    return app(PromoService::class)->book(
        Card::show($save->refresh()),
        $args['position'] ?? 1,
        PromoType::where('key', $key)->firstOrFail(),
        isset($args['a']) ? idOf($save, $args['a']) : null,
        isset($args['b']) ? idOf($save, $args['b']) : null,
        $args['championship'] ?? null,
        $args['before'] ?? null,
    );
}

function wweId(): int
{
    return (int) Championship::where('key', 'wwe')->value('id');
}

describe('availability', function () {
    it('greys out Inactive Superstar until someone is seriously injured, then targets only them', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        expect(rulesFor($save, PromoTypeKey::InactiveSuperstar)['available'])->toBeFalse();

        seriouslyInjure($save, 'Kane');

        expect(rulesFor($save, PromoTypeKey::InactiveSuperstar))
            ->available->toBeTrue()
            ->wrestler_ids->toBe([idOf($save, 'Kane')]);
    });

    it('offers Comeback Reminder only for wrestlers out more than 3 weeks', function (int $week, bool $available) {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        seriouslyInjure($save, 'Kane')->update(['ends_week' => 20]);
        $save->update(['current_week' => $week]);

        expect(rulesFor($save, PromoTypeKey::ComebackReminder)['available'])->toBe($available);
    })->with([[4, false], [5, true]]);

    it('offers Injury Return to wrestlers back from a serious injury within the window', function (int $endsWeek, bool $available) {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        seriouslyInjure($save, 'Kane')->update(['ends_week' => $endsWeek]);
        $save->update(['current_week' => 8]);

        expect(rulesFor($save, PromoTypeKey::InjuryReturn)['available'])->toBe($available);
    })->with([[8, true], [7, true], [6, false], [9, false]]);

    it('limits Interference to healthy non-Divas', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Lita'], smackdown: ['Batista']);
        seriouslyInjure($save, 'Kane');

        expect(rulesFor($save, PromoTypeKey::InterferencePromotion)['wrestler_ids'])->toBe([idOf($save, 'Edge')]);
    });

    it('needs two healthy Divas for the Diva Bikini Contest', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Lita'], smackdown: ['Batista']);
        expect(rulesFor($save, PromoTypeKey::DivaBikiniContest)['available'])->toBeFalse();

        $both = GameBuilder::inSeason(raw: ['Edge', 'Lita', 'Trish Stratus'], smackdown: ['Batista']);
        expect(rulesFor($both, PromoTypeKey::DivaBikiniContest))
            ->available->toBeTrue()
            ->wrestler_ids->toEqualCanonicalizing([idOf($both, 'Lita'), idOf($both, 'Trish Stratus')]);
    });

    it('leaves wrestlers already in a rivalry out of Start a Rivalry', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
        app(RivalryService::class)->start($save, idOf($save, 'Edge'), idOf($save, 'Kane'), 1);

        expect(rulesFor($save, PromoTypeKey::StartRivalry))
            ->available->toBeFalse()
            ->wrestler_ids->toBe([idOf($save, 'Carlito')])
            ->and(rulesFor($save, PromoTypeKey::ContinueRivalry)['pairs'])->toBe([[idOf($save, 'Edge'), idOf($save, 'Kane')]]);
    });

    it('greys out Continue Rivalry with no rivalries', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

        expect(rulesFor($save, PromoTypeKey::ContinueRivalry)['available'])->toBeFalse();
    });

    it('offers Title Match Hype only for titles defended in a saved match on this card', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        GameBuilder::giveTitle($save, 'wwe', ['Edge']);
        expect(rulesFor($save, PromoTypeKey::TitleMatchHype)['available'])->toBeFalse();

        Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']], titleId: wweId());

        expect(rulesFor($save, PromoTypeKey::TitleMatchHype)['championship_ids'])->toBe([wweId()]);
    });

    it('keeps the always-on promos available', function (PromoTypeKey $key) {
        $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

        expect(rulesFor($save, $key)['available'])->toBeTrue();
    })->with([PromoTypeKey::Advertising, PromoTypeKey::MerchandiseSponsorship, PromoTypeKey::MainEventHype, PromoTypeKey::PpvHype, PromoTypeKey::SlanderPromotion, PromoTypeKey::InterviewSpace]);
});

describe('booking', function () {
    it('rejects ineligible targets', function (PromoTypeKey $key, array $args, string $message) {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Lita', 'Trish Stratus'], smackdown: ['Batista']);
        seriouslyInjure($save, 'Kane');

        expect(fn () => promo($save, $key, $args))->toThrow(GameRuleException::class, $message);
    })->with([
        'inactive on a healthy wrestler' => [PromoTypeKey::InactiveSuperstar, ['a' => 'Edge'], 'can’t take part'],
        'interference with a Diva' => [PromoTypeKey::InterferencePromotion, ['a' => 'Lita'], 'can’t take part'],
        'bikini with a non-Diva' => [PromoTypeKey::DivaBikiniContest, ['a' => 'Lita', 'b' => 'Edge'], 'can’t take part'],
        'interview with an injured wrestler' => [PromoTypeKey::InterviewSpace, ['a' => 'Kane'], 'can’t take part'],
        'title hype with no title match' => [PromoTypeKey::TitleMatchHype, ['championship' => 1], 'Title Match Hype'],
    ]);

    it('airs before the main event by default and only before a real match slot', function () {
        $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
        $show = Card::show($save);

        expect(promo($save, PromoTypeKey::Advertising)->before_position)->toBe($show->matchSlotCount())
            ->and(promo($save, PromoTypeKey::Advertising, ['before' => 2])->before_position)->toBe(2)
            ->and(fn () => promo($save, PromoTypeKey::Advertising, ['before' => $show->matchSlotCount() + 1]))->toThrow(GameRuleException::class, 'before one of the card');
    });
});

describe('airing', function () {
    it('adds promo fatigue where the promo airs, so later matches start tired', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito', 'Kurt Angle'], smackdown: ['Batista']);
        $show = Card::show($save);
        Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kurt Angle']]);
        Card::book($save, $show, 2, Card::singles(), [['Edge'], ['Carlito']]);
        promo($save, PromoTypeKey::StartRivalry, ['a' => 'Edge', 'b' => 'Kane', 'before' => 2]);

        app(WeekResolver::class)->resolve($save->refresh());

        $bar = (int) config('gm.fatigue.bar_size');
        $before = fn (int $position) => MatchParticipant::whereHas('matchSlot', fn ($q) => $q->where('show_id', $show->id)->where('position', $position))
            ->where('save_wrestler_id', idOf($save, 'Edge'))->value('fatigue_before');

        expect($before(1))->toBe(0)
            ->and($before(2))->toBe(Card::singles()->fatigue_cost_bars * $bar + 2 * $bar);
    });

    it('can seriously injure a wrestler who goes into a fatiguing promo at 100%', function () {
        $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
        promo($save, PromoTypeKey::InterferencePromotion, ['a' => 'Edge']);
        GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 100]);

        app(WeekResolver::class)->resolve($save->refresh());

        expect(app(InjuryStatus::class)->isSeriouslyInjured(GameBuilder::wrestler($save, 'Edge'), 1))->toBeTrue();
    });

    it('fizzles Title Match Hype when the title match is gone by air time, but still charges', function () {
        config(['gm.promos.success_rates.title_match_hype' => 1.0]);
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        GameBuilder::giveTitle($save, 'wwe', ['Edge']);
        $show = Card::show($save);
        Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kane']], titleId: wweId());
        $slot = promo($save, PromoTypeKey::TitleMatchHype, ['championship' => wweId()]);
        app(CardService::class)->clear($show, 1);

        app(WeekResolver::class)->resolve($save->refresh());

        expect($slot->refresh())
            ->succeeded->toBeFalse()
            ->outcome_note->toBe('No title match on the card')
            ->and(LedgerEntry::where('save_id', $save->id)->where('category', LedgerCategory::PromoCost)->sum('amount'))->toBe(-10_000);
    });
});
