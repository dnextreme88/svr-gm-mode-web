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
     */
    public function post(Save $save, Brand $brand, LedgerCategory $category, int $amount, string $memo, ?int $week = null, ?Model $subject = null): LedgerEntry
    {
        $week ??= $save->current_week;

        $entry = new LedgerEntry([
            'save_id' => $save->id,
            'brand' => $brand,
            'season' => $this->calendar->seasonOf($week),
            'week_number' => $week,
            'category' => $category,
            'amount' => $amount,
            'memo' => $memo,
        ]);

        if ($subject !== null) {
            $entry->subject()->associate($subject);
        }

        $entry->save();

        SaveBrand::where('save_id', $save->id)->where('brand', $brand)->increment('budget', $amount);

        return $entry;
    }

    public function budget(Save $save, Brand $brand): int
    {
        return (int) SaveBrand::where('save_id', $save->id)->where('brand', $brand)->value('budget');
    }
}
