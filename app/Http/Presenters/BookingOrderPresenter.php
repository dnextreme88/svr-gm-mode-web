<?php

namespace App\Http\Presenters;

use App\Models\Save;
use App\Models\Show;
use App\Services\Week\BookingOrder;

/**
 * Where a card sits in the week's booking order, for the card and PPV
 * entrants screens: the step tracker, the read-only state, and whether the
 * header offers "Continue to …" or "Air week N".
 */
class BookingOrderPresenter
{
    /**
     * @return array{steps: list<array{id: int, name: string, show_type: string, confirmed: bool, current: bool}>, blocked_by: array{id: int, name: string}|null, next: array{id: int, name: string}|null, confirmed: bool, editable: bool, is_last: bool, airs_this_week: bool}
     */
    public static function for(Save $save, Show $show, BookingOrder $order): array
    {
        $airsThisWeek = $show->week_number === $save->current_week && $show->status->value === 'booking';
        $blocker = $airsThisWeek ? $order->blocker($save, $show) : null;
        $next = $order->next($save, $show);

        return [
            'steps' => array_values($order->steps($save, $show->week_number)->map(fn (Show $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'show_type' => $s->show_type->value,
                'confirmed' => $s->confirmed_at !== null,
                'current' => $s->is($show),
            ])->all()),
            'blocked_by' => $blocker === null ? null : ['id' => $blocker->id, 'name' => $blocker->name],
            'next' => $next === null ? null : ['id' => $next->id, 'name' => $next->name],
            'confirmed' => $show->confirmed_at !== null,
            'editable' => $airsThisWeek && $blocker === null && $show->confirmed_at === null,
            'is_last' => $next === null,
            'airs_this_week' => $airsThisWeek,
        ];
    }
}
