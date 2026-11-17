<?php

use App\Enums\Brand;
use App\Enums\FixedSlotKind;
use App\Enums\InjurySeverity;
use App\Enums\MatchMethod;
use App\Enums\NewsEventType;
use App\Enums\PpvKey;
use App\Enums\RosterType;
use App\Enums\ShowType;
use App\Exceptions\GameRuleException;
use App\Models\MatchSlot;
use App\Models\NewsEvent;
use App\Models\PpvQualifier;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Card\CardService;
use App\Services\Injuries\InjuryService;
use App\Services\Matches\MatchResolver;
use App\Services\Ppv\PpvCardService;
use App\Services\Ppv\QualifierService;
use App\Services\Week\BookingOrder;
use App\Services\Week\ShowScheduler;
use App\Services\Week\WeekResolver;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| House rule (2026-11-17): Qualifying Matches. Match 5 on both brands' weekly
| shows is a fixed Ladder Triple Threat in the 5 weeks before Survivor Series
| (weeks 30–34) and a Normal Triple Threat in the 3 weeks before WrestleMania
| (weeks 49–51). Each winner is locked onto the PPV card.
*/

function qualifierSave(int $week, Brand $player = Brand::Raw): Save
{
    $save = GameBuilder::inSeason(size: 16, player: $player);
    $save->update(['current_week' => $week]);
    app(ShowScheduler::class)->ensureWeek($save, $week);

    return $save->refresh();
}

function weeklyShow(Save $save, Brand $brand, int $week): Show
{
    return Show::where('save_id', $save->id)->where('week_number', $week)->where('brand', $brand)->where('show_type', ShowType::Weekly)->sole();
}

function qualifierSlot(Show $show): MatchSlot
{
    return MatchSlot::with(['matchType', 'participants'])->where('show_id', $show->id)->where('position', 5)->sole();
}

/**
 * @return list<int>
 */
function menOf(Save $save, Brand $brand, int $count, array $skip = []): array
{
    return array_values(array_map('intval', SaveWrestler::where('save_id', $save->id)->where('brand', $brand)
        ->whereHas('wrestler', fn ($q) => $q->where('roster_type', '!=', RosterType::Diva))
        ->whereNotIn('id', $skip)->orderBy('id')->limit($count)->pluck('id')->all()));
}

function anySlot(Save $save): int
{
    return (int) MatchSlot::whereHas('show', fn ($q) => $q->where('save_id', $save->id))->value('id');
}

function qualify(Save $save, PpvKey $ppv, Brand $brand, SaveWrestler $wrestler, int $week): PpvQualifier
{
    return PpvQualifier::create([
        'save_id' => $save->id,
        'season' => 1,
        'ppv' => $ppv,
        'brand' => $brand,
        'save_wrestler_id' => $wrestler->id,
        'match_slot_id' => qualifierSlot(weeklyShow($save, $brand, $week))->id,
        'week_number' => $week,
    ]);
}

it('runs Survivor Series qualifiers in weeks 30–34 and Money in the Bank ones in weeks 49–51', function () {
    GameBuilder::newSave();
    $service = app(QualifierService::class);
    $weeks = collect(range(1, 52))->mapWithKeys(fn (int $w) => [$w => $service->window($w)?->value])->filter();

    expect($weeks->keys()->all())->toBe([30, 31, 32, 33, 34, 49, 50, 51])
        ->and($weeks->unique()->values()->all())->toBe(['survivor_series', 'wrestlemania']);
});

it('makes Match 5 a fixed Ladder Triple Threat on both brands before Survivor Series', function () {
    $save = qualifierSave(30);

    foreach ([Brand::Raw, Brand::Smackdown] as $brand) {
        $slot = qualifierSlot(weeklyShow($save, $brand, 30));

        expect($slot->fixed_kind)->toBe(FixedSlotKind::QualifyingMatch)
            ->and($slot->matchType->family->value)->toBe('ladder')
            ->and($slot->matchType->variant)->toBe('Triple Threat');
    }
});

it('makes Match 5 a Normal Triple Threat before WrestleMania, and leaves other weeks alone', function () {
    $save = qualifierSave(49);
    $slot = qualifierSlot(weeklyShow($save, Brand::Raw, 49));

    expect($slot->matchType->family->value)->toBe('single')
        ->and($slot->matchType->variant)->toBe('Triple Threat');

    $other = qualifierSave(29);

    expect(MatchSlot::where('show_id', weeklyShow($other, Brand::Raw, 29)->id)->where('fixed_kind', FixedSlotKind::QualifyingMatch)->exists())->toBeFalse();
});

it('won’t let the qualifier be booked, cleared or moved like a normal slot', function () {
    $save = qualifierSave(30);
    $show = weeklyShow($save, Brand::Raw, 30);
    $cards = app(CardService::class);

    expect(qualifierSlot($show)->isFixed())->toBeTrue()
        ->and(fn () => $cards->clear($show, 5))->toThrow(GameRuleException::class, 'fixed')
        ->and(fn () => $cards->reorder($show, [5, 1, 2, 3, 4, 6]))->toThrow(GameRuleException::class);
});

it('takes 3 different eligible men, refusing Divas and those already qualified', function () {
    $save = qualifierSave(31);
    $show = weeklyShow($save, Brand::Raw, 31);
    $service = app(QualifierService::class);
    [$a, $b, $c, $d] = menOf($save, Brand::Raw, 4);
    qualify($save, PpvKey::SurvivorSeries, Brand::Raw, SaveWrestler::find($d), 31);
    $diva = SaveWrestler::where('save_id', $save->id)->where('brand', Brand::Raw)->whereHas('wrestler', fn ($q) => $q->where('roster_type', RosterType::Diva))->first();

    expect(fn () => $service->setEntrants($show, Brand::Raw, [$a, $b]))->toThrow(GameRuleException::class, 'Pick 3')
        ->and(fn () => $service->setEntrants($show, Brand::Raw, [$a, $a, $b]))->toThrow(GameRuleException::class, 'Pick 3')
        ->and(fn () => $service->setEntrants($show, Brand::Raw, [$a, $b, $d]))->toThrow(GameRuleException::class, 'Qualifying Match');

    if ($diva !== null) {
        expect(fn () => $service->setEntrants($show, Brand::Raw, [$a, $b, $diva->id]))->toThrow(GameRuleException::class, 'Qualifying Match');
    }

    $service->setEntrants($show, Brand::Raw, [$a, $b, $c]);

    expect(qualifierSlot($show)->sides())->toBe([[$a], [$b], [$c]])
        ->and($service->missing($show))->toBeFalse();
});

it('books the qualifier through the series endpoint', function () {
    $save = qualifierSave(30);
    $show = weeklyShow($save, Brand::Raw, 30);
    $ids = menOf($save, Brand::Raw, 3);

    $this->putJson(route('gm.card.series', [$save, $show, 5]), ['ids' => $ids])->assertNoContent();

    expect(qualifierSlot($show)->sides())->toBe([[$ids[0]], [$ids[1]], [$ids[2]]]);
});

it('won’t air the week until the player books the qualifier', function () {
    $save = qualifierSave(30);
    $show = weeklyShow($save, Brand::Raw, 30);

    expect(app(QualifierService::class)->missing($show))->toBeTrue()
        ->and(fn () => app(BookingOrder::class)->assertReadyToAir($save, 30))->toThrow(GameRuleException::class, 'Qualifying Match');
});

it('locks in the winner on any win, a DQ included, and lets the losers try again', function () {
    $save = qualifierSave(30);
    $show = weeklyShow($save, Brand::Raw, 30);
    [$a, $b, $c] = menOf($save, Brand::Raw, 3);
    app(QualifierService::class)->setEntrants($show, Brand::Raw, [$a, $b, $c]);
    app()->instance(MatchResolver::class, FixedOutcomes::queue(SaveWrestler::find($b)->name(), MatchMethod::Dq));

    app(WeekResolver::class)->resolve($save->refresh());

    $row = PpvQualifier::where('save_id', $save->id)->where('brand', Brand::Raw)->sole();

    expect($row->save_wrestler_id)->toBe($b)
        ->and($row->ppv)->toBe(PpvKey::SurvivorSeries)
        ->and(NewsEvent::where('save_id', $save->id)->where('type', NewsEventType::SurvivorSeriesQualified)->exists())->toBeTrue();

    $next = app(QualifierService::class)->eligible(weeklyShow($save->refresh(), Brand::Raw, 31), Brand::Raw)->pluck('id')->all();

    expect($next)->toContain($a)->toContain($c)->not->toContain($b);
});

it('has the rival pick qualifier entrants the match leaves at 70% fatigue or less', function () {
    $save = qualifierSave(30);
    $show = weeklyShow($save, Brand::Smackdown, 30);
    $tired = menOf($save, Brand::Smackdown, 6);
    SaveWrestler::whereIn('id', array_slice($tired, 0, 3))->update(['fatigue' => 60]);

    app(QualifierService::class)->aiEntrants($show, Brand::Smackdown, $save->rng('test.qualifier'));

    $slot = qualifierSlot($show);

    expect($slot->participants)->toHaveCount(3);

    foreach ($slot->participants as $p) {
        expect(SaveWrestler::find($p->save_wrestler_id)->fatigue + $slot->matchType->fatigueCost())->toBeLessThanOrEqual((int) config('gm.card.randomize_max_fatigue'));
    }
});

it('locks Survivor Series qualifiers onto the 5 vs 5 and leaves the open spots to pick', function () {
    $save = qualifierSave(35);
    $men = menOf($save, Brand::Raw, 3);

    foreach ($men as $i => $id) {
        PpvQualifier::create(['match_slot_id' => anySlot($save), 'save_id' => $save->id, 'season' => 1, 'ppv' => PpvKey::SurvivorSeries, 'brand' => Brand::Raw, 'save_wrestler_id' => $id, 'week_number' => 30 + $i]);
    }

    $show = Show::where('save_id', $save->id)->where('week_number', 35)->whereNull('brand')->sole();
    $cards = app(PpvCardService::class);
    $cards->seatSurvivorQualifiers($show);

    expect($cards->lockedEntrants($show, Brand::Raw))->toBe($men)
        ->and($cards->entrantsNeeded($show, Brand::Raw))->toBe(2);

    $others = menOf($save, Brand::Raw, 2, $men);
    $cards->setEntrants($show, Brand::Raw, $others);
    $side = collect(MatchSlot::where('show_id', $show->id)->where('position', 7)->sole()->sides()[0])->sort()->values()->all();

    expect($side)->toBe(collect([...$men, ...$others])->sort()->values()->all());
});

it('opens a seriously injured qualifier’s spot on the Survivor Series card', function () {
    $save = qualifierSave(35);
    $men = menOf($save, Brand::Raw, 2);

    foreach ($men as $i => $id) {
        PpvQualifier::create(['match_slot_id' => anySlot($save), 'save_id' => $save->id, 'season' => 1, 'ppv' => PpvKey::SurvivorSeries, 'brand' => Brand::Raw, 'save_wrestler_id' => $id, 'week_number' => 30 + $i]);
    }

    app(InjuryService::class)->injure(SaveWrestler::find($men[0]), InjurySeverity::Serious, 35, $save->rng('test.injury'));
    $show = Show::where('save_id', $save->id)->where('week_number', 35)->whereNull('brand')->sole();
    $cards = app(PpvCardService::class);
    $cards->seatSurvivorQualifiers($show);

    expect($cards->lockedEntrants($show, Brand::Raw))->toBe([$men[1]])
        ->and(PpvQualifier::where('save_wrestler_id', $men[0])->value('vacated_reason'))->toBe('injured');
});

it('seats Money in the Bank qualifiers on the ladder and locks their spots', function () {
    $save = GameBuilder::inSeason(size: 16);
    GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
    GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
    $men = menOf($save, Brand::Raw, 2, [GameBuilder::wrestler($save, 'John Cena')->id]);

    $save->update(['current_week' => 52]);
    app(ShowScheduler::class)->ensureWeek($save, 52);
    $show = Show::where('save_id', $save->id)->where('week_number', 52)->whereNull('brand')->sole();
    foreach ($men as $i => $id) {
        PpvQualifier::create(['match_slot_id' => anySlot($save), 'save_id' => $save->id, 'season' => 1, 'ppv' => PpvKey::Wrestlemania, 'brand' => Brand::Raw, 'save_wrestler_id' => $id, 'week_number' => 49 + $i]);
    }

    app(PpvCardService::class)->seatMitbQualifiers($show);
    $options = app(PpvCardService::class)->seriesOptions($show, Brand::Raw)[7];

    expect(array_filter($options['current']))->toHaveCount(2)
        ->and(collect($options['current'])->filter()->sort()->values()->all())->toBe($men)
        ->and($options['locked'])->toHaveCount(2);

    $open = collect($options['eligible'])->diff($men)->first();

    expect(fn () => app(PpvCardService::class)->setSeriesEntrants($show, 7, Brand::Raw, [$open, $men[0], collect($options['eligible'])->diff($men)->values()[1]]))->toThrow(GameRuleException::class);
});

it('keeps the rival’s qualifiers out of heavy matches until the PPV', function () {
    $save = qualifierSave(31);
    $id = menOf($save, Brand::Smackdown, 1)[0];
    PpvQualifier::create(['match_slot_id' => anySlot($save), 'save_id' => $save->id, 'season' => 1, 'ppv' => PpvKey::SurvivorSeries, 'brand' => Brand::Smackdown, 'save_wrestler_id' => $id, 'week_number' => 30]);

    expect(app(QualifierService::class)->protectedIds($save, Brand::Smackdown, 31))->toBe([$id])
        ->and(app(QualifierService::class)->protectedIds($save, Brand::Smackdown, 36))->toBe([]);

    app(WeekResolver::class)->resolve($save->refresh());

    $slots = MatchSlot::with('matchType')->whereHas('show', fn ($q) => $q->where('save_id', $save->id)->where('week_number', 31)->where('brand', Brand::Smackdown))
        ->whereHas('participants', fn ($q) => $q->where('save_wrestler_id', $id)->where('role', 'competitor'))
        ->get();

    foreach ($slots as $slot) {
        expect(in_array($slot->matchType->family->value, ['single', 'tag_team'], true))->toBeTrue();
    }
});
