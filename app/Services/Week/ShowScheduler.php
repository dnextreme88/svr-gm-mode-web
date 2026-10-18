<?php

namespace App\Services\Week;

use App\Enums\Brand;
use App\Enums\ShowStatus;
use App\Enums\ShowType;
use App\Models\Save;
use App\Models\Show;
use App\Services\Calendar\CalendarService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Creates each week's cards: one weekly show per brand, plus a PPV card for
 * every brand running a PPV that week (§1).
 */
class ShowScheduler
{
    public function __construct(private CalendarService $calendar) {}

    /**
     * @return Collection<int, Show>
     */
    public function ensureWeek(Save $save, int $week): Collection
    {
        foreach (Brand::cases() as $brand) {
            $this->ensureShow($save, $week, $brand, ShowType::Weekly, null, $brand === Brand::Raw ? 'Monday Night Raw' : 'Friday Night SmackDown!');

            $ppv = $this->calendar->ppvForWeek($week, $brand);

            if ($ppv !== null) {
                $this->ensureShow($save, $week, $brand, ShowType::Ppv, $ppv->id, $ppv->name);
            }
        }

        return $this->showsForWeek($save, $week);
    }

    /**
     * @return Collection<int, Show>
     */
    public function showsForWeek(Save $save, int $week, ?Brand $brand = null): Collection
    {
        return Show::with('ppvEvent')
            ->where('save_id', $save->id)
            ->where('week_number', $week)
            ->when($brand !== null, fn ($q) => $q->where(fn ($q) => $q->where('brand', $brand)->orWhereNull('brand')))
            ->get()
            ->sortBy(fn (Show $show): int => $show->isPpv() ? 1 : 0)
            ->values();
    }

    private function ensureShow(Save $save, int $week, ?Brand $brand, ShowType $type, ?int $ppvId, string $name): Show
    {
        return Show::firstOrCreate(
            ['save_id' => $save->id, 'week_number' => $week, 'brand' => $brand, 'show_type' => $type],
            [
                'season' => $this->calendar->seasonOf($week),
                'ppv_event_id' => $ppvId,
                'name' => $name,
                'status' => ShowStatus::Booking,
            ],
        );
    }
}
