<?php

namespace App\Http\Presenters;

use App\Models\Injury;
use App\Models\SaveWrestler;
use App\Models\TitleReign;
use App\Models\WrestlerSpecialty;
use App\Services\Fatigue\FatigueZones;
use Illuminate\Support\Collection;

/**
 * Shapes a SaveWrestler for the React pages (§3 Rosters columns).
 */
class WrestlerPresenter
{
    /**
     * @param  array<int, Injury>  $injuries  active injuries keyed by save_wrestler_id
     * @param  Collection<int, TitleReign>  $reigns  current reigns
     * @return array<string, mixed>
     */
    public static function row(SaveWrestler $w, int $week, array $injuries = [], ?Collection $reigns = null): array
    {
        $injury = $injuries[$w->id] ?? null;
        $contract = $w->relationLoaded('activeContract') ? $w->activeContract : null;
        $titles = $reigns === null ? [] : array_values($reigns
            ->filter(fn (TitleReign $r): bool => in_array($w->id, $r->holderIds(), true))
            ->map(fn (TitleReign $r): string => $r->championship->name)
            ->all());

        return [
            'id' => $w->id,
            'name' => $w->wrestler->name,
            'slug' => $w->wrestler->slug,
            'roster_type' => $w->wrestler->roster_type->value,
            'weight_class' => $w->wrestler->weight_class->value,
            'brand' => $w->brand?->value,
            'morale' => $w->morale->value,
            'fatigue' => $w->fatigue,
            'fatigue_zone' => FatigueZones::zoneFor($w->fatigue)->value,
            'wins' => $w->wins,
            'losses' => $w->losses,
            'win_streak' => $w->win_streak,
            'loss_streak' => $w->loss_streak,
            'popularity' => $w->popularity,
            'specialties' => $w->wrestler->relationLoaded('specialties')
                ? array_values($w->wrestler->specialties->map(fn (WrestlerSpecialty $s): string => $s->family->value)->all())
                : [],
            'injury' => $injury === null ? null : [
                'label' => $injury->type->label,
                'severity' => $injury->severity->value,
                'weeks_left' => $injury->weeksLeft($week),
            ],
            'sitting_out' => $w->isSittingOut($week),
            'unbookable_until_week' => $w->unbookable_until_week,
            'contract' => $contract === null ? null : [
                'length_weeks' => $contract->length_weeks,
                'weeks_remaining' => $contract->weeks_remaining,
                'weekly_cost' => $contract->weekly_cost,
                'starts_week' => $contract->starts_week,
            ],
            'titles' => $titles,
        ];
    }
}
