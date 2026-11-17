<?php

namespace App\Http\Controllers\Gm;

use App\Enums\Brand;
use App\Enums\SaveStage;
use App\Http\Controllers\Controller;
use App\Http\Presenters\WrestlerPresenter;
use App\Models\DraftPick;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Injuries\InjuryStatus;
use App\Services\Ppv\ReDraftService;
use App\Services\Roster\RosterService;
use App\Services\Titles\TitleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The post-WrestleMania re-draft page (house rule, 2026-11-15): the
 * immunity tag, the manual draft pick, then 5 animated picks each way.
 * Continue writes WrestleMania week's news and opens it.
 */
class RedraftController extends Controller
{
    public function __construct(
        private ReDraftService $redraft,
        private RosterService $roster,
        private InjuryStatus $injuries,
        private TitleService $titles,
    ) {}

    public function show(Save $save): Response
    {
        $this->assertRunning($save);
        $series = $this->redraft->prepare($save);
        abort_if($series === null, 404);

        $week = $save->current_week;
        $injuries = $this->injuries->activeByWrestler($save->id, $week);
        $reigns = $this->titles->currentReigns($save);
        $champions = $reigns->flatMap(fn ($r) => $r->holderIds())->all();
        $picks = $this->redraft->picks($save);
        $drafted = $picks->pluck('save_wrestler_id')->filter()->all();

        $present = fn (Brand $brand) => $this->roster->roster($save, $brand)->map(fn (SaveWrestler $w): array => [
            ...WrestlerPresenter::row($w, $week, $injuries, $reigns),
            'locked' => match (true) {
                in_array($w->id, $champions, true) => 'Champion',
                in_array($w->id, $series->exemptIds(), true) => 'Immune',
                in_array($w->id, $drafted, true) => 'Drafted',
                default => null,
            },
        ])->values();

        return Inertia::render('gm/redraft', [
            'phase' => $this->redraft->phase($save, $series),
            'series' => [
                'season' => $series->season,
                // When the career was created: with its id and the season it
                // keys what this browser has seen (the rules modal, the
                // rival's tag reels), so a rebuilt career reusing an id
                // starts fresh (fix, 2026-11-17).
                'career' => $save->created_at->timestamp,
                'raw_wins' => $series->raw_wins,
                'smackdown_wins' => $series->smackdown_wins,
                'winner_brand' => $series->winner_brand?->value,
                'immunity_brand' => $series->immunity_brand?->value,
                'exemption_wrestler_ids' => $series->exemptIds(),
                'handpick_wrestler_ids' => $series->handpickIds(),
                // House rule (2026-11-17): how many each tag takes this time.
                'immunity_needed' => $this->redraft->immunityNeeded($save, $series),
                'manual_needed' => $this->redraft->manualNeeded($save, $series),
                'crowds' => $this->redraft->crowds($save, $series),
            ],
            'rosters' => [
                Brand::Raw->value => $present(Brand::Raw),
                Brand::Smackdown->value => $present(Brand::Smackdown),
            ],
            'order' => array_map(fn (Brand $b): string => $b->value, $this->redraft->order($save, $series)),
            'per_brand' => $this->redraft->perBrand(),
            'picks' => $picks->map(fn (DraftPick $p): array => $this->pickRow($p))->values(),
        ]);
    }

    public function immunity(Request $request, Save $save): RedirectResponse
    {
        $this->assertRunning($save);
        $data = $request->validate(['save_wrestler_ids' => ['required', 'array', 'min:1'], 'save_wrestler_ids.*' => ['integer']]);

        DB::transaction(fn () => $this->redraft->setImmunity($save, array_values(array_map('intval', $data['save_wrestler_ids']))));

        return back();
    }

    public function handpick(Request $request, Save $save): RedirectResponse
    {
        $this->assertRunning($save);
        $data = $request->validate(['save_wrestler_ids' => ['required', 'array', 'min:1'], 'save_wrestler_ids.*' => ['integer']]);

        DB::transaction(fn () => $this->redraft->setHandpick($save, array_values(array_map('intval', $data['save_wrestler_ids']))));

        return back();
    }

    /**
     * One pick, for the page to animate.
     */
    public function step(Save $save): JsonResponse
    {
        $this->assertRunning($save);
        $pick = DB::transaction(fn (): ?DraftPick => $this->redraft->step($save));
        $series = $this->redraft->series($save);

        return response()->json([
            'pick' => $pick === null ? null : $this->pickRow($pick),
            'done' => $series === null || $this->redraft->phase($save, $series) === 'done',
        ]);
    }

    public function finish(Save $save): RedirectResponse
    {
        $this->assertRunning($save);
        DB::transaction(fn () => $this->redraft->finish($save));

        return to_route('gm.news', $save);
    }

    /**
     * The re-draft only exists between WrestleMania's main event and
     * Continue (house rule, 2026-11-16): any other time, typing the URL
     * gets the 404 page, not a peek at next season's re-draft.
     */
    private function assertRunning(Save $save): void
    {
        abort_unless($save->stage === SaveStage::Redrafting, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function pickRow(DraftPick $p): array
    {
        return [
            'pick_number' => $p->pick_number,
            'brand' => $p->brand->value,
            'from' => $p->brand->rival()->value,
            'save_wrestler_id' => $p->save_wrestler_id,
            'name' => $p->saveWrestler?->name(),
            'pick_type' => $p->pick_type->value,
            'skipped' => $p->skipped,
        ];
    }
}
