<?php

namespace App\Http\Controllers\Gm;

use App\Http\Controllers\Controller;
use App\Http\Presenters\CardPresenter;
use App\Models\MatchSlot;
use App\Models\Rivalry;
use App\Models\Save;
use App\Services\Calendar\CalendarService;
use App\Services\Rivalries\RivalryService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Active and recent rivalries with heat and duration (§3 Rivalry).
 */
class RivalryController extends Controller
{
    public function __construct(
        private RivalryService $rivalries,
        private CalendarService $calendar,
    ) {}

    public function __invoke(Save $save): Response
    {
        $rows = Rivalry::with(['wrestlerA.wrestler', 'wrestlerB.wrestler'])
            ->where('save_id', $save->id)
            ->where(fn ($q) => $q->where('active', true)->orWhere('ended_week', '>=', $save->current_week - 8))
            ->orderByDesc('active')
            ->orderByDesc('heat')
            ->get()
            ->map(function (Rivalry $r) use ($save): array {
                // Always "A vs. B" alphabetically, older rivalries included.
                [$a, $b] = strcasecmp($r->wrestlerA->name(), $r->wrestlerB->name()) <= 0
                    ? [$r->wrestlerA, $r->wrestlerB]
                    : [$r->wrestlerB, $r->wrestlerA];

                return [
                    'id' => $r->id,
                    'wrestler_a' => $a->name(),
                    'wrestler_b' => $b->name(),
                    // The brand it started on (house rule, 2026-11-15), else where the rivals are now.
                    'brand' => ($r->brand ?? ($a->brand !== null && $a->brand === $b->brand ? $a->brand : null))?->value,
                    'brand_a' => $a->brand?->value,
                    'brand_b' => $b->brand?->value,
                    'heat' => $r->heat,
                    'weeks_active' => $r->weeks_active,
                    'started_week' => $r->started_week,
                    'active' => $r->active,
                    'ended_week' => $r->ended_week,
                    // House rule (2026-11-20): every match they were in
                    // together, newest first, for the row's history.
                    'matches' => $this->matches($save, $r),
                ];
            });

        return Inertia::render('gm/rivalries', [
            'rivalries' => $rows,
            'max_heat' => (int) config('gm.rivalries.max_heat'),
            'heating_up' => (int) config('gm.rivalries.heating_up_threshold'),
            // House rule (2026-11-20): the heat bar is yellow below the
            // first, orange below the second, red from it.
            'heat_colors' => array_map('intval', (array) config('gm.rivalries.heat_colors')),
            // How rivalries work (RivalryService, MatchResolver, the two
            // rivalry promos), shown as the page's rules.
            'rules' => [
                'meetings' => (int) config('gm.rivalries.auto_create_meetings'),
                'window_weeks' => (int) config('gm.rivalries.auto_create_window_weeks'),
                'start_heat' => (int) config('gm.rivalries.start_heat'),
                'heat_per_meeting' => (int) config('gm.rivalries.heat_per_meeting'),
                'heat_per_ppv_meeting' => (int) config('gm.rivalries.heat_per_ppv_meeting'),
                'heat_per_interference' => (int) config('gm.rivalries.heat_per_interference'),
                'idle_decay' => (int) config('gm.rivalries.idle_decay'),
                'milestone_weeks' => (int) config('gm.news.rivalry_milestone_weeks'),
                'start_promo_bars' => (int) config('gm.promos.fatigue_bars.start_rivalry'),
                'continue_promo_bars' => (int) config('gm.promos.fatigue_bars.continue_rivalry'),
                'continue_promo_cost' => abs((int) config('gm.promos.amounts.continue_rivalry')),
                // House rule (2026-11-15): Call-out and the Diva Bikini Contest.
                'call_out_heat' => (int) config('gm.rivalries.heat_per_call_out'),
                'call_out_cost' => abs((int) config('gm.promos.amounts.call_out')),
                'call_out_chance' => (int) round((float) config('gm.promos.success_rates.call_out') * 100),
            ],
        ]);
    }

    /**
     * The rivals' matches together (RivalryService::matchSlotIds), each with
     * its week (and PPV) and its result, in the Reports table's shape.
     *
     * @return list<array<string, mixed>>
     */
    private function matches(Save $save, Rivalry $rivalry): array
    {
        $ids = $this->rivalries->matchSlotIds($rivalry, $save->current_week);

        if ($ids === []) {
            return [];
        }

        $slots = MatchSlot::with(['show.ppvEvent', 'matchType', 'participants.saveWrestler.wrestler', 'result', 'titleChampionship', 'gauntletBouts'])
            ->whereIn('id', $ids)
            ->whereHas('result')
            ->get()
            ->keyBy('id');

        return array_values(array_filter(array_map(function (int $id) use ($save, $slots): ?array {
            $slot = $slots->get($id);

            if ($slot === null) {
                return null;
            }

            $week = $slot->show->week_number;
            $query = ['match' => $slot->id, ...($slot->show->brand !== null ? ['brand' => $slot->show->brand->value] : [])];

            return [
                'id' => $slot->id,
                'season' => $this->calendar->seasonOf($week),
                'season_week' => $this->calendar->seasonWeek($week),
                'ppv' => $slot->show->isPpv() ? $slot->show->name : null,
                'link' => route('gm.results', [$save, $week, ...$query], false),
                'outcome' => CardPresenter::outcome($slot),
            ];
        }, $ids)));
    }
}
