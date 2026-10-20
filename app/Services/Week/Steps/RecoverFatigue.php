<?php

namespace App\Services\Week\Steps;

use App\Enums\PromoTypeKey;
use App\Models\FatigueLog;
use App\Models\PromoSlot;
use App\Models\SaveWrestler;
use App\Services\Fatigue\FatigueZones;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Weekly recovery (house rule, 2026-10-20): every wrestler recovers
 * `rest_recovery_bars` at the end of the week, whether or not they wrestled.
 * A successful Inactive Superstar promo adds its own recovery on top.
 * Fatigue never drops below 0. Each wrestler's week is logged for the
 * results screen.
 */
class RecoverFatigue implements WeekStep
{
    public function handle(WeekContext $context): void
    {
        $bar = (int) config('gm.fatigue.bar_size');
        $base = (int) config('gm.fatigue.rest_recovery_bars');
        $extra = $this->promoRecovery($context);
        $start = $context->data['fatigue_start'] ?? [];

        SaveWrestler::where('save_id', $context->save->id)
            ->get()
            ->each(function (SaveWrestler $w) use ($context, $bar, $base, $extra, $start): void {
                $bars = $base + ($extra[$w->id] ?? 0);
                $afterShows = $w->fatigue;
                $end = FatigueZones::cap($afterShows - $bars * $bar);

                if ($end !== $afterShows) {
                    $w->update(['fatigue' => $end]);
                }

                FatigueLog::updateOrCreate(
                    ['save_id' => $context->save->id, 'week_number' => $context->week, 'save_wrestler_id' => $w->id],
                    ['start' => $start[$w->id] ?? $afterShows, 'after_shows' => $afterShows, 'end' => $end, 'recovered_bars' => $bars],
                );
            });
    }

    /**
     * Extra recovery bars from this week's successful Inactive Superstar promos.
     *
     * @return array<int, int> save_wrestler_id => bars
     */
    private function promoRecovery(WeekContext $context): array
    {
        $bars = (int) config('gm.promos.recovery_bars.'.PromoTypeKey::InactiveSuperstar->value);
        $extra = [];

        PromoSlot::whereHas('show', fn ($q) => $q->where('save_id', $context->save->id)->where('week_number', $context->week))
            ->whereHas('promoType', fn ($q) => $q->where('key', PromoTypeKey::InactiveSuperstar))
            ->where('succeeded', true)
            ->whereNotNull('target_wrestler_id')
            ->pluck('target_wrestler_id')
            ->each(function ($id) use (&$extra, $bars): void {
                $extra[(int) $id] = ($extra[(int) $id] ?? 0) + $bars;
            });

        return $extra;
    }
}
