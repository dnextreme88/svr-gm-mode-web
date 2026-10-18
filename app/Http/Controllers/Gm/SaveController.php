<?php

namespace App\Http\Controllers\Gm;

use App\Enums\Brand;
use App\Enums\SaveStage;
use App\Http\Controllers\Controller;
use App\Models\Save;
use App\Services\Calendar\CalendarService;
use App\Services\Game\NewGameService;
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
    public function index(Request $request, CalendarService $calendar): Response
    {
        $saves = Save::with('brands')
            ->where('user_id', $request->user()?->id)
            ->latest('updated_at')
            ->get()
            ->map(fn (Save $save): array => [
                'id' => $save->id,
                'name' => $save->name,
                'player_brand' => $save->player_brand->value,
                'stage' => $save->stage->value,
                'season' => $calendar->seasonOf($save->current_week),
                'season_week' => $calendar->seasonWeek($save->current_week),
                'month' => $calendar->monthLabel($save->current_week),
                'budget' => $save->brands->firstWhere('brand', $save->player_brand)?->budget,
                'updated_at' => $save->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('gm/saves/index', ['saves' => $saves]);
    }

    public function store(Request $request, NewGameService $newGame): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'brand' => ['required', Rule::enum(Brand::class)],
        ]);

        $save = $newGame->create($request->user(), $data['name'], Brand::from($data['brand']));

        return to_route('gm.draft.show', $save);
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
