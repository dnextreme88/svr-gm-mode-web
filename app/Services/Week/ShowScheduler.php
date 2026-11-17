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
use App\Services\Ppv\QualifierService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Creates each week's cards (§1, §13, §23): one weekly show per brand, each
 * brand's own PPV card, the interbrand Royal Rumble / Survivor Series main
 * event, and the single interbrand SummerSlam and WrestleMania cards.
 */
class ShowScheduler
{
    public function __construct(
        private CalendarService $calendar,
        private PpvCardService $ppvCards,
        private QualifierService $qualifiers,
    ) {}

    /**
     * @return Collection<int, Show>
     */
    public function ensureWeek(Save $save, int $week): Collection
    {
        // House rule (2026-11-16): WrestleMania week has no weekly shows.
        // The season ends on WrestleMania's main event, then the re-draft.
        foreach ($this->calendar->isWrestleManiaWeek($week) ? [] : Brand::cases() as $brand) {
            $show = $this->ensureShow($save, $week, $brand, ShowType::Weekly, null, $brand === Brand::Raw ? 'Monday Night Raw' : 'Friday Night Smackdown');
            // House rule (2026-11-17): Match 5 is a fixed Qualifying Match in
            // the weeks before Survivor Series and WrestleMania.
            $this->qualifiers->setUp($show);
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

        // House rules: SummerSlam (2026-11-01), and the Royal Rumble and
        // Survivor Series (2026-11-15) are one 7-match card both brands book.
        if (in_array($ppv->key, [PpvKey::Summerslam, PpvKey::RoyalRumble, PpvKey::SurvivorSeries], true)) {
            $show = $this->ensureShow($save, $week, null, ShowType::Ppv, $ppv->id, $ppv->name);

            if ($show->wasRecentlyCreated) {
                $this->ppvCards->setUp($show);
            }

            return;
        }

        foreach (Brand::cases() as $brand) {
            if ($ppv->runsOn($brand)) {
                $this->ensureShow($save, $week, $brand, ShowType::Ppv, $ppv->id, $ppv->name);
            }
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
