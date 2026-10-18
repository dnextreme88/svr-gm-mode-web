<?php

namespace App\Http\Controllers\Gm;

use App\Enums\SaveStage;
use App\Http\Controllers\Controller;
use App\Http\Presenters\WrestlerPresenter;
use App\Models\Championship;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Titles\ChampionshipAssignment;
use App\Services\Titles\TitleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Assigning championships after the draft (§1) and awarding vacant titles (§16).
 */
class TitleAssignmentController extends Controller
{
    public function __construct(
        private ChampionshipAssignment $assignment,
        private TitleService $titles,
    ) {}

    public function show(Save $save): Response
    {
        $week = $save->current_week;

        $championships = Championship::where('brand', $save->player_brand)->orderBy('sort_order')->get()->map(function (Championship $c) use ($save, $week): array {
            $reign = $this->titles->currentReign($save, $c);

            return [
                'id' => $c->id,
                'key' => $c->key->value,
                'name' => $c->name,
                'is_tag' => $c->is_tag,
                'eligibility' => $c->eligibility->value,
                'holder' => $reign?->holderName(),
                'eligible' => array_values($this->assignment->eligible($save, $c)->map(fn (SaveWrestler $w): array => WrestlerPresenter::row($w, $week))->all()),
            ];
        });

        return Inertia::render('gm/titles/assign', [
            'championships' => $championships,
            'initial' => $save->stage === SaveStage::AssigningTitles,
        ]);
    }

    public function store(Request $request, Save $save, Championship $championship): RedirectResponse
    {
        $data = $request->validate([
            'save_wrestler_ids' => ['required', 'array', 'min:1', 'max:2'],
            'save_wrestler_ids.*' => ['integer'],
        ]);

        abort_unless($championship->brand === $save->player_brand, 403);

        $this->assignment->assign($save, $championship, array_values(array_map('intval', $data['save_wrestler_ids'])));

        return back();
    }

    public function complete(Save $save): RedirectResponse
    {
        DB::transaction(fn () => $this->assignment->complete($save));

        return to_route('gm.schedule', $save);
    }
}
