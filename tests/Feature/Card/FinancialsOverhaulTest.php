<?php

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Enums\FixedSlotKind;
use App\Enums\InjurySeverity;
use App\Enums\LedgerCategory;
use App\Enums\MatchMethod;
use App\Enums\MitbStatus;
use App\Enums\RosterType;
use App\Exceptions\GameRuleException;
use App\Models\LedgerEntry;
use App\Models\MatchResult;
use App\Models\MatchSlot;
use App\Models\MoneyInTheBank;
use App\Models\Rivalry;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Card\CardRandomizer;
use App\Services\Card\CardService;
use App\Services\Contracts\ContractService;
use App\Services\Injuries\InjuryService;
use App\Services\Rivalries\RivalryService;
use App\Services\Roster\SuperstarCareer;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| House rules (2026-11-15): release fees, reordering a card's matches,
| rivalry and gender-balanced Preset bookings, a rivalry's brand, and the
| superstar profile's injury history and special info.
*/

it('charges a release fee of the weeks left on the deal', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $edge = GameBuilder::wrestler($save, 'Edge');
    $edge->activeContract->update(['weeks_remaining' => 3]);

    app(ContractService::class)->release($edge->refresh(), 1);

    $fee = LedgerEntry::where('save_id', $save->id)->where('category', LedgerCategory::ReleaseFee)->sole();

    expect($fee->amount)->toBe(-3 * 4_615)
        ->and($fee->brand)->toBe(Brand::Raw)
        ->and($fee->memo)->toBe('Released Edge (3 weeks left)');
});

it('charges nothing when a contract simply runs out', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $edge = GameBuilder::wrestler($save, 'Edge');
    $edge->activeContract->update(['weeks_remaining' => 1]);

    app(ContractService::class)->tick($save, 1);

    expect(LedgerEntry::where('save_id', $save->id)->where('category', LedgerCategory::ReleaseFee)->exists())->toBeFalse();
});

it('refuses a signing the brand can’t cover', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $save->brand(Brand::Raw)->update(['budget' => 50_000]);

    app(ContractService::class)->signFreeAgent($save->refresh(), GameBuilder::wrestler($save, 'Kane'), Brand::Raw, ContractLength::OneYear, 1);
})->throws(GameRuleException::class, 'can’t afford');

describe('reordering matches', function () {
    it('moves a match and the others around it', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian', 'Carlito'], smackdown: ['Batista']);
        $show = Card::show($save);
        $first = Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kane']]);
        $second = Card::book($save, $show, 2, Card::singles(), [['Christian'], ['Carlito']]);

        app(CardService::class)->reorder($show, [2, 1, 3, 4, 5, 6], Brand::Raw);

        expect($first->refresh()->position)->toBe(2)
            ->and($second->refresh()->position)->toBe(1);
    });

    it('keeps a fixed slot where it is', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        $show = Card::show($save);
        MatchSlot::where('show_id', $show->id)->where('position', 1)->update(['fixed_kind' => FixedSlotKind::MitbCashIn]);

        app(CardService::class)->reorder($show, [2, 1, 3, 4, 5, 6], Brand::Raw);
    })->throws(GameRuleException::class, 'fixed');

    it('refuses an order that isn’t the card’s matches', function () {
        $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

        app(CardService::class)->reorder(Card::show($save), [1, 2, 3], Brand::Raw);
    })->throws(GameRuleException::class);

    it('reorders through the card endpoint', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        $show = Card::show($save);
        $slot = Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kane']]);

        $this->putJson(route('gm.card.reorder', [$save, $show]), ['order' => [6, 2, 3, 4, 5, 1]])->assertNoContent();

        expect($slot->refresh()->position)->toBe(6);
    });
});

describe('Preset and rivalries', function () {
    function overhaulRivalSave(): Save
    {
        GameBuilder::passiveRival();
        $save = GameBuilder::inSeason(
            raw: ['Edge', 'Kane', 'Christian', 'Carlito', 'Shelton Benjamin', 'Chris Masters', 'Tajiri', 'Snitsky', 'Rob Van Dam', 'Kurt Angle', 'Triple H', 'Big Show', 'Trish Stratus', 'Lita', 'Victoria', 'Mickie James'],
            smackdown: ['Batista'],
        );
        $rivalries = app(RivalryService::class);

        foreach ([['Edge', 'Kane'], ['Christian', 'Carlito'], ['Shelton Benjamin', 'Chris Masters']] as [$a, $b]) {
            $rivalries->start($save, GameBuilder::wrestler($save, $a)->id, GameBuilder::wrestler($save, $b)->id, 1);
        }

        return $save->refresh();
    }

    it('books up to 3 of the brand’s rivalries, each pair in one match', function () {
        $save = overhaulRivalSave();
        $result = app(CardRandomizer::class)->preset(Card::show($save), $save->rng('preset.test', 1), Brand::Raw, replace: true);

        $booked = 0;

        foreach (Rivalry::all() as $rivalry) {
            $together = collect($result['proposals'])->contains(fn ($p): bool => in_array($rivalry->wrestler_a_id, $p->booking->allIds(), true)
                && in_array($rivalry->wrestler_b_id, $p->booking->allIds(), true));
            $booked += $together ? 1 : 0;
        }

        expect($booked)->toBe(3)
            ->and(collect($result['proposals'])->filter(fn ($p): bool => str_starts_with((string) $p->note, 'Rivalry:')))->not->toBeEmpty();
    });

    it('books each rivalry shape', function (string $shape, int $competitors) {
        $save = overhaulRivalSave();
        $show = Card::show($save);
        $randomizer = app(CardRandomizer::class);
        $pool = $randomizer->pool($show, Brand::Raw);
        $pairs = $randomizer->rivalries($show, $pool);

        $proposal = $randomizer->rivalryProposal($show, 1, $shape, [$pairs[0]], $pairs, $pool, [], [], $save->rng("shape.{$shape}", 1), Brand::Raw);

        expect($proposal)->not->toBeNull()
            ->and($proposal->booking->competitorIds())->toHaveCount($competitors)
            ->and($proposal->booking->allIds())->toContain(...$pairs[0]);
    })->with([
        'singles' => ['singles', 2],
        'triple threat' => ['triple_threat', 3],
        'partners' => ['partners', 4],
        'manager' => ['manager', 2],
        'two rivalries' => ['two_rivalries', 6],
    ]);

    it('books an all-Diva match when there are Divas to book', function () {
        $save = overhaulRivalSave();
        $result = app(CardRandomizer::class)->preset(Card::show($save), $save->rng('preset.divas', 1), Brand::Raw, replace: true);
        $divas = SaveWrestler::where('save_id', $save->id)->whereHas('wrestler', fn ($q) => $q->where('roster_type', RosterType::Diva))->pluck('id')->all();

        expect(collect($result['proposals'])->contains(fn ($p): bool => array_diff($p->booking->competitorIds(), $divas) === []))->toBeTrue();
    });

    it('keeps every side even: the same number of Divas on each', function () {
        $save = overhaulRivalSave();
        $divas = SaveWrestler::where('save_id', $save->id)->whereHas('wrestler', fn ($q) => $q->where('roster_type', RosterType::Diva))->pluck('id')->all();

        foreach (range(1, 8) as $roll) {
            $result = app(CardRandomizer::class)->preset(Card::show($save), $save->rng("preset.gender.{$roll}", 1), Brand::Raw, replace: true);

            foreach ($result['proposals'] as $proposal) {
                $counts = array_map(fn (array $side): int => count(array_intersect($side, $divas)), $proposal->sides);

                expect(count(array_unique($counts)))->toBe(1, "{$proposal->booking->matchType->name}: ".json_encode($proposal->sides));
            }
        }
    });
});

it('stores the brand a rivalry started on', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

    $rivalry = app(RivalryService::class)->start($save, GameBuilder::wrestler($save, 'Edge')->id, GameBuilder::wrestler($save, 'Kane')->id, 1);

    expect($rivalry->refresh()->brand)->toBe(Brand::Raw);
});

describe('superstar profile', function () {
    it('lists every injury, newest first, with when it ended', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        $edge = GameBuilder::wrestler($save, 'Edge');
        $injuries = app(InjuryService::class);
        $old = $injuries->injure($edge, InjurySeverity::Minor, 1, $save->rng('t', 1));
        $old->update(['healed_week' => 3]);
        $injuries->injure($edge, InjurySeverity::Serious, 2, $save->rng('t', 2));

        $rows = app(SuperstarCareer::class)->injuries($save->refresh(), $edge);

        expect($rows)->toHaveCount(2)
            ->and($rows[0]['severity'])->toBe('serious')
            ->and($rows[0]['active'])->toBeTrue()
            ->and($rows[0]['ended'])->toStartWith('Out, ')
            ->and($rows[1]['ended'])->toBe('S1 · W3');
    });

    it('names the champion a Money in the Bank briefcase was cashed in on', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        $reign = GameBuilder::giveTitle($save, 'wwe', ['Kane']);
        $edge = GameBuilder::wrestler($save, 'Edge');
        $slot = Card::book($save, Card::show($save), 1, Card::singles(), [['Kane'], ['Edge']], [], $reign->championship_id);
        MatchResult::create([
            'match_slot_id' => $slot->id, 'winner_side' => 1, 'method' => MatchMethod::Pinfall, 'booked' => false,
            'title_changed' => true, 'champion_reign_id' => $reign->id, 'summary' => 'x', 'rng_seed_step' => 0,
        ]);
        MoneyInTheBank::create(['save_id' => $save->id, 'season' => 1, 'brand' => Brand::Raw, 'save_wrestler_id' => $edge->id, 'won_week' => 1, 'expires_week' => 52, 'status' => MitbStatus::CashedInWon, 'cash_in_match_id' => $slot->id, 'resolved_week' => 2]);

        $moments = collect(app(SuperstarCareer::class)->moments($save, $edge));
        $cashIn = $moments->firstWhere('kind', 'mitb_cash_in');

        expect($moments->pluck('kind')->all())->toContain('mitb', 'mitb_cash_in')
            ->and($cashIn['detail'])->toBe("Cashed in on Kane for the {$reign->championship->name}.")
            ->and($cashIn['outcome'])->toBe('Won the title')
            ->and($cashIn['url'])->toContain('match='.$slot->id);
    });

    it('shows the profile’s salary, release fee, injuries and special info', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        $edge = GameBuilder::wrestler($save, 'Edge');

        $this->get(route('gm.rosters.show', [$save, $edge]))
            ->assertInertia(fn ($page) => $page
                ->where('salary.monthly', 20_000)
                ->where('salary.tier', 'superstar')
                ->where('release_fee', 52 * 4_615)
                ->has('injury_history', 0)
                ->has('special_info', 0)
                ->has('quotes', 5)
                ->where('quotes.4.total', 240_000));
    });
});
