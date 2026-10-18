<?php

namespace App\Http\Controllers\Gm;

use App\Enums\Stipulation;
use App\Exceptions\GameRuleException;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CardPresenter;
use App\Http\Presenters\TitleContextPresenter;
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
use App\Services\Titles\MitbService;
use App\Services\Titles\TitleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
        private TitleContextPresenter $titleContext,
        private MitbService $mitb,
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
            'title_context' => $this->titleContext->forShow($save, $show, $brand),
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
            'stipulation' => ['nullable', Rule::enum(Stipulation::class)],
            'stipulation_championship_id' => ['nullable', 'integer', 'exists:championships,id'],
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

    public function cashIn(Save $save, Show $show): RedirectResponse
    {
        $this->assertOwnShow($save, $show);
        $briefcase = $this->mitb->held($save)->first(fn ($b): bool => $b->brand === $save->player_brand)
            ?? throw new GameRuleException('You have no Money in the Bank briefcase to cash in.');

        DB::transaction(fn () => $this->mitb->cashIn($show, $briefcase));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$briefcase->holder->name()} is cashing in!"]);

        return back();
    }

    private function assertOwnShow(Save $save, Show $show): void
    {
        if ($show->brand !== null && $show->brand !== $save->player_brand) {
            throw new GameRuleException("That's the rival brand's card.");
        }
    }
}
