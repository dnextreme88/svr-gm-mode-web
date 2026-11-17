<?php

use App\Enums\Brand;
use App\Models\Save;
use App\Models\SaveCheckpoint;
use App\Models\WeekAiring;
use App\Services\Game\CareerCheckpoint;
use App\Services\Week\WeekResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GameBuilder;

/**
 * Every registered table's rows for one career, as plain arrays.
 *
 * @return array<string, mixed>
 */
function careerState(Save $save): array
{
    $state = ['save' => (array) DB::table('saves')->where('id', $save->id)->first(['stage', 'current_week', 'current_season', 'rng_seed', 'draft_order'])];
    $ids = [];

    foreach (CareerCheckpoint::TABLES as $table => $parent) {
        $query = DB::table($table)->orderBy('id');
        $parent === null
            ? $query->where('save_id', $save->id)
            : $query->whereIn($parent[1], $ids[$parent[0]] ?: [0]);
        $rows = $query->get()->map(fn (object $r): array => (array) $r)->all();
        $ids[$table] = array_column($rows, 'id');
        $state[$table] = $rows;
    }

    return $state;
}

it('puts the whole career back as it was saved when it is loaded', function () {
    $save = GameBuilder::inSeason();
    $save->update(['saved_at' => now()]);
    $before = careerState($save);

    app(CareerCheckpoint::class)->capture($save);
    app(WeekResolver::class)->resolveWeek($save->refresh(), 1);
    app(WeekResolver::class)->resolveWeek($save->refresh(), 2);

    expect($save->refresh()->current_week)->toBe(3)
        ->and(careerState($save))->not->toBe($before);

    $this->post(route('gm.saves.load', $save))->assertRedirect(route('gm.saves.show', $save));

    expect($save->refresh()->current_week)->toBe(1)
        ->and(careerState($save))->toBe($before);
});

it('can save and load in the middle of a week airing', function () {
    $save = GameBuilder::inSeason();
    WeekAiring::create(['save_id' => $save->id, 'week_number' => 1, 'context' => ['phase' => 'ppv'], 'rng_states' => ['a' => [1, 2]]]);
    $before = careerState($save);

    app(CareerCheckpoint::class)->capture($save);
    WeekAiring::where('save_id', $save->id)->delete();
    app(CareerCheckpoint::class)->restore($save);

    expect(careerState($save))->toBe($before)
        ->and(WeekAiring::where('save_id', $save->id)->first()->context)->toBe(['phase' => 'ppv']);
});

it('leaves other careers alone', function () {
    $mine = GameBuilder::inSeason();
    $other = GameBuilder::inSeason(player: Brand::Smackdown, seed: 'other');
    $others = careerState($other);

    app(CareerCheckpoint::class)->capture($mine);
    app(WeekResolver::class)->resolveWeek($mine->refresh(), 1);
    app(CareerCheckpoint::class)->restore($mine);

    expect(careerState($other))->toBe($others);
});

it('opens a career without a checkpoint as it is', function () {
    $save = GameBuilder::inSeason();
    $save->update(['saved_at' => now()]);
    app(WeekResolver::class)->resolveWeek($save->refresh(), 1);

    $this->post(route('gm.saves.load', $save))->assertRedirect(route('gm.saves.show', $save));

    expect($save->refresh()->current_week)->toBe(2);
});

it('saves a checkpoint from the header button and lists the career as saved', function () {
    $save = GameBuilder::inSeason();

    $this->post(route('gm.saves.persist', $save))->assertRedirect();

    $checkpoint = SaveCheckpoint::where('save_id', $save->id)->firstOrFail();
    expect($checkpoint->current_week)->toBe(1);

    $this->get(route('gm.saves.index'))->assertInertia(fn (Assert $page) => $page
        ->where('saves.0.id', $save->id)
        ->where('saves.0.season_week', 1)
        ->where('saves.0.has_changes', false));

    app(WeekResolver::class)->resolveWeek($save->refresh(), 1);

    // Listed as its save (week 1), with progress since then flagged.
    $this->get(route('gm.saves.index'))->assertInertia(fn (Assert $page) => $page
        ->where('saves.0.season_week', 1)
        ->where('saves.0.has_changes', true));

    $this->get(route('gm.schedule', $save))->assertInertia(fn (Assert $page) => $page
        ->where('game.last_saved.season', 1)
        ->where('game.last_saved.season_week', 1));
});

it('replaces the checkpoint on every save', function () {
    $save = GameBuilder::inSeason();
    app(CareerCheckpoint::class)->capture($save);
    app(WeekResolver::class)->resolveWeek($save->refresh(), 1);
    app(CareerCheckpoint::class)->capture($save->refresh());

    expect(SaveCheckpoint::where('save_id', $save->id)->count())->toBe(1)
        ->and(SaveCheckpoint::where('save_id', $save->id)->value('current_week'))->toBe(2);
});

it('deletes the checkpoint with the career', function () {
    $save = GameBuilder::inSeason();
    app(CareerCheckpoint::class)->capture($save);

    $this->delete(route('gm.saves.destroy', $save));

    expect(SaveCheckpoint::count())->toBe(0);
});

it('covers every per-career table', function () {
    $registered = array_keys(CareerCheckpoint::TABLES);
    $missing = [];

    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        if (in_array($table, ['saves', 'save_checkpoints', ...$registered], true)) {
            continue;
        }

        $pointsIn = collect(Schema::getForeignKeys($table))
            ->contains(fn (array $fk): bool => in_array($fk['foreign_table'], $registered, true));

        if (Schema::hasColumn($table, 'save_id') || $pointsIn) {
            $missing[] = $table;
        }
    }

    expect($missing)->toBe([]);
});
