<?php

use App\Enums\Brand;
use App\Enums\ContractStatus;
use App\Enums\EmailTemplate;
use App\Enums\InjurySeverity;
use App\Enums\MitbStatus;
use App\Enums\Morale;
use App\Enums\ReignEndReason;
use App\Enums\ShowType;
use App\Enums\TradeStatus;
use App\Exceptions\GameRuleException;
use App\Models\Email;
use App\Models\NewsEvent;
use App\Models\SaveWrestler;
use App\Models\TitleReign;
use App\Services\Contracts\ContractService;
use App\Services\Injuries\InjuryService;
use App\Services\Morale\DefectionService;
use App\Services\Roster\TradeService;
use App\Services\Titles\MitbService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| §8 Defection and Trades.
*/

/**
 * Fill a brand to exactly $size wrestlers.
 */
function fillBrand($save, Brand $brand, int $size): void
{
    $have = SaveWrestler::where('save_id', $save->id)->where('brand', $brand)->count();
    $free = SaveWrestler::where('save_id', $save->id)->whereNull('brand')->orderBy('id')->limit($size - $have)->get();

    foreach ($free as $w) {
        app(ContractService::class)->signDrafted($save, $w, $brand, 1);
    }
}

describe('defection', function () {
    it('only rolls for Unsatisfied and Upset wrestlers, with higher odds when Upset', function () {
        $service = app(DefectionService::class);

        expect($service->chanceFor(Morale::Happy))->toBe(0.0)
            ->and($service->chanceFor(Morale::Content))->toBe(0.0)
            ->and($service->chanceFor(Morale::Unsatisfied))->toBeLessThan($service->chanceFor(Morale::Upset));
    });

    it('moves the wrestler to the rival brand with their contract when there is room', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        $edge = GameBuilder::wrestler($save, 'Edge');
        GameBuilder::giveTitle($save, 'intercontinental', ['Edge']);
        $briefcase = app(MitbService::class)->award($save, $edge, 1);
        $contractId = $edge->activeContract->id;

        app(DefectionService::class)->attempt($edge->refresh(), 1);

        $edge->refresh();

        expect($edge->brand)->toBe(Brand::Smackdown)
            ->and($edge->activeContract->id)->toBe($contractId)
            ->and($edge->activeContract->brand)->toBe(Brand::Smackdown)
            ->and($edge->activeContract->status)->toBe(ContractStatus::Active)
            ->and(TitleReign::where('save_id', $save->id)->orderBy('id')->first()->end_reason)->toBe(ReignEndReason::Defected)
            ->and($briefcase->refresh()->status)->toBe(MitbStatus::Voided)
            ->and(Email::where('template', EmailTemplate::DefectionAttempt)->where('brand', 'raw')->count())->toBe(1)
            ->and(NewsEvent::where('type', 'defection_completed')->count())->toBe(1);
    });

    it('blocks the move when the rival roster is full and benches the wrestler for exactly 2 weeks', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        fillBrand($save, Brand::Smackdown, 30);
        $edge = GameBuilder::wrestler($save, 'Edge');

        $moved = app(DefectionService::class)->attempt($edge, 1);
        $edge->refresh();

        expect($moved)->toBeFalse()
            ->and($edge->brand)->toBe(Brand::Raw)
            ->and($edge->isSittingOut(2))->toBeTrue()
            ->and($edge->isSittingOut(3))->toBeTrue()
            ->and($edge->isSittingOut(4))->toBeFalse()
            ->and(NewsEvent::where('type', 'defection_blocked')->count())->toBe(1)
            ->and(fn () => Card::book($save, Card::show($save, Brand::Raw, ShowType::Weekly, 2), 1, Card::singles(), [['Kane'], ['Edge']]))
            ->toThrow(GameRuleException::class, 'sitting out');
    });

    it('rolls during week resolution using the seeded RNG', function () {
        config(['gm.defection.chance.upset' => 1.0]);
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        GameBuilder::wrestler($save, 'Edge')->update(['weeks_unused' => 8]);

        app(WeekResolver::class)->resolve($save->refresh());

        expect(GameBuilder::wrestler($save, 'Edge')->brand)->toBe(Brand::Smackdown);
    });
});

describe('trades', function () {
    it('allows uneven trades of up to 5 per side and swaps the wrestlers', function () {
        config(['gm.trades.ai_accept_chance' => 1.0]);
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
        $out = [GameBuilder::wrestler($save, 'Edge')->id, GameBuilder::wrestler($save, 'Kane')->id, GameBuilder::wrestler($save, 'Carlito')->id];

        $trade = app(TradeService::class)->propose($save, Brand::Raw, $out, [GameBuilder::wrestler($save, 'Batista')->id]);

        expect($trade->status)->toBe(TradeStatus::Accepted)
            ->and(GameBuilder::wrestler($save, 'Edge')->brand)->toBe(Brand::Smackdown)
            ->and(GameBuilder::wrestler($save, 'Batista')->brand)->toBe(Brand::Raw)
            ->and(GameBuilder::wrestler($save, 'Batista')->activeContract->brand)->toBe(Brand::Raw)
            ->and(NewsEvent::where('type', 'trade_completed')->count())->toBe(1);
    });

    it('rejects more than 5 per side', function () {
        $save = GameBuilder::inSeason(size: 7);
        $out = SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Raw)->limit(6)->pluck('id')->all();

        app(TradeService::class)->propose($save, Brand::Raw, $out, []);
    })->throws(GameRuleException::class, '5 wrestlers per side');

    it('refuses champions, including tag champions, and seriously injured wrestlers', function (string $setup) {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Lance Cade', 'Trevor Murdoch'], smackdown: ['Batista']);
        $target = match ($setup) {
            'champion' => tap('Edge', fn () => GameBuilder::giveTitle($save, 'wwe', ['Edge'])),
            'tag' => tap('Lance Cade', fn () => GameBuilder::giveTitle($save, 'world_tag_team', ['Lance Cade', 'Trevor Murdoch'])),
            'injured' => tap('Kane', fn () => app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Kane'), InjurySeverity::Serious, 1, $save->rng('t'))),
        };

        app(TradeService::class)->propose($save, Brand::Raw, [GameBuilder::wrestler($save, $target)->id], []);
    })->with(['champion', 'tag', 'injured'])->throws(GameRuleException::class, "can't be traded");

    it('rejects a trade that would put either roster over 30', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        fillBrand($save, Brand::Smackdown, 30);

        app(TradeService::class)->propose($save, Brand::Raw, [GameBuilder::wrestler($save, 'Edge')->id], []);
    })->throws(GameRuleException::class, 'over 30');

    it('shows resulting roster sizes before the offer is sent', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);

        expect(app(TradeService::class)->resultingSizes($save, Brand::Raw, [1, 2], [3]))->toBe(['raw' => 2, 'smackdown' => 2]);
    });

    it('voids a traded briefcase holder’s Money in the Bank', function () {
        config(['gm.trades.ai_accept_chance' => 1.0]);
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        $briefcase = app(MitbService::class)->award($save, GameBuilder::wrestler($save, 'Edge'), 1);

        app(TradeService::class)->propose($save, Brand::Raw, [GameBuilder::wrestler($save, 'Edge')->id], []);

        expect($briefcase->refresh()->status)->toBe(MitbStatus::Voided);
    });

    it('leaves rosters alone when the AI declines', function () {
        config(['gm.trades.ai_accept_chance' => 0.0]);
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

        $trade = app(TradeService::class)->propose($save, Brand::Raw, [GameBuilder::wrestler($save, 'Edge')->id], []);

        expect($trade->status)->toBe(TradeStatus::Rejected)
            ->and(GameBuilder::wrestler($save, 'Edge')->brand)->toBe(Brand::Raw);
    });
});

it('renders the Propose Trade, Rivalry and News screens', function (string $route, string $component) {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    app(WeekResolver::class)->resolve($save);

    $this->actingAs($save->user)->get(route($route, $save))->assertInertia(fn ($page) => $page->component($component));
})->with([
    ['gm.trade.show', 'gm/trade'],
    ['gm.rivalries', 'gm/rivalries'],
    ['gm.news', 'gm/news'],
]);
