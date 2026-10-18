<?php

use App\Enums\Brand;
use App\Enums\DraftPickType;
use App\Enums\LedgerCategory;
use App\Enums\Morale;
use App\Enums\SaveStage;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\DraftPick;
use App\Models\LedgerEntry;
use App\Models\SaveWrestler;
use App\Models\TitleReign;
use App\Models\User;
use App\Services\Draft\DraftService;
use App\Services\Game\NewGameService;
use App\Services\Titles\ChampionshipAssignment;
use Tests\Support\GameBuilder;

/*
| §1 Setup: new save, initial draft, title assignment.
*/

it('creates a save with every wrestler in Free Agency and $15M per brand, granted once', function () {
    $save = GameBuilder::newSave();

    expect(SaveWrestler::where('save_id', $save->id)->count())->toBe(98)
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
        ->and(SaveWrestler::where('save_id', $save->id)->whereNull('brand')->count())->toBe(38)
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

it('makes the AI pick automatically when a new game starts with its turn', function () {
    GameBuilder::seedCatalog();

    foreach (range(1, 6) as $i) {
        $save = app(NewGameService::class)->create(User::factory()->create(), 'X', Brand::Raw, "auto-{$i}");

        if ($save->draft_order[0] === 'smackdown') {
            expect(DraftPick::where('save_id', $save->id)->count())->toBe(1)
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
