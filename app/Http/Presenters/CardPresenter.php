<?php

namespace App\Http\Presenters;

use App\Enums\Brand;
use App\Enums\MatchFamily;
use App\Enums\MatchMethod;
use App\Enums\ParticipantRole;
use App\Models\Championship;
use App\Models\GauntletBout;
use App\Models\MatchParticipant;
use App\Models\MatchResult;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Models\Show;
use App\Services\Factions\FactionNamer;

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
    public static function slot(MatchSlot $slot, ?Brand $playerBrand = null): array
    {
        $sides = [];
        $managers = [];
        $referee = null;

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

            if ($p->role === ParticipantRole::Referee) {
                $referee = $p->save_wrestler_id;

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
            'referee_id' => $referee,
            'title_championship_id' => $slot->title_championship_id,
            'stipulation' => $slot->stipulation?->value,
            'stipulation_championship_id' => $slot->stipulation_championship_id,
            'contested_title_ids' => $slot->contested_title_ids ?? [],
            'cost' => $slot->cost,
            'fixed_kind' => $slot->fixed_kind?->value,
            'owner_brand' => $slot->owner_brand?->value,
            'locked' => $slot->fixed_kind !== null || ($playerBrand !== null && $slot->owner_brand !== null && $slot->owner_brand !== $playerBrand),
            'names' => (object) $slot->participants->mapWithKeys(fn (MatchParticipant $p): array => [$p->save_wrestler_id => $p->saveWrestler->wrestler->name])->all(),
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
            'captain_fall' => $type->captain_fall,
            'special_referee' => $type->hasSpecialReferee(),
            // House rule: the stipulations this type can take (a Gauntlet only takes #1 Contender's Match).
            'allowed_stipulations' => array_map(fn ($s) => $s->value, $type->allowedStipulations()),
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
        $referee = null;

        foreach ($slot->participants as $p) {
            $name = $p->saveWrestler->wrestler->name;

            match (true) {
                $p->role === ParticipantRole::Manager => $managers[$p->side] = $name,
                $p->role === ParticipantRole::Referee => $referee = $name,
                $p->side === $result->winner_side => $winners[] = $name,
                default => $losers[$p->side][] = $name,
            };
        }

        ksort($losers);

        return [
            'id' => $slot->id,
            'pending' => false,
            'position' => $slot->position,
            'match_type' => $slot->matchType?->name,
            'winners' => $winners,
            'loser_sides' => array_values($losers),
            'booked' => $result->booked,
            'champions' => $result->championReign === null || $result->championReign->isVacant() ? [] : $result->championReign->holder_snapshot['names'],
            'captains' => self::captains($slot),
            'factions' => self::factions($slot),
            'winners_label' => self::sideLabel($slot, $result->winner_side),
            'losers' => array_map(fn (int $side): string => self::sideLabel($slot, $side), array_keys($losers)),
            'managers' => array_values($managers),
            // Each side's manager, for "Snitsky w/ Eugene".
            'winner_manager' => $managers[$result->winner_side] ?? null,
            'loser_side_managers' => array_map(fn (int $side): ?string => $managers[$side] ?? null, array_keys($losers)),
            'referee' => $referee,
            'method' => $result->method->value,
            'method_label' => $result->method === MatchMethod::MatchSpecific && $slot->matchType?->win_condition !== null ? $slot->matchType->win_condition : $result->method->label(),
            'finish_event' => $result->finish_event?->value,
            'finish_label' => $result->finish_event?->label(),
            'summary' => $result->summary,
            'title' => $slot->titleChampionship?->name,
            'title_banner' => $result->title_banner,
            'stipulation' => $slot->stipulation?->value,
            'stipulation_outcome' => $result->stipulation_outcome,
            ...self::stipulationTitles($slot),
            // Money in the Bank cash-in: before, during or after (house rule).
            'mitb_cash_in' => $slot->mitb_cash_in?->value,
            'titles_won' => self::titleNames(array_map(fn (array $won): int => $won['championship_id'], $result->titles_won ?? [])),
            'bouts' => self::bouts($slot),
            'rating' => $slot->rating,
            'fan_delta' => $slot->fan_delta,
            'participants' => $slot->participants->map(fn (MatchParticipant $p): array => [
                'id' => $p->save_wrestler_id,
                'role' => $p->role->value,
                'side' => $p->side,
            ])->values()->all(),
        ];
    }

    /**
     * A match still to be decided on the live results screen.
     *
     * @param  list<string>  $champions  the current holders when a title is on the line
     * @return array<string, mixed>
     */
    public static function pending(MatchSlot $slot, bool $isNext, array $champions): array
    {
        $sides = [];
        $managers = [];
        $referee = null;

        foreach ($slot->participants as $p) {
            $w = $p->saveWrestler->wrestler;

            match (true) {
                $p->role === ParticipantRole::Manager => $managers[$p->side] = $w->name,
                $p->role === ParticipantRole::Referee => $referee = $w->name,
                default => $sides[$p->side][] = ['id' => $p->save_wrestler_id, 'name' => $w->name, 'roster_type' => $w->roster_type->value, 'weight_class' => $w->weight_class->value],
            };
        }

        ksort($sides);

        return [
            'id' => $slot->id,
            'pending' => true,
            'is_next' => $isNext,
            'position' => $slot->position,
            'match_type' => $slot->matchType?->name,
            'win_condition' => $slot->matchType?->win_condition,
            'sides' => array_map(fn (int $side, array $members): array => ['side' => $side, 'members' => $members, 'manager' => $managers[$side] ?? null], array_keys($sides), array_values($sides)),
            'managers' => array_values($managers),
            'referee' => $referee,
            'title' => $slot->titleChampionship?->name,
            'stipulation' => $slot->stipulation?->value,
            ...self::stipulationTitles($slot),
            'mitb_cash_in' => $slot->mitb_cash_in?->value,
            'champions' => $champions,
            'captains' => self::captains($slot),
            'factions' => self::factions($slot),
            'gauntlet' => $slot->matchType?->family === MatchFamily::Gauntlet,
        ];
    }

    /**
     * A Gauntlet's bouts, in order (house rule).
     *
     * @return list<array<string, mixed>>
     */
    private static function bouts(MatchSlot $slot): array
    {
        if ($slot->matchType?->family !== MatchFamily::Gauntlet) {
            return [];
        }

        $names = $slot->competitors()->mapWithKeys(fn (MatchParticipant $p): array => [$p->side => $p->saveWrestler->wrestler->name]);

        return array_values($slot->gauntletBouts->map(fn (GauntletBout $bout): array => [
            'round' => $bout->round,
            'winner' => $names[$bout->winner_side] ?? '?',
            'loser' => $names[$bout->winner_side === $bout->side_a ? $bout->side_b : $bout->side_a] ?? '?',
            'method_label' => $bout->method->label(),
            'booked' => $bout->booked,
            'summary' => $bout->summary,
        ])->all());
    }

    /**
     * Factions teaming in this match (house rule): each faction's name and
     * its members on one side, so the results show "Team Xtreme" in place of
     * "Matt Hardy & Jeff Hardy".
     *
     * @return list<array{name: string, members: list<string>}>
     */
    private static function factions(MatchSlot $slot): array
    {
        $namer = app(FactionNamer::class);
        $names = $slot->competitors()->mapWithKeys(fn (MatchParticipant $p): array => [$p->save_wrestler_id => $p->saveWrestler->wrestler->name]);
        $factions = [];

        foreach ($slot->sides() as $ids) {
            foreach ($namer->groups($slot->show->save_id, $ids, $slot->show->week_number) as $group) {
                if ($group['faction'] !== null) {
                    $factions[] = [
                        'name' => $group['faction']->faction_name,
                        'members' => array_map(fn (int $id): string => (string) $names[$id], $group['ids']),
                    ];
                }
            }
        }

        return $factions;
    }

    /**
     * One side's name in plain text, factions named as such.
     */
    private static function sideLabel(MatchSlot $slot, int $side): string
    {
        return app(FactionNamer::class)->name($slot->show->save_id, $slot->sides()[$side] ?? [], $slot->show->week_number);
    }

    /**
     * In a Captain Fall match, the first wrestler on each side, for the
     * (Cpt) tag.
     *
     * @return list<string>
     */
    private static function captains(MatchSlot $slot): array
    {
        if (! $slot->matchType?->captain_fall) {
            return [];
        }

        $names = $slot->competitors()->mapWithKeys(fn (MatchParticipant $p): array => [$p->save_wrestler_id => $p->saveWrestler->wrestler->name]);

        return array_values(array_map(fn (array $ids): string => (string) $names[$ids[0]], $slot->sides()));
    }

    /**
     * The titles a stipulation is about (house rule): the one a Champion
     * vs. Contender or #1 Contender's Match is for, and a Winner Takes All
     * match's contested titles.
     *
     * @return array{stipulation_title: string|null, contested_titles: list<string>}
     */
    private static function stipulationTitles(MatchSlot $slot): array
    {
        return [
            'stipulation_title' => $slot->stipulation_championship_id === null ? null : Championship::find($slot->stipulation_championship_id)?->name,
            'contested_titles' => self::titleNames($slot->contested_title_ids ?? []),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private static function titleNames(array $ids): array
    {
        return $ids === [] ? [] : array_values(Championship::whereIn('id', $ids)->orderBy('sort_order')->pluck('name')->map(fn ($n): string => (string) $n)->all());
    }
}
