<?php

namespace App\Http\Presenters;

use App\Enums\MatchMethod;
use App\Enums\ParticipantRole;
use App\Models\MatchParticipant;
use App\Models\MatchResult;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Models\Show;

/**
 * Card builder and outcome screen payloads (§4, §20).
 */
class CardPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function show(Show $show): array
    {
        return [
            'id' => $show->id,
            'name' => $show->name,
            'show_type' => $show->show_type->value,
            'brand' => $show->brand?->value,
            'status' => $show->status->value,
            'week_number' => $show->week_number,
            'ppv_event_id' => $show->ppv_event_id,
            'match_slot_count' => $show->matchSlotCount(),
            'promo_slot_count' => $show->promoSlotCount(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function slot(MatchSlot $slot): array
    {
        $sides = [];
        $managers = [];

        if ($slot->matchType !== null) {
            foreach ($slot->matchType->sides as $index => $size) {
                $sides[$index] = array_fill(0, $size, null);
            }
        }

        $filled = [];

        foreach ($slot->participants as $p) {
            if ($p->role === ParticipantRole::Manager) {
                $managers[$p->side] = $p->save_wrestler_id;

                continue;
            }

            $i = $filled[$p->side] ?? 0;
            $sides[$p->side][$i] = $p->save_wrestler_id;
            $filled[$p->side] = $i + 1;
        }

        return [
            'id' => $slot->id,
            'position' => $slot->position,
            'match_type_id' => $slot->match_type_id,
            'sides' => array_values($sides),
            'managers' => (object) $managers,
            'title_championship_id' => $slot->title_championship_id,
            'stipulation' => $slot->stipulation?->value,
            'cost' => $slot->cost,
            'fixed_kind' => $slot->fixed_kind?->value,
            'owner_brand' => $slot->owner_brand?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function matchType(MatchType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'family' => $type->family->value,
            'variant' => $type->variant,
            'sides' => $type->sides,
            'manager_sides' => $type->manager_sides,
            'fatigue_cost_bars' => $type->fatigue_cost_bars,
            'cost' => $type->cost,
            'divas_only' => $type->divas_only,
            'divas_allowed' => $type->divas_allowed,
            'titles_allowed' => $type->titles_allowed,
            'win_condition' => $type->win_condition,
            'ppv_only' => $type->ppv_only,
        ];
    }

    /**
     * One flashing outcome card (§20).
     *
     * @return array<string, mixed>
     */
    public static function outcome(MatchSlot $slot): array
    {
        /** @var MatchResult $result */
        $result = $slot->result;
        $winners = [];
        $losers = [];
        $managers = [];

        foreach ($slot->participants as $p) {
            $name = $p->saveWrestler->wrestler->name;

            match (true) {
                $p->role === ParticipantRole::Manager => $managers[] = $name,
                $p->side === $result->winner_side => $winners[] = $name,
                default => $losers[$p->side][] = $name,
            };
        }

        return [
            'position' => $slot->position,
            'match_type' => $slot->matchType?->name,
            'winners' => $winners,
            'losers' => array_values(array_map(fn (array $side): string => implode(' & ', $side), $losers)),
            'managers' => $managers,
            'method' => $result->method->value,
            'method_label' => $result->method === MatchMethod::MatchSpecific && $slot->matchType?->win_condition !== null ? $slot->matchType->win_condition : $result->method->label(),
            'finish_event' => $result->finish_event?->value,
            'finish_label' => $result->finish_event?->label(),
            'summary' => $result->summary,
            'title' => $slot->titleChampionship?->name,
            'title_banner' => $result->title_banner,
            'stipulation' => $slot->stipulation?->value,
            'stipulation_outcome' => $result->stipulation_outcome,
            'rating' => $slot->rating,
            'fan_delta' => $slot->fan_delta,
            'participants' => $slot->participants->map(fn (MatchParticipant $p): array => [
                'id' => $p->save_wrestler_id,
                'role' => $p->role->value,
                'side' => $p->side,
            ])->values()->all(),
        ];
    }
}
