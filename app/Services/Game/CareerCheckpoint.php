<?php

namespace App\Services\Game;

use App\Models\Save;
use App\Models\SaveCheckpoint;
use App\Services\Factions\FactionNamer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Save career checkpoints (house rule, 2026-11-17). Saving stores the whole
 * career as it stands: the `saves` row and every row of every per-career
 * table, with their ids. Loading the career from Saved careers deletes the
 * career's rows and puts the stored ones back, so anything done since the
 * last save is lost, like a save slot in a video game.
 *
 * One checkpoint per career; each save replaces it.
 */
class CareerCheckpoint
{
    /**
     * Every per-career table, in an order that satisfies the foreign keys
     * when inserting (parents first). A table with a `save_id` maps to null;
     * a child table without one maps to [parent table, its column that
     * points at the parent]. `CareerCheckpointTest` fails when a table with
     * a `save_id` (or one pointing at a table here) is missing.
     *
     * @var array<string, array{0: string, 1: string}|null>
     */
    public const TABLES = [
        'save_brands' => null,
        'save_wrestlers' => null,
        'seasons' => null,
        'contracts' => null,
        'draft_picks' => null,
        'shows' => null,
        'week_resolutions' => null,
        'emails' => null,
        'news_events' => null,
        'ledger_entries' => null,
        'tag_teams' => null,
        'tag_team_members' => ['tag_teams', 'tag_team_id'],
        'match_slots' => ['shows', 'show_id'],
        'title_reigns' => null,
        'match_participants' => ['match_slots', 'match_slot_id'],
        'match_results' => ['match_slots', 'match_slot_id'],
        'gauntlet_bouts' => ['match_slots', 'match_slot_id'],
        'promo_slots' => ['shows', 'show_id'],
        'money_in_the_bank' => null,
        'morale_complaints' => null,
        'pending_roster_moves' => null,
        'injuries' => null,
        'rivalries' => null,
        'title_shots' => null,
        'last_chance_lockouts' => null,
        'trades' => null,
        'trade_items' => ['trades', 'trade_id'],
        'interbrand_series' => null,
        'fatigue_logs' => null,
        'week_airings' => null,
        'save_factions' => null,
        'save_faction_members' => ['save_factions', 'save_faction_id'],
        'faction_prompts' => null,
        'title_vacancy_prompts' => null,
        'gm_notices' => null,
        'rematch_clauses' => null,
        'conditional_story_triggers' => null,
        'royal_rumble_entries' => null,
    ];

    /**
     * `saves` columns a load leaves alone: who the career is and when it
     * was saved.
     */
    private const KEEP_SAVE_COLUMNS = ['id', 'name', 'saved_at', 'created_at', 'updated_at'];

    /** Rows per insert, well under SQLite's bound-parameter limit. */
    private const CHUNK = 40;

    public function __construct(private FactionNamer $factions) {}

    /**
     * Store the career as it stands, replacing any earlier checkpoint.
     */
    public function capture(Save $save): SaveCheckpoint
    {
        $save->refresh();
        $snapshot = DB::transaction(fn (): array => $this->snapshot($save));

        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $compressed = gzencode($encoded, 6);

        if ($compressed === false) {
            throw new RuntimeException('Could not compress the checkpoint.');
        }

        return SaveCheckpoint::updateOrCreate(['save_id' => $save->id], [
            'current_week' => $save->current_week,
            'stage' => $save->stage,
            'budget' => $this->budget($save),
            'fingerprint' => $this->fingerprint($save),
            'payload' => base64_encode($compressed),
        ]);
    }

    /**
     * Put the career back as it was at its checkpoint. Returns false when
     * it has none (a career saved before checkpoints existed, or never).
     */
    public function restore(Save $save): bool
    {
        $checkpoint = SaveCheckpoint::where('save_id', $save->id)->first();

        if ($checkpoint === null) {
            return false;
        }

        $decoded = base64_decode($checkpoint->payload, true);
        $json = $decoded === false ? false : gzdecode($decoded);

        if ($json === false) {
            throw new RuntimeException('The checkpoint is damaged.');
        }

        /** @var array{save: array<string, mixed>, tables: array<string, list<array<string, mixed>>>} $snapshot */
        $snapshot = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($save, $snapshot): void {
            $this->wipe($save);

            foreach (array_keys(self::TABLES) as $table) {
                foreach (array_chunk($snapshot['tables'][$table] ?? [], self::CHUNK) as $rows) {
                    DB::table($table)->insert($rows);
                }
            }

            DB::table('saves')->where('id', $save->id)->update([
                ...$snapshot['save'],
                'updated_at' => now(),
            ]);

            // The fingerprint moves with updated_at: a load is a clean slate.
            SaveCheckpoint::where('save_id', $save->id)->update(['fingerprint' => $this->fingerprint($save)]);
        });

        $this->factions->forget($save->id);
        $save->refresh();

        return true;
    }

    /**
     * Whether anything changed since the checkpoint (null without one).
     */
    public function hasChanges(Save $save): ?bool
    {
        $checkpoint = SaveCheckpoint::where('save_id', $save->id)->first(['fingerprint']);

        return $checkpoint === null ? null : $checkpoint->fingerprint !== $this->fingerprint($save);
    }

    /**
     * Each table's ids for this career, following parents for child tables.
     *
     * @return array<string, Builder>
     */
    private function queries(Save $save): array
    {
        $queries = [];

        foreach (self::TABLES as $table => $parent) {
            $query = DB::table($table);

            if ($parent === null) {
                $query->where('save_id', $save->id);
            } else {
                [$parentTable, $column] = $parent;
                // TABLES lists every parent before its children.
                $parentQuery = $queries[$parentTable] ?? throw new RuntimeException("Checkpoint table {$table} is listed before its parent {$parentTable}.");
                $query->whereIn($column, (clone $parentQuery)->select('id'));
            }

            $queries[$table] = $query;
        }

        return $queries;
    }

    /**
     * @return array{save: array<string, mixed>, tables: array<string, list<array<string, mixed>>>}
     */
    private function snapshot(Save $save): array
    {
        $saveRow = DB::table('saves')->where('id', $save->id)->first();
        $row = $saveRow === null ? [] : get_object_vars($saveRow);
        $tables = [];

        foreach ($this->queries($save) as $table => $query) {
            $rows = [];

            foreach ($query->orderBy('id')->get() as $record) {
                $rows[] = get_object_vars($record);
            }

            $tables[$table] = $rows;
        }

        return [
            'save' => array_diff_key($row, array_flip(self::KEEP_SAVE_COLUMNS)),
            'tables' => $tables,
        ];
    }

    /**
     * Delete the career's rows, children first.
     */
    private function wipe(Save $save): void
    {
        $queries = $this->queries($save);

        // Collect ids first: deleting a parent would empty a child's
        // sub-query before the child is cleared.
        $ids = [];

        foreach ($queries as $table => $query) {
            $ids[$table] = (clone $query)->pluck('id')->all();
        }

        foreach (array_reverse(array_keys(self::TABLES)) as $table) {
            foreach (array_chunk($ids[$table], 500) as $chunk) {
                DB::table($table)->whereIn('id', $chunk)->delete();
            }
        }
    }

    /**
     * A cheap summary of the career's state: the `saves` row plus each
     * table's row count, highest id and latest update.
     */
    private function fingerprint(Save $save): string
    {
        $parts = [json_encode(DB::table('saves')->where('id', $save->id)->first(['stage', 'current_week', 'current_season', 'updated_at']))];

        foreach ($this->queries($save) as $table => $query) {
            $columns = ['count(*) as n', 'max(id) as top'];

            if (Schema::hasColumn($table, 'updated_at')) {
                $columns[] = 'max(updated_at) as touched';
            }

            $parts[] = $table.':'.json_encode($query->selectRaw(implode(', ', $columns))->first());
        }

        return hash('sha256', implode('|', $parts));
    }

    private function budget(Save $save): ?int
    {
        $budget = DB::table('save_brands')->where('save_id', $save->id)->where('brand', $save->player_brand->value)->value('budget');

        return $budget === null ? null : (int) $budget;
    }
}
