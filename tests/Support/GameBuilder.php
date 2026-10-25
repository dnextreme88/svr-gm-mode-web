<?php

namespace Tests\Support;

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Enums\RosterType;
use App\Enums\SaveStage;
use App\Models\Championship;
use App\Models\Save;
use App\Models\SaveBrand;
use App\Models\SaveWrestler;
use App\Models\TitleReign;
use App\Models\Wrestler;
use App\Services\Contracts\ContractService;
use App\Services\Game\NewGameService;
use App\Services\Titles\ChampionshipAssignment;
use App\Services\Week\ShowScheduler;
use Database\Seeders\CatalogSeeder;

/**
 * Builds saves in known states for feature tests. Always uses a fixed seed.
 */
class GameBuilder
{
    public const SEED = 'test-seed';

    public static function seedCatalog(): void
    {
        if (Wrestler::count() === 0) {
            (new CatalogSeeder)->run();
        }
    }

    /**
     * A save still in the initial draft, with no picks made.
     */
    public static function newSave(Brand $player = Brand::Raw, string $seed = self::SEED): Save
    {
        self::seedCatalog();

        return app(NewGameService::class)->create('Test Save', $player, $seed);
    }

    /**
     * An in-season save where each brand signed the named wrestlers (or the
     * first $size from its placeholder roster).
     *
     * @param  list<string>|null  $raw
     * @param  list<string>|null  $smackdown
     */
    public static function inSeason(?array $raw = null, ?array $smackdown = null, int $size = 12, Brand $player = Brand::Raw, ContractLength $length = ContractLength::OneYear, string $seed = self::SEED): Save
    {
        $save = self::newSave($player, $seed);
        $contracts = app(ContractService::class);

        foreach ([Brand::Raw->value => $raw, Brand::Smackdown->value => $smackdown] as $brandValue => $names) {
            $brand = Brand::from($brandValue);
            $names ??= Wrestler::where('placeholder_brand', $brand)->where('roster_type', RosterType::Superstar)->orderBy('id')->limit($size)->pluck('name')->all();

            foreach ($names as $name) {
                $contracts->signDrafted($save, self::wrestler($save, $name), $brand, 1, $length);
            }
        }

        SaveBrand::where('save_id', $save->id)->update(['draft_done' => true]);
        $save->update(['stage' => SaveStage::InSeason]);
        app(ShowScheduler::class)->ensureWeek($save, 1);

        return $save->refresh();
    }

    /**
     * Keep the rival GM out of a test's way: no signings or renewals, no
     * rivalries, and only money promos. It still books matches.
     */
    public static function passiveRival(): void
    {
        config([
            'gm.ai.roster.target_size' => 0,
            'gm.ai.roster.renew_at_weeks_left' => -1,
            'gm.ai.rivalries.max_per_brand' => 0,
            'gm.ai.rivalries.reset_minimum' => 0,
            'gm.ai.rivalries.rivalry_match_chance' => 0.0,
            'gm.ai.promos.interference_every_weeks' => PHP_INT_MAX,
            'gm.ai.promos.slander_gap_weeks' => [PHP_INT_MAX => 1.0],
            'gm.ai.promos.ppv_hype_weeks_before' => [PHP_INT_MAX => 1.0],
            'gm.ai.promos.situational_weights' => [],
        ]);
    }

    public static function wrestler(Save $save, string $name): SaveWrestler
    {
        return SaveWrestler::with('wrestler')
            ->where('save_id', $save->id)
            ->whereHas('wrestler', fn ($q) => $q->where('name', $name))
            ->firstOrFail();
    }

    /**
     * @param  list<string>  $holders
     */
    public static function giveTitle(Save $save, string $key, array $holders): TitleReign
    {
        $championship = Championship::where('key', $key)->firstOrFail();
        $ids = array_map(fn (string $name): int => self::wrestler($save, $name)->id, $holders);

        return app(ChampionshipAssignment::class)->assign($save, $championship, $ids);
    }
}
