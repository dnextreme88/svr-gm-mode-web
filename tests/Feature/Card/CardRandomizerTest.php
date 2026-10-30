<?php

use App\Enums\Brand;
use App\Enums\InjurySeverity;
use App\Enums\PromoTypeKey;
use App\Enums\RosterType;
use App\Enums\ShowType;
use App\Enums\TitleShotStatus;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\LastChanceLockout;
use App\Models\MatchSlot;
use App\Models\PromoType;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Models\TitleShot;
use App\Services\Card\CardRandomizer;
use App\Services\Card\Proposal;
use App\Services\Contracts\ContractService;
use App\Services\Injuries\InjuryService;
use App\Services\Promos\PromoService;
use App\Support\Rng\SeededRng;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| §24 CardRandomizer: the spec's backend test list.
*/

function randomizer(): CardRandomizer
{
    return app(CardRandomizer::class);
}

/**
 * @param  list<string>  $names
 */
function signDivas(Save $save, array $names, Brand $brand = Brand::Raw): void
{
    foreach ($names as $name) {
        app(ContractService::class)->signDrafted($save, GameBuilder::wrestler($save, $name), $brand, 1);
    }
}

/**
 * @return list<int>
 */
function proposedIds(Proposal $proposal): array
{
    return $proposal->booking->competitorIds();
}

it('never selects a seriously injured wrestler', function () {
    $save = GameBuilder::inSeason(size: 10);
    $raw = SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Raw)->orderBy('id')->get();

    foreach ($raw->slice(2) as $w) {
        app(InjuryService::class)->injure($w, InjurySeverity::Serious, 1, $save->rng("inj.{$w->id}"));
    }

    $show = Card::show($save);
    $healthy = $raw->take(2)->pluck('id')->all();

    foreach (range(1, 40) as $seed) {
        $ids = proposedIds(randomizer()->proposeSlot($show, 1, Card::singles(), new SeededRng("s{$seed}")));
        expect(array_diff($ids, $healthy))->toBe([]);
    }
});

it('does select minor-injured wrestlers when needed', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Kane'), InjurySeverity::Minor, 1, $save->rng('t'));

    $ids = proposedIds(randomizer()->proposeSlot(Card::show($save), 1, Card::singles(), new SeededRng('minor')));

    expect($ids)->toContain(GameBuilder::wrestler($save, 'Kane')->id);
});

it('never selects a wrestler already booked on the card, as competitor or manager', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Ric Flair', 'Carlito', 'Big Show'], smackdown: ['Batista']);
    $show = Card::show($save);
    Card::book($save, $show, 1, Card::type('single', 'No Manager vs. w/Manager'), [['Edge'], ['Kane']], [1 => 'Ric Flair']);
    $booked = [GameBuilder::wrestler($save, 'Edge')->id, GameBuilder::wrestler($save, 'Kane')->id, GameBuilder::wrestler($save, 'Ric Flair')->id];

    foreach (range(1, 30) as $seed) {
        expect(array_intersect(proposedIds(randomizer()->proposeSlot($show, 2, Card::singles(), new SeededRng("b{$seed}"))), $booked))->toBe([]);
    }
});

it('never selects a wrestler sitting out a blocked defection', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
    GameBuilder::wrestler($save, 'Carlito')->update(['unbookable_until_week' => 3]);

    foreach (range(1, 30) as $seed) {
        expect(proposedIds(randomizer()->proposeSlot(Card::show($save), 1, Card::singles(), new SeededRng("d{$seed}"))))
            ->not->toContain(GameBuilder::wrestler($save, 'Carlito')->id);
    }
});

it('fails with a 422 and the expected message when the pool has 0 or 1 wrestlers', function (array $raw, int $have) {
    $save = GameBuilder::inSeason(raw: $raw, smackdown: ['Batista']);
    $show = Card::show($save);

    $this->postJson(route('gm.card.randomize', [$save, $show, 1]))
        ->assertStatus(422)
        ->assertJson(['message' => "Not enough available wrestlers to book this match (need 2, have {$have})."]);

    expect(MatchSlot::where('show_id', $show->id)->whereNotNull('match_type_id')->count())->toBe(0);
})->with([[[], 0], [['Edge'], 1]]);

it('fails when the pool is smaller than a chosen match type needs', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito', 'Big Show'], smackdown: ['Batista']);

    randomizer()->proposeSlot(Card::show($save), 1, Card::type('hell_in_a_cell', 'Armageddon Hell in a Cell'), new SeededRng('six'));
})->throws(GameRuleException::class, 'need 6, have 4');

it('only puts a title on the line when that title is absent from the card', function () {
    config(['gm.card.randomize_title_chance' => 1.0]);
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito', 'Big Show'], smackdown: ['Batista']);
    $wwe = Championship::where('key', 'wwe')->value('id');
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    $show = Card::show($save);

    expect(randomizer()->proposeSlot($show, 2, Card::singles(), new SeededRng('first'))->booking->titleChampionshipId)->toBe($wwe);

    Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kane']], [], $wwe);

    foreach (range(1, 40) as $seed) {
        expect(randomizer()->proposeSlot($show, 2, Card::singles(), new SeededRng("t{$seed}"), [], [$wwe])->booking->titleChampionshipId)->not->toBe($wwe);
    }
});

it('always includes the champions and only eligible challengers in a title proposal', function (string $key, array $champions, array $pool, array $ineligible) {
    config(['gm.card.randomize_title_chance' => 1.0]);
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $brand = Championship::where('key', $key)->firstOrFail()->brand;

    foreach ([...$champions, ...$pool, ...$ineligible] as $name) {
        $w = GameBuilder::wrestler($save, $name);
        if ($w->brand === null) {
            app(ContractService::class)->signDrafted($save, $w, $brand, 1);
        }
    }

    GameBuilder::giveTitle($save, $key, $champions);
    $show = Card::show($save, $brand);
    $championIds = array_map(fn ($n) => GameBuilder::wrestler($save, $n)->id, $champions);
    $badIds = array_map(fn ($n) => GameBuilder::wrestler($save, $n)->id, $ineligible);

    foreach (range(1, 25) as $seed) {
        $proposal = randomizer()->proposeSlot($show, 1, Card::singles(), new SeededRng("e{$seed}"), [], [], $brand);

        if ($proposal->booking->titleChampionshipId !== null) {
            expect(array_diff($championIds, proposedIds($proposal)))->toBe([])
                ->and(array_intersect($badIds, proposedIds($proposal)))->toBe([]);
        }
    }
})->with([
    'Divas only' => ['womens', ['Trish Stratus'], ['Lita'], ['Edge', 'Kane']],
    'Cruiserweight class rule' => ['cruiserweight', ['Rey Mysterio'], ['Paul London'], ['Big Show', 'Undertaker', 'Melina']],
]);

it('never offers a title match to a Last Chance loser against that champion', function () {
    config(['gm.card.randomize_title_chance' => 1.0]);
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
    $reign = GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    LastChanceLockout::create(['save_id' => $save->id, 'championship_id' => $reign->championship_id, 'title_reign_id' => $reign->id, 'wrestler_ids' => [GameBuilder::wrestler($save, 'Kane')->id], 'created_week' => 1]);

    foreach (range(1, 30) as $seed) {
        $proposal = randomizer()->proposeSlot(Card::show($save), 1, Card::singles(), new SeededRng("lc{$seed}"));

        if ($proposal->booking->titleChampionshipId !== null) {
            expect(proposedIds($proposal))->not->toContain(GameBuilder::wrestler($save, 'Kane')->id);
        }
    }
});

it('respects match-type constraints: competitor count, Divas-only types and PPV-only types', function () {
    config(['gm.card.randomize_title_chance' => 0.0]);
    $save = GameBuilder::inSeason(size: 12);
    signDivas($save, ['Trish Stratus', 'Lita']);
    $weekly = Card::show($save);
    $divas = SaveWrestler::where('save_id', $save->id)->whereHas('wrestler', fn ($q) => $q->where('roster_type', RosterType::Diva))->pluck('id')->all();

    foreach (range(1, 60) as $seed) {
        $proposal = randomizer()->proposeSlot($weekly, 1, null, new SeededRng("c{$seed}"));
        $type = $proposal->booking->matchType;

        expect(count(proposedIds($proposal)))->toBe($type->competitorCount())
            ->and($type->ppv_only)->toBeFalse();

        if ($type->divas_only) {
            expect(array_diff(proposedIds($proposal), $divas))->toBe([]);
        }
    }

    $fyf = randomizer()->proposeSlot($weekly, 1, Card::type('fulfill_your_fantasy', 'Single'), new SeededRng('fyf'));
    expect(array_diff(proposedIds($fyf), $divas))->toBe([]);

    $eligible = randomizer()->eligibleTypes(Card::show($save, Brand::Raw, ShowType::Ppv, 4), Brand::Raw);
    $noMercy = randomizer()->eligibleTypes(Card::show($save, Brand::Smackdown, ShowType::Ppv, (int) config('gm.calendar.ppv_weeks.no_mercy')), Brand::Smackdown);
    expect($eligible->contains(fn ($t) => $t->family->value === 'buried_alive'))->toBeFalse()
        ->and($eligible->contains(fn ($t) => $t->family->value === 'elimination_chamber'))->toBeFalse()
        ->and($noMercy->contains(fn ($t) => $t->family->value === 'buried_alive'))->toBeTrue()
        ->and(randomizer()->eligibleTypes($weekly, Brand::Raw)->contains(fn ($t) => $t->ppv_only))->toBeFalse();
});

describe('Preset', function () {
    it('keeps manually filled slots and fixed slots unchanged', function () {
        $save = GameBuilder::inSeason(size: 14);
        $show = Card::show($save);
        $manual = Card::book($save, $show, 2, Card::singles(), [[GameBuilder::wrestler($save, 'Edge')->wrestler->name], ['Kane']]);
        MatchSlot::where('show_id', $show->id)->where('position', 4)->update(['fixed_kind' => 'royal_rumble']);

        $result = randomizer()->preset($show, new SeededRng('keep'));
        $positions = array_map(fn ($p) => $p->position, $result['proposals']);

        expect($positions)->not->toContain(2)
            ->and($positions)->not->toContain(4)
            ->and($manual->fresh()->match_type_id)->toBe(Card::singles()->id);
    });

    it('books a pending title shot first', function () {
        $save = GameBuilder::inSeason(size: 12);
        $reign = GameBuilder::giveTitle($save, 'wwe', ['Edge']);
        $contender = GameBuilder::wrestler($save, 'Kane');
        $earned = MatchSlot::where('show_id', Card::show($save)->id)->where('position', 6)->firstOrFail();
        TitleShot::create([
            'save_id' => $save->id, 'championship_id' => $reign->championship_id, 'brand' => Brand::Raw,
            'contender_ids' => [$contender->id], 'champion_ids' => $reign->holderIds(), 'earned_match_id' => $earned->id,
            'earned_week' => 0, 'due_week' => 1, 'status' => TitleShotStatus::Pending,
        ]);

        $first = randomizer()->preset(Card::show($save), new SeededRng('shot'))['proposals'][0];

        expect($first->position)->toBe(1)
            ->and($first->booking->titleChampionshipId)->toBe($reign->championship_id)
            ->and(proposedIds($first))->toEqualCanonicalizing([GameBuilder::wrestler($save, 'Edge')->id, $contender->id]);
    });

    it('never books the same wrestler twice or the same title twice', function () {
        config(['gm.card.randomize_title_chance' => 1.0]);
        $save = GameBuilder::inSeason(size: 20);
        GameBuilder::giveTitle($save, 'wwe', ['Edge']);
        GameBuilder::giveTitle($save, 'intercontinental', ['Kane']);

        foreach (range(1, 10) as $seed) {
            $result = randomizer()->preset(Card::show($save), new SeededRng("p{$seed}"));
            $ids = array_merge(...array_map('proposedIds', $result['proposals']));
            $titles = array_values(array_filter(array_map(fn ($p) => $p->booking->titleChampionshipId, $result['proposals'])));

            expect(count($ids))->toBe(count(array_unique($ids)))
                ->and(count($titles))->toBe(count(array_unique($titles)));
        }
    });

    it('reports unfilled slots when the pool runs dry', function () {
        config(['gm.card.randomize_title_chance' => 0.0]);
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);

        $result = randomizer()->preset(Card::show($save), new SeededRng('dry'));

        expect($result['proposals'])->toHaveCount(1)
            ->and($result['unfilled_slots'])->toBe([2, 3, 4, 5, 6]);
    });

    it('errors like Randomize when no slot can be filled', function () {
        $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

        randomizer()->preset(Card::show($save), new SeededRng('none'));
    })->throws(GameRuleException::class, 'need 2, have 1');

    it('returns the proposal, unfilled slots and cost warning through the endpoint', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);

        $this->postJson(route('gm.card.preset', [$save, Card::show($save)]))
            ->assertOk()
            ->assertJsonStructure(['proposals' => [['position', 'match_type_id', 'sides', 'side_names']], 'unfilled_slots', 'total_cost', 'deficit_warning']);
    });
});

it('gives the same proposal for the same seed', function () {
    $save = GameBuilder::inSeason(size: 16);
    $show = Card::show($save);

    $a = randomizer()->preset($show, new SeededRng('same'));
    $b = randomizer()->preset($show, new SeededRng('same'));

    expect(array_map(fn ($p) => [$p->position, $p->booking->matchType->id, $p->sides], $a['proposals']))
        ->toBe(array_map(fn ($p) => [$p->position, $p->booking->matchType->id, $p->sides], $b['proposals']));
});

it('confirms a Randomize proposal through the normal booking endpoint', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $show = Card::show($save);

    $proposal = $this->postJson(route('gm.card.randomize', [$save, $show, 1]), ['match_type_id' => Card::singles()->id])->json('proposal');

    $this->put(route('gm.card.book', [$save, $show, 1]), [
        'match_type_id' => $proposal['match_type_id'],
        'sides' => $proposal['sides'],
    ])->assertRedirect();

    expect(Show::find($show->id)->matchSlots()->where('position', 1)->value('match_type_id'))->toBe(Card::singles()->id);
});

it('answers a JSON confirm with 204 instead of a redirect', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $show = Card::show($save);

    $this->putJson(route('gm.card.book', [$save, $show, 1]), [
        'match_type_id' => Card::singles()->id,
        'sides' => [[GameBuilder::wrestler($save, 'Edge')->id], [GameBuilder::wrestler($save, 'Kane')->id]],
    ])->assertNoContent();
});

describe('Preset card button (house rule): rebooks every slot', function () {
    it('rebooks booked slots too, but never fixed ones', function () {
        $save = GameBuilder::inSeason(size: 14);
        $show = Card::show($save);
        Card::book($save, $show, 2, Card::singles(), [['Edge'], ['Kane']]);
        MatchSlot::where('show_id', $show->id)->where('position', 4)->update(['fixed_kind' => 'royal_rumble']);

        $positions = array_map(fn ($p) => $p->position, randomizer()->preset($show, new SeededRng('all'), replace: true)['proposals']);

        expect($positions)->toContain(2)
            ->and($positions)->not->toContain(4);
    });

    it('can reuse wrestlers from the bookings it replaces', function () {
        config(['gm.card.randomize_title_chance' => 0.0]);
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        $show = Card::show($save);
        Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kane']]);

        $result = randomizer()->preset($show, new SeededRng('reuse'), replace: true);

        expect($result['proposals'])->toHaveCount(1)
            ->and(proposedIds($result['proposals'][0]))->toEqualCanonicalizing([GameBuilder::wrestler($save, 'Edge')->id, GameBuilder::wrestler($save, 'Kane')->id]);
    });

    it('books an earned title shot first again, with a note saying why', function () {
        $save = GameBuilder::inSeason(size: 12);
        $reign = GameBuilder::giveTitle($save, 'wwe', ['Edge']);
        $contender = GameBuilder::wrestler($save, 'Kane');
        $show = Card::show($save);
        $earned = MatchSlot::where('show_id', $show->id)->where('position', 6)->firstOrFail();
        TitleShot::create([
            'save_id' => $save->id, 'championship_id' => $reign->championship_id, 'brand' => Brand::Raw,
            'contender_ids' => [$contender->id], 'champion_ids' => $reign->holderIds(), 'earned_match_id' => $earned->id,
            'earned_week' => 0, 'due_week' => 1, 'status' => TitleShotStatus::Pending,
        ]);
        Card::book($save, $show, 3, Card::singles(), [['Edge'], ['Kane']], titleId: $reign->championship_id);

        $first = randomizer()->preset($show, new SeededRng('shot-again'), replace: true)['proposals'][0];

        expect($first->position)->toBe(1)
            ->and($first->titleShot)->toBeTrue()
            ->and($first->booking->titleChampionshipId)->toBe($reign->championship_id)
            ->and($first->toArray([$contender->id => 'Kane'])['note'])->toContain('Kane earned this match by winning a Champion vs Contender match');
    });

    it('sends every slot through the endpoint, booked or not', function () {
        $save = GameBuilder::inSeason(size: 14);
        $show = Card::show($save);
        Card::book($save, $show, 2, Card::singles(), [['Edge'], ['Kane']]);

        $positions = array_column($this->postJson(route('gm.card.preset', [$save, $show]))->assertOk()->json('proposals'), 'position');

        expect($positions)->toContain(2);
    });
});

describe('Applying a Preset', function () {
    it('books every slot at once, so a title can move between slots', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito', 'Big Show'], smackdown: ['Batista']);
        $reign = GameBuilder::giveTitle($save, 'wwe', ['Edge']);
        $show = Card::show($save);
        Card::book($save, $show, 3, Card::singles(), [['Edge'], ['Kane']], titleId: $reign->championship_id);
        $id = fn (string $name): int => GameBuilder::wrestler($save, $name)->id;

        $this->postJson(route('gm.card.preset.apply', [$save, $show]), ['proposals' => [
            ['position' => 1, 'match_type_id' => Card::singles()->id, 'sides' => [[$id('Edge')], [$id('Carlito')]], 'title_championship_id' => $reign->championship_id],
            ['position' => 3, 'match_type_id' => Card::singles()->id, 'sides' => [[$id('Kane')], [$id('Big Show')]]],
        ]])->assertNoContent();

        $slots = MatchSlot::where('show_id', $show->id)->get()->keyBy('position');
        expect($slots[1]->title_championship_id)->toBe($reign->championship_id)
            ->and($slots[3]->title_championship_id)->toBeNull();
    });

    it('leaves the card as it was when a proposal is refused', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
        $show = Card::show($save);
        Card::book($save, $show, 2, Card::singles(), [['Edge'], ['Kane']]);
        $id = fn (string $name): int => GameBuilder::wrestler($save, $name)->id;

        $this->postJson(route('gm.card.preset.apply', [$save, $show]), ['proposals' => [
            ['position' => 2, 'match_type_id' => Card::singles()->id, 'sides' => [[$id('Carlito')], [$id('Kane')]]],
            ['position' => 3, 'match_type_id' => Card::singles()->id, 'sides' => [[$id('Batista')], [$id('Edge')]]],
        ]])->assertStatus(422);

        expect(MatchSlot::where('show_id', $show->id)->where('position', 2)->first()->competitorIds())
            ->toEqualCanonicalizing([$id('Edge'), $id('Kane')]);
    });
});

describe('Randomize and Preset keep the roster healthy (house rule)', function () {
    it('never picks someone the match would push past 70% fatigue', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito', 'Big Show'], smackdown: ['Batista']);
        GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 60]);
        $edge = GameBuilder::wrestler($save, 'Edge')->id;
        $show = Card::show($save);

        foreach (range(1, 30) as $seed) {
            $proposal = randomizer()->proposeSlot($show, 1, null, new SeededRng("tired{$seed}"));
            $cost = $proposal->booking->matchType->fatigueCost();

            if (60 + $cost > 70) {
                expect(proposedIds($proposal))->not->toContain($edge);
            }
        }
    });

    it('counts the tiring promos booked for this week', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito', 'Big Show'], smackdown: ['Batista']);
        $show = Card::show($save);
        $promo = PromoType::where('key', PromoTypeKey::InterferencePromotion)->firstOrFail();
        // Fresh enough for a singles match on his own, not after the Interference promo's bars.
        GameBuilder::wrestler($save, 'Edge')->update(['fatigue' => 70 - Card::singles()->fatigueCost()]);
        app(PromoService::class)->book($show, 1, $promo, GameBuilder::wrestler($save, 'Edge')->id);
        $edge = GameBuilder::wrestler($save, 'Edge')->id;

        foreach (range(1, 20) as $seed) {
            expect(proposedIds(randomizer()->proposeSlot($show, 1, Card::singles(), new SeededRng("week{$seed}"))))->not->toContain($edge);
        }
    });

    it('says so when everyone left is too tired', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        GameBuilder::wrestler($save, 'Kane')->update(['fatigue' => 70]);

        randomizer()->proposeSlot(Card::show($save), 1, Card::singles(), new SeededRng('worn'));
    })->throws(GameRuleException::class, 'without risking an injury');

    it('leaves minor-injured wrestlers out while others can go', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
        app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Kane'), InjurySeverity::Minor, 1, $save->rng('t'));
        $kane = GameBuilder::wrestler($save, 'Kane')->id;

        foreach (range(1, 20) as $seed) {
            expect(proposedIds(randomizer()->proposeSlot(Card::show($save), 1, Card::singles(), new SeededRng("m{$seed}"))))->not->toContain($kane);
        }
    });
});
