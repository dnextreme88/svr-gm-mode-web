<?php

namespace App\Http\Controllers\Gm;

use App\Exceptions\GameRuleException;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CardPresenter;
use App\Http\Presenters\WrestlerPresenter;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Models\Save;
use App\Models\Show;
use App\Services\Card\Booking;
use App\Services\Card\CardRandomizer;
use App\Services\Card\CardService;
use App\Services\Injuries\InjuryStatus;
use App\Services\Roster\RosterService;
use App\Services\Titles\TitleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The card builder (§4).
 */
class CardController extends Controller
{
    public function __construct(
        private CardService $cards,
        private CardRandomizer $randomizer,
        private RosterService $roster,
        private InjuryStatus $injuries,
        private TitleService $titles,
    ) {}

    public function show(Save $save, Show $show): Response
    {
        $this->assertOwnShow($save, $show);
        $this->cards->ensureSlots($show);
        $week = $show->week_number;
        $brand = $show->brand ?? $save->player_brand;
        $active = $this->injuries->activeByWrestler($save->id, $week);
        $reigns = $this->titles->currentReigns($save);

        $roster = $this->roster->roster($save, $brand)->map(function ($w) use ($week, $active, $reigns): array {
            $row = WrestlerPresenter::row($w, $week, $active, $reigns);
            $blocked = match (true) {
                ($row['injury']['severity'] ?? null) === 'serious' => 'Seriously injured',
                $row['sitting_out'] => 'Sitting out (blocked defection)',
                default => null,
            };

            return [...$row, 'bookable' => $blocked === null, 'blocked_reason' => $blocked];
        })->values();

        $types = $this->randomizer->eligibleTypes($show, $brand)->map(fn (MatchType $t): array => CardPresenter::matchType($t))->values();

        return Inertia::render('gm/card', [
            'show' => CardPresenter::show($show),
            'slots' => MatchSlot::with('participants')->where('show_id', $show->id)->orderBy('position')->get()->map(fn (MatchSlot $s): array => CardPresenter::slot($s))->values(),
            'match_types' => $types,
            'roster' => $roster,
        ]);
    }

    public function book(Request $request, Save $save, Show $show, int $position): RedirectResponse
    {
        $this->assertOwnShow($save, $show);

        $data = $request->validate([
            'match_type_id' => ['required', 'integer', 'exists:match_types,id'],
            'sides' => ['required', 'array', 'min:2'],
            'sides.*' => ['array', 'min:1'],
            'sides.*.*' => ['integer'],
            'managers' => ['nullable', 'array'],
            'managers.*' => ['nullable', 'integer'],
            'title_championship_id' => ['nullable', 'integer', 'exists:championships,id'],
            'stipulation' => ['nullable', 'string'],
        ]);

        DB::transaction(fn () => $this->cards->book($show, $position, Booking::fromArray($data), $save->player_brand));

        return back();
    }

    public function clear(Save $save, Show $show, int $position): RedirectResponse
    {
        $this->assertOwnShow($save, $show);
        $this->cards->clear($show, $position);

        return back();
    }

    private function assertOwnShow(Save $save, Show $show): void
    {
        if ($show->brand !== null && $show->brand !== $save->player_brand) {
            throw new GameRuleException("That's the rival brand's card.");
        }
    }
}
