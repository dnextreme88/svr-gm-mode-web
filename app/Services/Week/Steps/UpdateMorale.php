<?php

namespace App\Services\Week\Steps;

use App\Enums\ShowStatus;
use App\Models\PromoSlot;
use App\Services\Morale\DefectionService;
use App\Services\Morale\MoraleService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;

/**
 * Morale clocks and complaints (§15), then defection rolls (§8).
 */
class UpdateMorale implements WeekStep
{
    public function __construct(
        private MoraleService $morale,
        private DefectionService $defections,
    ) {}

    public function handle(WeekContext $context): void
    {
        $this->morale->weekly(
            $context->save,
            $context->week,
            ($context->data['used'] ?? []) + $this->usedInPromos($context),
            $context->data['title_match'] ?? [],
        );

        $context->data['defections'] = $this->defections->weekly($context->save, $context->week, $context->rng('defection'));
    }

    /**
     * House rule (2026-11-20): who appeared this week in a promo that counts
     * as being used (Interference, Diva Bikini Contest), success or not.
     *
     * @return array<int, true>
     */
    private function usedInPromos(WeekContext $context): array
    {
        $keys = (array) config('gm.morale.promos_count_as_used', []);

        if ($keys === []) {
            return [];
        }

        $used = [];

        PromoSlot::with('promoType')
            ->whereHas('show', fn ($q) => $q->where('save_id', $context->save->id)
                ->where('week_number', $context->week)
                ->where('status', ShowStatus::Aired))
            ->whereHas('promoType', fn ($q) => $q->whereIn('key', $keys))
            ->get()
            ->each(function (PromoSlot $slot) use (&$used): void {
                foreach ($slot->targetIds() as $id) {
                    $used[$id] = true;
                }
            });

        return $used;
    }
}
