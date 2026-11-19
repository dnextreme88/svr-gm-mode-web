<?php

use App\Enums\Brand;
use App\Enums\DraftPhase;
use App\Enums\DraftPickType;
use App\Enums\SaveStage;
use App\Exceptions\GameRuleException;
use App\Models\DraftPick;
use App\Models\InterbrandSeries;
use App\Models\NewsEvent;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Season;
use App\Services\Ppv\ReDraftService;
use Tests\Support\GameBuilder;

/*
| The bigger re-draft (house rule, 2026-11-17): the immunity tag protects 3,
| the manual draft pick takes the holder's first 2 picks, and each brand
| drafts 10.
*/

function biggerRedraft(Brand $tags): Save
{
    $save = GameBuilder::inSeason(size: 20);
    GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
    $save->update(['stage' => SaveStage::Redrafting, 'current_week' => 53, 'current_season' => 2]);
    Season::create(['save_id' => $save->id, 'number' => 2, 'started_week' => 53]);
    InterbrandSeries::create([
        'save_id' => $save->id, 'season' => 1, 'week_number' => 52, 'match_ids' => [],
        'raw_wins' => $tags === Brand::Raw ? 2 : 1, 'smackdown_wins' => $tags === Brand::Raw ? 1 : 2,
        'winner_brand' => $tags, 'immunity_brand' => $tags,
    ]);

    return $save->refresh();
}

/**
 * @return list<int>
 */
function eligibleIds(Save $save, Brand $brand, int $count, array $skip = []): array
{
    return app(ReDraftService::class)->eligibleFrom($save, $brand, $skip)->take($count)->pluck('id')->map(fn ($id) => (int) $id)->all();
}

it('protects exactly 3 and takes exactly 2 with the manual pick', function () {
    $save = biggerRedraft(Brand::Raw);
    $service = app(ReDraftService::class);
    $series = $service->prepare($save);

    expect($service->immunityNeeded($save, $series))->toBe(3)
        ->and($service->manualNeeded($save, $series))->toBe(2)
        ->and(fn () => $service->setImmunity($save, eligibleIds($save, Brand::Raw, 2)))->toThrow(GameRuleException::class, 'exactly 3');

    $protected = eligibleIds($save, Brand::Raw, 3);
    $service->setImmunity($save, $protected);
    $manual = eligibleIds($save, Brand::Smackdown, 2);

    expect(fn () => $service->setHandpick($save, [$manual[0]]))->toThrow(GameRuleException::class, 'exactly 2');

    $service->setHandpick($save, $manual);

    while ($service->step($save) !== null) {
        // every pick
    }

    $picks = DraftPick::where('save_id', $save->id)->where('phase', DraftPhase::WrestlemaniaReset)->orderBy('pick_number')->get();
    $raw = $picks->where('brand', Brand::Raw)->values();

    expect($picks)->toHaveCount(20)
        ->and($picks->where('skipped', true))->toBeEmpty()
        // Raw's first two picks are its manual choices, in order; the rest are random.
        ->and($raw->take(2)->pluck('save_wrestler_id')->all())->toBe($manual)
        ->and($raw->take(2)->every(fn (DraftPick $p): bool => $p->pick_type === DraftPickType::Manual))->toBeTrue()
        ->and($picks->where('pick_type', DraftPickType::Manual))->toHaveCount(2)
        ->and($picks->pluck('save_wrestler_id')->intersect($protected))->toBeEmpty();

    foreach ($protected as $id) {
        expect(SaveWrestler::find($id)->brand)->toBe(Brand::Raw);
    }
});

it('refuses a protected superstar for the manual pick', function () {
    $save = biggerRedraft(Brand::Smackdown);
    InterbrandSeries::where('save_id', $save->id)->update(['immunity_brand' => Brand::Raw, 'winner_brand' => Brand::Raw]);
    $service = app(ReDraftService::class);
    $series = $service->prepare($save->refresh());

    // Raw is the player: it protects 3, then can't hand-pick from its own list.
    $service->setImmunity($save, eligibleIds($save, Brand::Raw, 3));

    expect(fn () => $service->setHandpick($save, eligibleIds($save, Brand::Raw, 2)))->toThrow(GameRuleException::class, 'non-champions');
});

it('lets a rival GM protect its 3 and hand-pick 2 of the player’s', function () {
    $save = biggerRedraft(Brand::Smackdown);
    $series = app(ReDraftService::class)->prepare($save);
    $champion = GameBuilder::wrestler($save, 'Batista')->id;

    expect($series->exemptIds())->toHaveCount(3)
        ->and($series->handpickIds())->toHaveCount(2)
        ->and($series->exemptIds())->not->toContain($champion)
        ->and(SaveWrestler::whereIn('id', $series->exemptIds())->pluck('brand')->unique()->all())->toBe([Brand::Smackdown])
        ->and(SaveWrestler::whereIn('id', $series->handpickIds())->pluck('brand')->unique()->all())->toBe([Brand::Raw]);
});

it('protects fewer when fewer are eligible', function () {
    $save = biggerRedraft(Brand::Raw);
    $service = app(ReDraftService::class);
    $keep = eligibleIds($save, Brand::Raw, 2);
    SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Raw)->whereNotIn('id', $keep)->update(['brand' => null]);

    expect($service->immunityNeeded($save, $service->prepare($save)))->toBe(2);
});

it('names every protected and hand-picked superstar in the re-draft headline', function () {
    $save = biggerRedraft(Brand::Raw);
    app(ReDraftService::class)->run($save);

    $series = InterbrandSeries::where('save_id', $save->id)->first();
    $names = fn (array $ids) => SaveWrestler::with('wrestler')->whereIn('id', $ids)->get()->map->name()->all();
    $payload = NewsEvent::where('save_id', $save->id)->where('type', 'redraft_results')->sole()->payload;

    foreach ($names($series->exemptIds()) as $name) {
        expect($payload['immune'])->toContain($name);
    }

    foreach ($names($series->handpickIds()) as $name) {
        expect($payload['handpick'])->toContain($name);
    }
});
