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

    public function show(Save $save): Response|RedirectResponse
    {
        $series = $this->redraft->prepare($save);

        if ($save->stage !== SaveStage::Redrafting || $series === null) {
            return to_route('gm.news', $save);
        }

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
                $w->id === $series->exemption_wrestler_id => 'Immune',
                in_array($w->id, $drafted, true) => 'Drafted',
                default => null,
            },
        ])->values();

        return Inertia::render('gm/redraft', [
            'phase' => $this->redraft->phase($save, $series),
            'series' => [
                'season' => $series->season,
                'raw_wins' => $series->raw_wins,
                'smackdown_wins' => $series->smackdown_wins,
                'winner_brand' => $series->winner_brand?->value,
                'immunity_brand' => $series->immunity_brand?->value,
                'exemption_wrestler_id' => $series->exemption_wrestler_id,
                'handpick_wrestler_id' => $series->handpick_wrestler_id,
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
        $data = $request->validate(['save_wrestler_id' => ['required', 'integer']]);

        DB::transaction(fn () => $this->redraft->setImmunity($save, (int) $data['save_wrestler_id']));

        return back();
    }

    public function handpick(Request $request, Save $save): RedirectResponse
    {
        $data = $request->validate(['save_wrestler_id' => ['required', 'integer']]);

        DB::transaction(fn () => $this->redraft->setHandpick($save, (int) $data['save_wrestler_id']));

        return back();
    }

    /**
     * One pick, for the page to animate.
     */
    public function step(Save $save): JsonResponse
    {
        $pick = $save->stage === SaveStage::Redrafting ? DB::transaction(fn (): ?DraftPick => $this->redraft->step($save)) : null;
        $series = $this->redraft->series($save);

        return response()->json([
            'pick' => $pick === null ? null : $this->pickRow($pick),
            'done' => $series === null || $this->redraft->phase($save, $series) === 'done',
        ]);
    }

    public function finish(Save $save): RedirectResponse
    {
        DB::transaction(fn () => $this->redraft->finish($save));

        return to_route('gm.news', $save);
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
