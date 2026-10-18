<?php

namespace App\Http\Controllers\Gm;

use App\Enums\ContractLength;
use App\Http\Controllers\Controller;
use App\Http\Presenters\WrestlerPresenter;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Contracts\ContractPricing;
use App\Services\Injuries\InjuryStatus;
use App\Services\Roster\RosterService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sign unsigned Superstars, Divas and Legends (§3 Free Agency).
 */
class FreeAgencyController extends Controller
{
    public function __invoke(Save $save, RosterService $roster, ContractPricing $pricing, InjuryStatus $injuries): Response
    {
        $week = $save->current_week;
        $active = $injuries->activeByWrestler($save->id, $week);

        $available = $roster->freeAgentsQuery($save, $week)
            ->with('wrestler.specialties')
            ->get()
            ->sortBy(fn (SaveWrestler $w): string => $w->wrestler->name)
            ->map(fn (SaveWrestler $w): array => [
                ...WrestlerPresenter::row($w, $week, $active),
                'quotes' => $pricing->allQuotes($w->wrestler),
            ])
            ->values();

        $locked = SaveWrestler::with('wrestler')
            ->where('save_id', $save->id)
            ->whereNull('brand')
            ->where('unsignable_until_week', '>', $week)
            ->get()
            ->map(fn (SaveWrestler $w): array => ['id' => $w->id, 'name' => $w->wrestler->name, 'until_week' => $w->unsignable_until_week])
            ->values();

        return Inertia::render('gm/free-agency', [
            'available' => $available,
            'locked' => $locked,
            'lengths' => array_map(fn (ContractLength $l): array => ['value' => $l->value, 'label' => $l->label()], ContractLength::cases()),
            'has_room' => $roster->hasRoom($save, $save->player_brand),
        ]);
    }
}
