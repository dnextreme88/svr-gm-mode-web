<?php

namespace App\Services\Week;

use App\Enums\Brand;
use App\Enums\PpvKey;
use App\Enums\ShowStatus;
use App\Enums\ShowType;
use App\Models\PpvEvent;
use App\Models\Save;
use App\Models\Show;
use App\Services\Calendar\CalendarService;
use App\Services\Ppv\PpvCardService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Creates each week's cards (§1, §13, §23): one weekly show per brand, each
 * brand's own PPV card, the interbrand Royal Rumble / Survivor Series main
 * event, and the single interbrand WrestleMania card.
 */
class ShowScheduler
{
    public function __construct(
        private CalendarService $calendar,
        private PpvCardService $ppvCards,
    ) {}

    /**
     * @return Collection<int, Show>
     */
    public function ensureWeek(Save $save, int $week): Collection
    {
        foreach (Brand::cases() as $brand) {
            $this->ensureShow($save, $week, $brand, ShowType::Weekly, null, $brand === Brand::Raw ? 'Monday Night Raw' : 'Friday Night Smackdown');
        }

        foreach ($this->calendar->ppvsForWeek($week) as $ppv) {
            $this->ensurePpv($save, $week, $ppv);
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
            ->sortBy(fn (Show $show): int => $show->isInterbrand() ? 2 : ($show->isPpv() ? 1 : 0))
            ->values();
    }

    private function ensurePpv(Save $save, int $week, PpvEvent $ppv): void
    {
        if ($ppv->key === PpvKey::Wrestlemania) {
            $show = $this->ensureShow($save, $week, null, ShowType::Ppv, $ppv->id, 'WrestleMania');

            if ($show->wasRecentlyCreated) {
                $this->ppvCards->setUp($show);
                $this->ppvCards->drawSeries($show, $save->rng('wm.series', $week));
                $this->ppvCards->setMainEvent($show);
            }

            return;
        }

        foreach (Brand::cases() as $brand) {
            if ($ppv->runsOn($brand)) {
                $this->ensureShow($save, $week, $brand, ShowType::Ppv, $ppv->id, $ppv->name);
            }
        }

        if (in_array($ppv->key, [PpvKey::RoyalRumble, PpvKey::SurvivorSeries], true)) {
            $name = $ppv->key === PpvKey::RoyalRumble ? 'Royal Rumble Match' : 'Survivor Series: Raw vs. Smackdown';
            $this->ppvCards->setUp($this->ensureShow($save, $week, null, ShowType::Ppv, $ppv->id, $name));
        }
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
