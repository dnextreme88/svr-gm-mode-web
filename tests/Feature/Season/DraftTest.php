<?php

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Enums\DraftMode;
use App\Enums\DraftPickType;
use App\Enums\LedgerCategory;
use App\Enums\Morale;
use App\Enums\RosterType;
use App\Enums\SaveStage;
use App\Enums\WeightClass;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\DraftPick;
use App\Models\LedgerEntry;
use App\Models\PendingRosterMove;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\TitleReign;
use App\Services\Ai\AiGm;
use App\Services\Contracts\ContractService;
use App\Services\Draft\DraftService;
use App\Services\Game\NewGameService;
use App\Services\Titles\ChampionshipAssignment;
use App\Services\Titles\TitleService;
use Tests\Support\GameBuilder;

/*
| §1 Setup: new save, initial draft, title assignment.
*/

it('creates a save with every wrestler in Free Agency and $15M per brand, granted once', function () {
    $save = GameBuilder::newSave();

    expect(SaveWrestler::where('save_id', $save->id)->count())->toBe(158)
        ->and(SaveWrestler::where('save_id', $save->id)->whereNotNull('brand')->count())->toBe(0)
        ->and($save->brand(Brand::Raw)->budget)->toBe(15_000_000)
        ->and($save->brand(Brand::Smackdown)->budget)->toBe(15_000_000)
        ->and(LedgerEntry::where('save_id', $save->id)->where('category', LedgerCategory::StartingBudget)->count())->toBe(2)
        ->and($save->stage)->toBe(SaveStage::Drafting);
});

it('randomizes the draft order per seed', function () {
    GameBuilder::seedCatalog();
    $orders = collect(range(1, 12))->map(fn (int $i) => GameBuilder::newSave(seed: "seed-{$i}")->draft_order[0])->unique();

    expect($orders->count())->toBe(2);
});

it('alternates picks between brands starting with the first in the draft order', function () {
    $save = GameBuilder::newSave();
    $draft = app(DraftService::class);
    $first = Brand::from($save->draft_order[0]);
    $second = Brand::from($save->draft_order[1]);

    $pool = SaveWrestler::where('save_id', $save->id)->orderBy('id')->get();

    expect($draft->nextBrand($save))->toBe($first);
    $draft->pick($save, $first, $pool[0]);
    expect($draft->nextBrand($save))->toBe($second);
    $draft->pick($save, $second, $pool[1]);
    expect($draft->nextBrand($save))->toBe($first);

    expect(fn () => $draft->pick($save, $second, $pool[2]))->toThrow(GameRuleException::class);
});

it('lets a brand stop early while the other keeps picking', function () {
    $save = GameBuilder::newSave();
    $draft = app(DraftService::class);
    $first = Brand::from($save->draft_order[0]);
    $second = Brand::from($save->draft_order[1]);
    $pool = SaveWrestler::where('save_id', $save->id)->orderBy('id')->get();

    $draft->finish($save, $first);
    $draft->pick($save, $second, $pool[0]);

    expect($draft->nextBrand($save))->toBe($second);
});

it('caps each brand at 30 picks and sends everyone undrafted to Free Agency', function () {
    $save = GameBuilder::newSave(Brand::Raw);
    $draft = app(DraftService::class);

    // Human picks the first available wrestler every turn; the AI picks at random.
    while (($brand = $draft->nextBrand($save)) !== null) {
        if ($save->isHuman($brand)) {
            $wrestler = SaveWrestler::where('save_id', $save->id)->whereNull('brand')->orderBy('id')->first();
            $draft->pick($save, $brand, $wrestler);
        }

        $draft->runAi($save);
    }

    $save->refresh();

    expect(SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Raw)->count())->toBe(30)
        ->and(SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Smackdown)->count())->toBe(30)
        ->and(SaveWrestler::where('save_id', $save->id)->whereNull('brand')->count())->toBe(98)
        ->and(DraftPick::where('save_id', $save->id)->where('brand', Brand::Smackdown)->where('pick_type', DraftPickType::Random)->count())->toBe(30)
        ->and($save->stage)->toBe(SaveStage::AssigningTitles);
});

it('starts draftees Happy on a contract paid from the budget', function () {
    $save = GameBuilder::newSave();
    $brand = Brand::from($save->draft_order[0]);
    $wrestler = SaveWrestler::where('save_id', $save->id)->orderBy('id')->first();

    app(DraftService::class)->pick($save, $brand, $wrestler);

    expect($wrestler->refresh()->morale)->toBe(Morale::Happy)
        ->and($wrestler->activeContract?->weeks_remaining)->toBe(52)
        ->and($save->brand($brand)->budget)->toBe(15_000_000 - 100_000);
});

describe('Use WWE roster (house rule)', function () {
    beforeEach(function () {
        GameBuilder::seedCatalog();
        $this->save = app(NewGameService::class)->create('Real', Brand::Raw, GameBuilder::SEED, DraftMode::WweRoster);
    });

    it('signs each brand its Nov 2005 roster and skips the draft', function () {
        $signed = fn (Brand $brand) => SaveWrestler::where('save_id', $this->save->id)->where('brand', $brand)->get();

        expect($signed(Brand::Raw))->toHaveCount(30)
            ->and($signed(Brand::Smackdown))->toHaveCount(30)
            ->and($signed(Brand::Raw)->every(fn (SaveWrestler $w) => $w->wrestler->placeholder_brand === Brand::Raw))->toBeTrue()
            ->and($signed(Brand::Smackdown)->every(fn (SaveWrestler $w) => $w->wrestler->placeholder_brand === Brand::Smackdown))->toBeTrue()
            ->and(SaveWrestler::where('save_id', $this->save->id)->whereNull('brand')->count())->toBe(98)
            ->and(DraftPick::where('save_id', $this->save->id)->count())->toBe(0)
            ->and($this->save->refresh()->stage)->toBe(SaveStage::AssigningTitles);
    });

    it('pays for every signing like a draft pick', function () {
        $fees = fn (Brand $brand) => -LedgerEntry::where('save_id', $this->save->id)->where('brand', $brand)->where('category', LedgerCategory::SigningFee)->sum('amount');

        foreach (Brand::cases() as $brand) {
            expect(LedgerEntry::where('save_id', $this->save->id)->where('brand', $brand)->where('category', LedgerCategory::SigningFee)->count())->toBe(30)
                ->and($this->save->brand($brand)->budget)->toBe(15_000_000 - (int) $fees($brand));
        }

        $wrestler = GameBuilder::wrestler($this->save, 'John Cena');

        expect($wrestler->morale)->toBe(Morale::Happy)
            ->and($wrestler->activeContract?->weeks_remaining)->toBe(52);
    });

    it('crowns the rival brand\'s champions and leaves the player\'s titles to assign', function () {
        expect(TitleReign::where('save_id', $this->save->id)->get()->every(fn (TitleReign $r) => $r->championship->brand === Brand::Smackdown))->toBeTrue()
            ->and(TitleReign::where('save_id', $this->save->id)->count())->toBeGreaterThan(0);
    });
});

it('leaves the AI opening pick to the draft room, one step at a time', function () {
    GameBuilder::seedCatalog();

    foreach (range(1, 6) as $i) {
        $save = app(NewGameService::class)->create('X', Brand::Raw, "auto-{$i}");

        if ($save->draft_order[0] === 'smackdown') {
            expect(DraftPick::where('save_id', $save->id)->count())->toBe(0);

            $step = app(DraftService::class)->aiStep($save);

            expect($step['pick']?->brand)->toBe(Brand::Smackdown)
                ->and($step['ai_turn'])->toBeFalse()
                ->and(app(DraftService::class)->nextBrand($save))->toBe(Brand::Raw);

            return;
        }
    }

    $this->fail('No seed gave SmackDown the first pick.');
});

it('opens reign #1 as awarded for each assigned title and starts the season', function () {
    $save = GameBuilder::newSave(Brand::Raw);
    $save->update(['stage' => SaveStage::AssigningTitles]);
    $edge = GameBuilder::wrestler($save, 'Edge');
    $edge->update(['brand' => Brand::Raw]);

    $assignment = app(ChampionshipAssignment::class);
    $reign = $assignment->assign($save, Championship::where('key', 'wwe')->firstOrFail(), [$edge->id]);
    $assignment->complete($save->refresh());

    expect($reign->reign_number)->toBe(1)
        ->and($reign->won_via?->value)->toBe('awarded')
        ->and($reign->holderName())->toBe('Edge')
        ->and(TitleReign::where('save_id', $save->id)->whereNull('lost_week')->count())->toBe(8)
        ->and($save->refresh()->stage)->toBe(SaveStage::InSeason);
});

it('rejects ineligible title holders', function () {
    $save = GameBuilder::newSave(Brand::Smackdown);
    $save->update(['stage' => SaveStage::AssigningTitles]);
    $batista = GameBuilder::wrestler($save, 'Batista');
    $batista->update(['brand' => Brand::Smackdown]);

    app(ChampionshipAssignment::class)->assign($save, Championship::where('key', 'cruiserweight')->firstOrFail(), [$batista->id]);
})->throws(GameRuleException::class);

describe('Legends in the initial draft (house rule)', function () {
    it('keeps Legends out of the draft pool and refuses a Legend pick', function () {
        $save = GameBuilder::newSave();
        $first = Brand::from($save->draft_order[0]);
        $legend = GameBuilder::wrestler($save, 'Hulk Hogan');

        $this->get(route('gm.draft.show', $save))
            ->assertInertia(fn ($page) => $page->where('pool', fn ($pool) => collect($pool)->doesntContain('roster_type', 'legend')));

        expect(fn () => app(DraftService::class)->pick($save, $first, $legend))->toThrow(GameRuleException::class, 'Legend');
    });

    it('never has the rival GM draft a Legend', function () {
        $save = GameBuilder::newSave();
        $ai = app(AiGm::class);

        $picks = collect(range(1, 60))->map(fn (int $n) => $ai->draftPick($save, Brand::Raw, $n));

        expect($picks->filter(fn (?SaveWrestler $w) => $w?->wrestler->isLegend())->count())->toBe(0);
    });

    it('leaves Legends in Free Agency to sign once the draft is over', function () {
        $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
        $hogan = GameBuilder::wrestler($save, 'Hulk Hogan');

        expect($hogan->brand)->toBeNull();

        app(ContractService::class)->signFreeAgent($save, $hogan, Brand::Raw, ContractLength::OneYear, $save->current_week);

        expect(PendingRosterMove::where('save_wrestler_id', $hogan->id)->exists())->toBeTrue();
    });
});

describe('Rival GM draft focus (house rule)', function () {
    it('goes after Divas for Raw and Cruiserweights or Light Heavyweights for SmackDown on a focus roll', function () {
        config(['gm.draft.ai_focus_from' => 1]);
        $save = GameBuilder::newSave();
        $ai = app(AiGm::class);

        foreach (range(1, 20) as $n) {
            expect($ai->draftPick($save, Brand::Raw, $n)?->wrestler->roster_type)->toBe(RosterType::Diva);

            $sd = $ai->draftPick($save, Brand::Smackdown, $n);
            expect($sd?->wrestler->weight_class)->toBeIn([WeightClass::Cruiserweight, WeightClass::LightHeavyweight])
                ->and($sd?->wrestler->roster_type)->not->toBe(RosterType::Diva);
        }
    });

    it('picks from the whole pool when the roll misses', function () {
        config(['gm.draft.ai_focus_from' => 101]);
        $save = GameBuilder::newSave();

        $picks = collect(range(1, 40))->map(fn (int $n) => app(AiGm::class)->draftPick($save, Brand::Raw, $n));

        expect($picks->filter(fn (?SaveWrestler $w) => $w?->wrestler->roster_type !== RosterType::Diva)->count())->toBeGreaterThan(20);
    });

    it('falls back to anyone when no focus wrestlers are left', function () {
        config(['gm.draft.ai_focus_from' => 1]);
        $save = GameBuilder::newSave();
        SaveWrestler::where('save_id', $save->id)
            ->whereHas('wrestler', fn ($q) => $q->where('roster_type', RosterType::Diva))
            ->update(['brand' => Brand::Smackdown]);

        expect(app(AiGm::class)->draftPick($save, Brand::Raw, 1))->not->toBeNull();
    });

    it('focuses on about the share of picks the roll range gives', function () {
        // Nobody is signed here, so keep the end-of-draft title fill and the focus cap out of it.
        config(['gm.initial_draft_max_picks' => 1000, 'gm.ai.draft.max_focus_picks' => 1000]);
        $save = GameBuilder::newSave();
        $ai = app(AiGm::class);
        $focus = collect(range(1, 300))->filter(fn (int $n) => AiGm::isDraftFocus(Brand::Raw, $ai->draftPick($save, Brand::Raw, $n)))->count();

        // 41% focus rolls + ~17% of the rest by chance ≈ 51%, against ~17% if it were plain random.
        expect($focus / 300)->toBeGreaterThan(0.4)->toBeLessThan(0.62);
    });
});

describe('Crowning champions one title at a time (house rule)', function () {
    function crowningSave(): Save
    {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'John Cena', 'Shawn Michaels', 'Trish Stratus'], smackdown: ['Batista', 'Rey Mysterio', 'Booker T', 'Christian', 'Kurt Angle']);
        $save->update(['stage' => SaveStage::AssigningTitles]);

        return $save->refresh();
    }

    function crownStep(Save $save): ?string
    {
        return app(ChampionshipAssignment::class)->nextToCrown($save->refresh())?->key->value;
    }

    it('goes world, second tier, tag, then special, whether each is awarded or left vacant', function () {
        $save = crowningSave();
        $this->get(route('gm.titles.assign', $save))->assertInertia(fn ($page) => $page->where('current_id', Championship::where('key', 'wwe')->value('id')));

        expect(crownStep($save))->toBe('wwe');
        $this->post(route('gm.titles.assign.store', [$save, Championship::where('key', 'wwe')->first()]), ['save_wrestler_ids' => [GameBuilder::wrestler($save, 'Edge')->id]]);

        expect(crownStep($save))->toBe('intercontinental');
        $this->post(route('gm.titles.assign.vacant', [$save, Championship::where('key', 'intercontinental')->first()]))->assertRedirect();

        expect(crownStep($save))->toBe('world_tag_team');
        $this->post(route('gm.titles.assign.store', [$save, Championship::where('key', 'world_tag_team')->first()]), ['save_wrestler_ids' => [GameBuilder::wrestler($save, 'Kane')->id, GameBuilder::wrestler($save, 'John Cena')->id]]);

        expect(crownStep($save))->toBe('womens');
        $this->post(route('gm.titles.assign.store', [$save, Championship::where('key', 'womens')->first()]), ['save_wrestler_ids' => [GameBuilder::wrestler($save, 'Trish Stratus')->id]]);

        expect(crownStep($save))->toBeNull()
            ->and(TitleReign::where('save_id', $save->id)->where('championship_id', Championship::where('key', 'intercontinental')->value('id'))->first()?->isVacant())->toBeTrue();
    });

    it('never gives one wrestler two titles', function () {
        $save = crowningSave();
        $edge = GameBuilder::wrestler($save, 'Edge');
        $assignment = app(ChampionshipAssignment::class);
        $assignment->assign($save, Championship::where('key', 'wwe')->firstOrFail(), [$edge->id]);

        expect($assignment->eligible($save, Championship::where('key', 'intercontinental')->firstOrFail())->pluck('id'))->not->toContain($edge->id)
            ->and(fn () => $assignment->assign($save, Championship::where('key', 'intercontinental')->firstOrFail(), [$edge->id]))->toThrow(GameRuleException::class);
    });

    it("won't leave a decided title vacant or touch the rival's titles", function () {
        $save = crowningSave();
        $wwe = Championship::where('key', 'wwe')->firstOrFail();
        app(ChampionshipAssignment::class)->assign($save, $wwe, [GameBuilder::wrestler($save, 'Edge')->id]);

        $this->from(route('gm.titles.assign', $save))->post(route('gm.titles.assign.vacant', [$save, $wwe]))->assertSessionHasErrors('game');
        $this->post(route('gm.titles.assign.vacant', [$save, Championship::where('key', 'world_heavyweight')->first()]))->assertForbidden();
    });

    it("only offers Divas the Women's Championship, and refuses a Diva for a men's title", function () {
        $save = crowningSave();
        $trish = GameBuilder::wrestler($save, 'Trish Stratus');
        $assignment = app(ChampionshipAssignment::class);
        $offered = fn (string $key) => $assignment->eligible($save, Championship::where('key', $key)->firstOrFail())->pluck('id');

        foreach (['wwe', 'intercontinental', 'world_tag_team'] as $key) {
            expect($offered($key))->not->toContain($trish->id)->not->toBeEmpty();
        }

        expect($offered('womens')->all())->toBe([$trish->id]);

        $this->get(route('gm.titles.assign', $save))->assertInertia(fn ($page) => $page
            ->where('championships', fn ($titles) => collect($titles)->every(
                fn ($t) => $t['key'] === 'womens' || collect($t['eligible'])->doesntContain('roster_type', 'diva'),
            )));

        $this->from(route('gm.titles.assign', $save))
            ->post(route('gm.titles.assign.store', [$save, Championship::where('key', 'wwe')->first()]), ['save_wrestler_ids' => [$trish->id]])
            ->assertSessionHasErrors('game');

        expect(app(TitleService::class)->currentRow($save, Championship::where('key', 'wwe')->firstOrFail()))->toBeNull();
    });

    it('only offers the Cruiserweight Championship to Cruiserweights and Light Heavyweights', function () {
        $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista', 'Undertaker', 'Rey Mysterio', 'Christian', 'Melina'], player: Brand::Smackdown);
        $save->update(['stage' => SaveStage::AssigningTitles]);
        $cruiser = Championship::where('key', 'cruiserweight')->firstOrFail();
        $assignment = app(ChampionshipAssignment::class);

        // Rey Mysterio is a Cruiserweight and Christian a Light Heavyweight; Batista,
        // Undertaker and Melina (a Diva) aren't eligible.
        expect($assignment->eligible($save, $cruiser)->map->name()->sort()->values()->all())->toBe(['Christian', 'Rey Mysterio']);

        foreach (['Batista', 'Undertaker', 'Melina'] as $name) {
            expect(fn () => $assignment->assign($save, $cruiser, [GameBuilder::wrestler($save, $name)->id]))->toThrow(GameRuleException::class);
        }

        $this->post(route('gm.titles.assign.store', [$save, $cruiser]), ['save_wrestler_ids' => [GameBuilder::wrestler($save, 'Christian')->id]])->assertRedirect();

        expect(app(TitleService::class)->currentReign($save, $cruiser)?->holderName())->toBe('Christian');
    });

    it('has the rival GM crown a champion for every title it can fill, each a different wrestler', function () {
        $save = crowningSave();
        app(ChampionshipAssignment::class)->autoAssign($save, Brand::Smackdown);

        $reigns = TitleReign::where('save_id', $save->id)->where('brand', Brand::Smackdown)->get();
        $holders = $reigns->flatMap(fn (TitleReign $r) => $r->holderIds());

        expect($reigns->filter->isVacant()->count())->toBe(0)
            ->and($reigns)->toHaveCount(4)
            ->and($holders->unique()->count())->toBe($holders->count());
    });
});
