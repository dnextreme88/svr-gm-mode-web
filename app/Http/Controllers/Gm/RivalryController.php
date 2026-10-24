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
        ]);
    }
}
