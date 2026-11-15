<?php

namespace App\Services\Week\Steps;

use App\Enums\Brand;
use App\Enums\InjurySeverity;
use App\Enums\LedgerCategory;
use App\Models\Injury;
use App\Services\Finance\LedgerService;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekStep;
use Illuminate\Support\Str;

/**
 * Medical rehab (house rule, 2026-11-15): every week each brand pays for
 * its injured superstars, `gm.finances.medical` per minor and per serious
 * injury still active at the end of the week.
 */
class PayMedicalCosts implements WeekStep
{
    public function __construct(private LedgerService $ledger) {}

    public function handle(WeekContext $context): void
    {
        $injuries = Injury::with('saveWrestler')
            ->where('save_id', $context->save->id)
            ->whereNull('healed_week')
            ->where('started_week', '<=', $context->week)
            ->where('ends_week', '>', $context->week)
            ->get()
            ->filter(fn (Injury $i): bool => $i->saveWrestler->brand !== null)
            ->unique('save_wrestler_id');

        foreach (Brand::cases() as $brand) {
            $mine = $injuries->filter(fn (Injury $i): bool => $i->saveWrestler->brand === $brand);
            $minor = $mine->where('severity', InjurySeverity::Minor)->count();
            $serious = $mine->where('severity', InjurySeverity::Serious)->count();
            $cost = $minor * (int) config('gm.finances.medical.minor') + $serious * (int) config('gm.finances.medical.serious');

            if ($cost === 0) {
                continue;
            }

            $parts = array_filter([
                $minor > 0 ? $minor.' minor' : null,
                $serious > 0 ? $serious.' serious' : null,
            ]);

            $this->ledger->post($context->save, $brand, LedgerCategory::MedicalRehab, -$cost, 'Medical rehab ('.implode(', ', $parts).' '.Str::plural('injury', $minor + $serious).')', $context->week);
        }
    }
}
