<?php

namespace Tests\Support;

use App\Enums\Brand;
use App\Enums\ShowType;
use App\Enums\Stipulation;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Models\Save;
use App\Models\Show;
use App\Services\Card\Booking;
use App\Services\Card\CardService;
use App\Services\Week\ShowScheduler;

/**
 * Booking helpers for feature tests.
 */
class Card
{
    public static function type(string $family, string $variant): MatchType
    {
        return MatchType::where('family', $family)->where('variant', $variant)->firstOrFail();
    }

    public static function singles(): MatchType
    {
        return self::type('single', 'No Manager');
    }

    public static function show(Save $save, Brand $brand = Brand::Raw, ShowType $type = ShowType::Weekly, ?int $week = null): Show
    {
        $week ??= $save->current_week;
        app(ShowScheduler::class)->ensureWeek($save, $week);
        $show = Show::where('save_id', $save->id)->where('week_number', $week)->where('brand', $brand)->where('show_type', $type)->firstOrFail();
        app(CardService::class)->ensureSlots($show);

        return $show;
    }

    /**
     * @param  list<list<string>>  $sides  wrestler names per side
     * @param  array<int, string>  $managers  side => manager name
     */
    public static function book(Save $save, Show $show, int $position, MatchType $type, array $sides, array $managers = [], ?int $titleId = null, ?Stipulation $stipulation = null, ?string $referee = null): MatchSlot
    {
        $ids = array_map(fn (array $side): array => array_map(fn (string $n): int => GameBuilder::wrestler($save, $n)->id, $side), $sides);
        $managerIds = array_map(fn (string $n): int => GameBuilder::wrestler($save, $n)->id, $managers);

        return app(CardService::class)->book($show, $position, new Booking($type, $ids, $managerIds, $titleId, $stipulation, refereeId: $referee === null ? null : GameBuilder::wrestler($save, $referee)->id));
    }
}
