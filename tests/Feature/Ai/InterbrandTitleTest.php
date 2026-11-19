<?php

use App\Enums\Brand;
use App\Enums\PpvKey;
use App\Models\Championship;
use App\Models\MatchSlot;
use App\Models\PpvEvent;
use App\Models\Save;
use App\Models\Show;
use App\Services\Ai\AiGm;
use App\Services\Week\ShowScheduler;
use Tests\Support\GameBuilder;

/*
| House rule (2026-11-15): on an interbrand PPV the rival GM books at least
| one title match in its own slots, trying world, secondary, tag, then
| special, even when its usual title rolls all miss.
*/

beforeEach(function () {
    // The usual title rolls never land: only the guarantee books a title.
    config([
        'gm.ai.titles.main_event_chance' => ['weekly' => 0.0, 'ppv' => 0.0],
        'gm.ai.titles.other_slot_chance' => 0.0,
        'gm.ai.titles.champion_vs_contender_chance' => 0.0,
        'gm.ai.titles.number_one_contender_chance' => 0.0,
        'gm.ai.titles.winner_takes_all_chance' => ['weekly' => 0.0, 'ppv' => 0.0],
    ]);
});

function summerSlamFor(Save $save): Show
{
    $week = (int) PpvEvent::where('key', PpvKey::Summerslam)->value('season_week');
    $save->update(['current_week' => $week]);
    app(ShowScheduler::class)->ensureWeek($save, $week);

    return Show::with('ppvEvent')->where('save_id', $save->id)->where('week_number', $week)->whereNull('brand')->sole();
}

/**
 * The titles the rival put on the line in its own slots.
 *
 * @return list<string>
 */
function rivalTitles(Show $show, Brand $brand): array
{
    return MatchSlot::with('titleChampionship')->where('show_id', $show->id)->where('owner_brand', $brand)->whereNotNull('title_championship_id')
        ->get()->map(fn (MatchSlot $s): string => $s->titleChampionship->key->value)->values()->all();
}

it('books its world title when the 80% roll lands', function () {
    GameBuilder::passiveRival();
    config(['gm.ai.titles.interbrand_world_chance' => 1.0]);
    $save = GameBuilder::inSeason(size: 16);
    GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
    GameBuilder::giveTitle($save, 'united_states', ['Booker T']);
    $show = summerSlamFor($save);

    app(AiGm::class)->bookShow($show, Brand::Smackdown);

    expect(rivalTitles($show, Brand::Smackdown))->toBe(['world_heavyweight']);
});

it('books one of its other titles when the roll misses', function () {
    GameBuilder::passiveRival();
    config(['gm.ai.titles.interbrand_world_chance' => 0.0]);
    $save = GameBuilder::inSeason(size: 16);
    GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
    GameBuilder::giveTitle($save, 'united_states', ['Booker T']);
    $show = summerSlamFor($save);

    app(AiGm::class)->bookShow($show, Brand::Smackdown);

    expect(rivalTitles($show, Brand::Smackdown))->toBe(['united_states']);
});

it('falls back to another title when the rolled one can’t be booked', function () {
    GameBuilder::passiveRival();
    config(['gm.ai.titles.interbrand_world_chance' => 1.0]);
    $save = GameBuilder::inSeason(size: 16);
    // No world champion, so the world title can't go on.
    GameBuilder::giveTitle($save, 'united_states', ['Booker T']);
    $show = summerSlamFor($save);

    app(AiGm::class)->bookShow($show, Brand::Smackdown);

    expect(rivalTitles($show, Brand::Smackdown))->toBe(['united_states']);
});

it('doesn’t force a title on a weekly show', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(size: 16);
    GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
    $weekly = Show::where('save_id', $save->id)->where('week_number', 1)->where('brand', Brand::Smackdown)->sole();

    app(AiGm::class)->bookShow($weekly, Brand::Smackdown);

    expect(MatchSlot::where('show_id', $weekly->id)->whereIn('title_championship_id', Championship::where('brand', Brand::Smackdown)->pluck('id'))->exists())->toBeFalse();
});
