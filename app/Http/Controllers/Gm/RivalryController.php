<?php

namespace App\Http\Controllers\Gm;

use App\Http\Controllers\Controller;
use App\Models\Rivalry;
use App\Models\Save;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Active and recent rivalries with heat and duration (§3 Rivalry).
 */
class RivalryController extends Controller
{
    public function __invoke(Save $save): Response
    {
        $rows = Rivalry::with(['wrestlerA.wrestler', 'wrestlerB.wrestler'])
            ->where('save_id', $save->id)
            ->where(fn ($q) => $q->where('active', true)->orWhere('ended_week', '>=', $save->current_week - 8))
            ->orderByDesc('active')
            ->orderByDesc('heat')
            ->get()
            ->map(function (Rivalry $r): array {
                // Always "A vs. B" alphabetically, older rivalries included.
                [$a, $b] = strcasecmp($r->wrestlerA->name(), $r->wrestlerB->name()) <= 0
                    ? [$r->wrestlerA, $r->wrestlerB]
                    : [$r->wrestlerB, $r->wrestlerA];

                return [
                    'id' => $r->id,
                    'wrestler_a' => $a->name(),
                    'wrestler_b' => $b->name(),
                    'brand_a' => $a->brand?->value,
                    'brand_b' => $b->brand?->value,
                    'heat' => $r->heat,
                    'weeks_active' => $r->weeks_active,
                    'started_week' => $r->started_week,
                    'active' => $r->active,
                    'ended_week' => $r->ended_week,
                ];
            });

        return Inertia::render('gm/rivalries', [
            'rivalries' => $rows,
            'max_heat' => (int) config('gm.rivalries.max_heat'),
            'heating_up' => (int) config('gm.rivalries.heating_up_threshold'),
            // How rivalries work (RivalryService, MatchResolver, the two
            // rivalry promos), shown as the page's rules.
            'rules' => [
                'meetings' => (int) config('gm.rivalries.auto_create_meetings'),
                'window_weeks' => (int) config('gm.rivalries.auto_create_window_weeks'),
                'start_heat' => (int) config('gm.rivalries.start_heat'),
                'heat_per_meeting' => (int) config('gm.rivalries.heat_per_meeting'),
                'heat_per_promo' => (int) config('gm.rivalries.heat_per_promo'),
                'idle_decay' => (int) config('gm.rivalries.idle_decay'),
                'blowoff_min_heat' => (int) config('gm.rivalries.blowoff_min_heat'),
                'milestone_weeks' => (int) config('gm.news.rivalry_milestone_weeks'),
                'start_promo_bars' => (int) config('gm.promos.fatigue_bars.start_rivalry'),
                'continue_promo_bars' => (int) config('gm.promos.fatigue_bars.continue_rivalry'),
                'continue_promo_cost' => abs((int) config('gm.promos.amounts.continue_rivalry')),
            ],
        ]);
    }
}
