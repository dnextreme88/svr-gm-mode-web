<?php

namespace App\Http\Controllers\Gm;

use App\Enums\InjurySeverity;
use App\Enums\Stipulation;
use App\Http\Controllers\Controller;
use App\Models\InjuryType;
use App\Models\MatchType;
use App\Models\PromoType;
use App\Models\Save;
use App\Models\Wrestler;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reference pages built from the catalog: match types (§10), fatigue and
 * injuries (§6), specialties (§21) and stipulations (§22). Nothing here is
 * per-save: it's served both inside a career (with the GM header and nav)
 * and from the save list, without one.
 */
class DocsController extends Controller
{
    /**
     * `$save` is only bound on the in-career route, so the GM header is shared.
     */
    public function __invoke(?Save $save = null): Response
    {
        $matchTypes = MatchType::with('ppvExclusive')
            ->orderBy('sort_order')
            ->get()
            ->groupBy(fn (MatchType $type): string => $type->family->value)
            ->map(fn ($types, string $family): array => [
                'family' => $family,
                'label' => $types->first()->family->label(),
                'variants' => $types->map(fn (MatchType $type): array => [
                    'id' => $type->id,
                    'variant' => $type->variant,
                    'sides' => $type->sides,
                    'competitors' => $type->competitorCount(),
                    'managers' => count($type->manager_sides),
                    'fatigue_bars' => $type->fatigue_cost_bars,
                    'cost' => $type->cost,
                    'dq' => $type->dq_enabled,
                    'countout' => $type->countout_enabled,
                    'rope_breaks' => $type->rope_breaks,
                    'ring_out' => $type->ring_out,
                    'captain_fall' => $type->captain_fall,
                    'timed' => $type->timed,
                    'titles_allowed' => $type->titles_allowed,
                    'divas_only' => $type->divas_only,
                    'divas_allowed' => $type->divas_allowed,
                    'elimination' => $type->is_elimination,
                    'ppv_only' => $type->ppv_only,
                    'ppv_exclusive' => $type->ppvExclusive?->name,
                    'brand' => $type->brand_restriction?->value,
                    'gm_bookable' => $type->gm_bookable,
                    'special_referee' => $type->hasSpecialReferee(),
                    'win_condition' => $type->win_condition,
                ])->values(),
            ])
            ->values();

        $injuries = InjuryType::with('escalatesTo')
            ->orderBy('min_weeks')
            ->orderBy('label')
            ->get()
            ->map(fn (InjuryType $type): array => [
                'label' => $type->label,
                'severity' => $type->severity->value,
                'min_weeks' => $type->min_weeks,
                'max_weeks' => $type->max_weeks,
                'escalates_to' => $type->escalatesTo?->label,
            ]);

        $specialists = Wrestler::with('specialties')
            ->whereHas('specialties')
            ->orderBy('name')
            ->get()
            ->map(fn (Wrestler $wrestler): array => [
                'name' => $wrestler->name,
                'roster_type' => $wrestler->roster_type->value,
                'weight_class' => $wrestler->weight_class->value,
                'specialties' => $wrestler->specialtyFamilies(),
            ]);

        return Inertia::render('gm/docs', [
            'match_types' => $matchTypes,
            'injuries' => [
                'minor' => $injuries->where('severity', InjurySeverity::Minor->value)->values(),
                'serious' => $injuries->where('severity', InjurySeverity::Serious->value)->values(),
            ],
            'fatigue' => [
                'bar_size' => (int) config('gm.fatigue.bar_size'),
                'max' => (int) config('gm.fatigue.max'),
                'safe_max' => (int) config('gm.fatigue.safe_max'),
                'serious_at' => (int) config('gm.fatigue.serious_at'),
                'rest_recovery_bars' => (int) config('gm.fatigue.rest_recovery_bars'),
                'minor_chance' => (float) config('gm.injuries.minor_chance'),
                'escalation_chance' => (float) config('gm.injuries.escalation_chance'),
            ],
            'specialists' => $specialists,
            'promos' => [
                'slots' => (int) config('gm.card.weekly_promo_slots'),
                'types' => PromoType::orderBy('name')->get()->map(fn (PromoType $t): array => [
                    'key' => $t->key->value,
                    'name' => $t->name,
                    'amount' => $t->base_amount,
                    'variable_amount' => $t->variable_amount,
                    'success_rate' => $t->successRate(),
                    'fatigue_bars' => $t->fatigue_bars,
                    'recovery_bars' => (int) config('gm.promos.recovery_bars.'.$t->key->value, 0),
                    'targets_rival' => $t->targets_rival,
                    'requirement' => $t->requirement,
                    'effect' => $t->effect,
                ])->values(),
            ],
            'stipulations' => array_map(fn (Stipulation $s): array => [
                'value' => $s->value,
                'label' => $s->label(),
            ], Stipulation::cases()),
        ]);
    }
}
