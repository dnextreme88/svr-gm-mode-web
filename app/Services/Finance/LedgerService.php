<?php

namespace App\Services\Finance;

use App\Enums\Brand;
use App\Enums\LedgerCategory;
use App\Models\LedgerEntry;
use App\Models\Save;
use App\Models\SaveBrand;
use App\Services\Calendar\CalendarService;
use Illuminate\Database\Eloquent\Model;

/**
 * Posts income and expenses and keeps each brand's budget in step (§7).
 */
class LedgerService
{
    public function __construct(private CalendarService $calendar) {}

    /**
     * Record a line item. Positive amounts are income, negative are expenses.
     * An unsettled line (a show's match and promo money, house rule
     * 2026-11-17) is recorded now and reaches the budget at `settle()`.
     */
    public function post(Save $save, Brand $brand, LedgerCategory $category, int $amount, string $memo, ?int $week = null, ?Model $subject = null, ?int $showId = null, bool $settled = true): LedgerEntry
    {
        $week ??= $save->current_week;

        $entry = new LedgerEntry([
            'save_id' => $save->id,
            'brand' => $brand,
            'season' => $this->calendar->seasonOf($week),
            'week_number' => $week,
            'category' => $category,
            'amount' => $amount,
            'settled' => $settled,
            'memo' => $memo,
            'show_id' => $showId,
        ]);

        if ($subject !== null) {
            $entry->subject()->associate($subject);
        }

        $entry->save();

        if ($settled) {
            SaveBrand::where('save_id', $save->id)->where('brand', $brand)->increment('budget', $amount);
        }

        return $entry;
    }

    /**
     * Apply a week's unsettled lines to the budgets (house rule,
     * 2026-11-17): the match and promo money of the shows that aired, once
     * their news is in (the PPV night's, or the whole week's).
     */
    public function settle(Save $save, int $week): void
    {
        $entries = LedgerEntry::where('save_id', $save->id)->where('week_number', $week)->where('settled', false)->get();

        foreach ($entries->groupBy(fn (LedgerEntry $e): string => $e->brand->value) as $brand => $lines) {
            SaveBrand::where('save_id', $save->id)->where('brand', $brand)->increment('budget', (int) $lines->sum('amount'));
        }

        LedgerEntry::whereIn('id', $entries->pluck('id'))->update(['settled' => true]);
    }

    public function budget(Save $save, Brand $brand): int
    {
        return (int) SaveBrand::where('save_id', $save->id)->where('brand', $brand)->value('budget');
    }
}
