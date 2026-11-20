<?php

use App\Enums\ReignEndReason;
use App\Enums\ShowType;
use App\Enums\TitleHolderType;
use App\Models\Championship;
use App\Models\Save;
use App\Models\Show;
use App\Models\TitleReign;
use App\Services\Titles\TitleRecords;
use App\Services\Titles\TitleStats;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| Title History stats (house rule, 2026-11-21): per title, and across all
| titles (most titles won, Grand Slam champions). Holders are always the
| superstars themselves, never a faction's name.
*/

/**
 * A reign written straight into the lineage, so a long history doesn't need
 * every match aired.
 *
 * @param  list<string>  $names
 */
function statReign(Save $save, string $key, array $names, int $won, ?int $lost = null, ReignEndReason $end = ReignEndReason::LostMatch, int $defenses = 0): TitleReign
{
    $title = Championship::where('key', $key)->firstOrFail();
    $ids = array_map(fn (string $n): int => GameBuilder::wrestler($save, $n)->id, $names);

    return TitleReign::create([
        'save_id' => $save->id,
        'championship_id' => $title->id,
        'brand' => $title->brand,
        'reign_number' => TitleReign::where('save_id', $save->id)->where('championship_id', $title->id)->count() + 1,
        'holder_type' => count($ids) > 1 ? TitleHolderType::TagTeam : TitleHolderType::Wrestler,
        'save_wrestler_id' => count($ids) === 1 ? $ids[0] : null,
        'holder_snapshot' => ['names' => $names, 'ids' => $ids],
        'season' => 1,
        'won_week' => $won,
        'lost_week' => $lost,
        'end_reason' => $lost === null ? ReignEndReason::Active : $end,
        'defenses' => $defenses,
    ]);
}

function tagSave(): Save
{
    return GameBuilder::inSeason(
        raw: ['Edge', 'Christian', 'Matt Hardy', 'Jeff Hardy', 'The Great Khali', 'John Cena'],
        smackdown: ['Batista', 'Rey Mysterio'],
    );
}

it('counts tag reigns by superstar and by team, named individually', function () {
    $save = tagSave();
    // The example lineage: Matt Hardy holds the most reigns as a superstar,
    // Edge & Christian as a team.
    statReign($save, 'world_tag_team', ['Edge', 'Christian'], 1, 3);
    statReign($save, 'world_tag_team', ['Edge', 'Matt Hardy'], 3, 5);
    statReign($save, 'world_tag_team', ['Jeff Hardy', 'Matt Hardy'], 5, 7);
    statReign($save, 'world_tag_team', ['The Great Khali', 'Matt Hardy'], 7, 9);
    statReign($save, 'world_tag_team', ['Edge', 'Christian'], 9, null, defenses: 2);

    $title = Championship::where('key', 'world_tag_team')->firstOrFail();
    $stats = app(TitleStats::class)->perTitle($save, collect([$title]))[$title->id];

    expect($stats['most_reigns_superstar'])->toMatchArray(['holder' => 'Edge, Matt Hardy', 'value' => 3, 'tied' => true])
        ->and($stats['most_reigns_team'])->toMatchArray(['holder' => 'Edge & Christian', 'value' => 2])
        ->and($stats['champions'])->toBe(5)
        ->and($stats['teams'])->toBe(4)
        ->and($stats['defenses'])->toBe(2);
});

it('names a faction’s reigns by its members, so the records survive it disbanding', function () {
    $save = tagSave();
    // Matt & Jeff Hardy are Team Xtreme; the records still say their names.
    statReign($save, 'world_tag_team', ['Jeff Hardy', 'Matt Hardy'], 1, 3);
    statReign($save, 'world_tag_team', ['Matt Hardy', 'Jeff Hardy'], 5);

    $title = Championship::where('key', 'world_tag_team')->firstOrFail();

    expect(app(TitleRecords::class)->records($save, $title)['most_reigns'])->toMatchArray(['holder' => 'Jeff Hardy & Matt Hardy', 'value' => 2])
        ->and(app(TitleStats::class)->perTitle($save, collect([$title]))[$title->id]['most_reigns_team']['value'])->toBe(2);
});

it('counts the times a title was vacated, and why', function () {
    $save = tagSave();
    statReign($save, 'wwe', ['Edge'], 1, 2, ReignEndReason::Injured);
    statReign($save, 'wwe', ['John Cena'], 3, 4, ReignEndReason::LostMatch);
    statReign($save, 'wwe', ['Christian'], 4, 6, ReignEndReason::Released);
    statReign($save, 'wwe', ['Edge'], 7, 8, ReignEndReason::Injured);
    statReign($save, 'wwe', ['Matt Hardy'], 9);

    $title = Championship::where('key', 'wwe')->firstOrFail();
    $stats = app(TitleStats::class)->perTitle($save, collect([$title]))[$title->id];

    expect($stats['vacated'])->toBe(3)
        ->and($stats['vacated_reasons'])->toBe(['injured' => 2, 'released' => 1])
        ->and($stats['champions'])->toBe(4)
        ->and($stats['teams'])->toBeNull()
        ->and($stats['most_reigns_superstar'])->toBeNull();
});

it('counts Title matches held on PPV and the match types most and least contested', function () {
    $save = tagSave();
    GameBuilder::giveTitle($save, 'wwe', ['Edge']);
    $wwe = Championship::where('key', 'wwe')->value('id');

    // Week 1: two Singles defenses... one title per card, so one a week.
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['John Cena']], [], $wwe);
    FixedOutcomes::queue('Edge');
    app(WeekResolver::class)->resolve($save->refresh());

    Card::book($save->refresh(), Card::show($save), 1, Card::singles(), [['Edge'], ['Christian']], [], $wwe);
    FixedOutcomes::queue('Edge');
    app(WeekResolver::class)->resolve($save->refresh());

    Card::book($save->refresh(), Card::show($save), 1, Card::type('hardcore', 'Single'), [['Edge'], ['Matt Hardy']], [], $wwe);
    FixedOutcomes::queue('Edge');
    app(WeekResolver::class)->resolve($save->refresh());

    // Count the last one as a PPV's.
    Show::where('save_id', $save->id)->where('week_number', 3)->where('brand', 'raw')->update(['show_type' => ShowType::Ppv]);

    $stats = app(TitleStats::class)->perTitle($save->refresh(), Championship::where('id', $wwe)->get())[$wwe];

    expect($stats['title_matches'])->toBe(3)
        ->and($stats['ppv_title_matches'])->toBe(1)
        ->and($stats['most_type'])->toBe(['types' => [Card::singles()->name], 'value' => 2])
        ->and($stats['least_type'])->toBe(['types' => [Card::type('hardcore', 'Single')->name], 'value' => 1])
        ->and($stats['defenses'])->toBe(3);
});

it('finds the superstar with the most different titles, across both brands', function () {
    $save = tagSave();
    statReign($save, 'intercontinental', ['Edge'], 1, 2);
    statReign($save, 'world_heavyweight', ['Matt Hardy'], 1, 2);
    statReign($save, 'intercontinental', ['Matt Hardy'], 3, 4);
    // A second reign with a title he's held doesn't count again.
    statReign($save, 'world_heavyweight', ['Matt Hardy'], 5);

    $most = app(TitleStats::class)->overall($save)['most_titles'];

    expect($most)->toMatchArray(['holder' => 'Matt Hardy', 'value' => 2])
        ->and($most['titles'])->toBe(['Intercontinental Championship', 'World Heavyweight Championship']);
});

it('lists Grand Slam champions with the first of each title and when the set was done', function () {
    $save = tagSave();
    // Edge: a world title, a secondary title and a tag title, on two brands.
    statReign($save, 'united_states', ['Edge'], 2, 4);
    statReign($save, 'wwe', ['Edge'], 6, 8);
    statReign($save, 'wwe_tag_team', ['Edge', 'Christian'], 10, 12);
    statReign($save, 'world_tag_team', ['Edge', 'Christian'], 14);
    // Christian: no world title. John Cena: the Cruiserweight and Women's
    // titles don't count (and he has no tag title).
    statReign($save, 'intercontinental', ['Christian'], 1, 3);
    statReign($save, 'world_heavyweight', ['John Cena'], 1, 3);
    statReign($save, 'intercontinental', ['John Cena'], 4, 5);

    $slams = app(TitleStats::class)->overall($save)['grand_slams'];

    expect($slams)->toHaveCount(1)
        ->and($slams[0]['holder'])->toBe('Edge')
        ->and(collect($slams[0]['titles'])->pluck('championship', 'group')->all())->toBe([
            'world' => 'WWE Championship',
            'secondary' => 'United States Championship',
            'tag' => 'WWE Tag Team Championship',
        ])
        ->and($slams[0]['attained_week'])->toBe(10);
});

it('names nobody for the most titles until someone has won 2', function () {
    $save = tagSave();
    statReign($save, 'wwe', ['Edge'], 1);
    statReign($save, 'intercontinental', ['Christian'], 1);

    expect(app(TitleStats::class)->overall($save)['most_titles'])->toBeNull();
});

it('sends the stats to Title History', function () {
    $save = tagSave();
    statReign($save, 'wwe', ['Edge'], 1, 2);
    statReign($save, 'intercontinental', ['Edge'], 3);

    $this->get(route('gm.titles.history', $save))
        ->assertInertia(fn ($page) => $page->component('gm/titles/history')
            ->has('stats.per_title', 8)
            ->has('stats.overall.grand_slams')
            ->where('stats.overall.most_titles.holder', 'Edge')
            ->where('stats.overall.most_titles.holder_parts.0.name', 'Edge'));
});
