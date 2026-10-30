<?php

namespace App\Services\Card;

use App\Enums\Brand;
use App\Enums\ParticipantRole;
use App\Enums\ShowStatus;
use App\Models\MatchSlot;
use App\Models\PromoSlot;
use App\Models\SaveWrestler;
use App\Models\Show;

/**
 * Each wrestler's fatigue going into a card: what they carry now, plus the
 * matches and tiring promos already booked for them on this week's unaired
 * cards. The rival GM books within it, and so do Randomize and Preset.
 */
class PlannedFatigue
{
    /**
     * @param  list<int>  $exceptSlotIds  match slots being rebooked, left out
     * @return array<int, int> save-wrestler id => fatigue
     */
    public function forWeek(Show $show, Brand $brand, array $exceptSlotIds = []): array
    {
        $fatigue = SaveWrestler::where('save_id', $show->save_id)->where('brand', $brand)->pluck('fatigue', 'id')
            ->mapWithKeys(fn ($f, $id): array => [(int) $id => (int) $f])
            ->all();

        $shows = Show::where('save_id', $show->save_id)->where('week_number', $show->week_number)->where('status', '!=', ShowStatus::Aired)->pluck('id');

        foreach (MatchSlot::with(['participants', 'matchType'])->whereIn('show_id', $shows)->whereNotIn('id', $exceptSlotIds)->get() as $slot) {
            if ($slot->matchType === null) {
                continue;
            }

            foreach ($slot->participants->where('role', ParticipantRole::Competitor) as $p) {
                $fatigue[$p->save_wrestler_id] = ($fatigue[$p->save_wrestler_id] ?? 0) + $slot->matchType->fatigueCost();
            }
        }

        $bar = (int) config('gm.fatigue.bar_size');

        foreach (PromoSlot::with('promoType')->whereIn('show_id', $shows)->whereNotNull('promo_type_id')->get() as $promo) {
            foreach ($promo->targetIds() as $id) {
                $fatigue[$id] = ($fatigue[$id] ?? 0) + (int) $promo->promoType?->fatigue_bars * $bar;
            }
        }

        return $fatigue;
    }
}
