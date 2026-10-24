<?php

namespace App\Http\Controllers\Gm;

use App\Enums\Brand;
use App\Enums\DraftMode;
use App\Enums\SaveStage;
use App\Http\Controllers\Controller;
use App\Models\Save;
use App\Services\Calendar\CalendarService;
use App\Services\Game\NewGameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Save slots and new games (§1).
 */
class SaveController extends Controller
{
    /**
     * Only careers the player has saved are listed. The latest unsaved one
     * (if any) is offered separately so it can be resumed or discarded.
     */
    public function index(CalendarService $calendar): Response
    {
        $present = fn (Save $save): array => [
            'id' => $save->id,
            'name' => $save->name,
            'player_brand' => $save->player_brand->value,
            'stage' => $save->stage->value,
            'season' => $calendar->seasonOf($save->current_week),
            'season_week' => $calendar->seasonWeek($save->current_week),
            'month' => $calendar->monthLabel($save->current_week),
            'budget' => $save->brands->firstWhere('brand', $save->player_brand)?->budget,
            'updated_at' => $save->updated_at?->toIso8601String(),
        ];

        $saves = Save::with('brands')->whereNotNull('saved_at')->latest('updated_at')->get()->map($present);
        $unsaved = Save::with('brands')->whereNull('saved_at')->latest('id')->first();

        return Inertia::render('gm/saves/index', [
            'saves' => $saves,
            'unsaved' => $unsaved === null ? null : $present($unsaved),
            'starting_budget' => (int) config('gm.starting_budget'),
        ]);
    }

    /**
     * Starting a new game throws away any career that was never saved.
     * JSON callers get the first pick back, so the new-game screen can
     * reveal it before heading to the draft. A "Use WWE roster" game skips
     * the draft and heads straight to title assignment.
     */
    public function store(Request $request, NewGameService $newGame): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'brand' => ['required', Rule::enum(Brand::class)],
            'draft_mode' => ['sometimes', Rule::enum(DraftMode::class)],
        ]);
        $mode = DraftMode::from($data['draft_mode'] ?? DraftMode::Manual->value);

        Save::whereNull('saved_at')->get()->each->delete();

        $save = $newGame->create($data['name'], Brand::from($data['brand']), mode: $mode);
        $next = $mode === DraftMode::WweRoster ? route('gm.titles.assign', $save) : route('gm.draft.show', $save);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'next_url' => $next,
                'first_brand' => $save->draft_order[0],
            ], 201);
        }

        return redirect($next);
    }

    /**
     * Save the career so it's listed under Saved careers. Progress is kept
     * as it happens from then on; saving again just refreshes the timestamp.
     */
    public function persist(Save $save): RedirectResponse
    {
        $save->update(['saved_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Saved \"{$save->name}\"."]);

        return back();
    }

    public function show(Save $save): RedirectResponse
    {
        return match ($save->stage) {
            SaveStage::Drafting => to_route('gm.draft.show', $save),
            SaveStage::AssigningTitles => to_route('gm.titles.assign', $save),
            SaveStage::Redrafting => to_route('gm.redraft.show', $save),
            SaveStage::InSeason => to_route('gm.schedule', $save),
        };
    }

    public function destroy(Save $save): RedirectResponse
    {
        $save->delete();

        return to_route('gm.saves.index');
    }
}
