<?php

namespace App\Http\Controllers\Gm;

use App\Enums\Stipulation;
use App\Exceptions\GameRuleException;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BookingOrderPresenter;
use App\Http\Presenters\CardPresenter;
use App\Http\Presenters\TitleContextPresenter;
use App\Http\Presenters\WrestlerPresenter;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Models\PromoSlot;
use App\Models\PromoType;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Attendance\SummerSlamMainEvent;
use App\Services\Card\Booking;
use App\Services\Card\CardRandomizer;
use App\Services\Card\CardService;
use App\Services\Injuries\InjuryStatus;
use App\Services\Promos\PromoEligibility;
use App\Services\Roster\RosterService;
use App\Services\Titles\MitbService;
use App\Services\Titles\TitleService;
use App\Services\Week\BookingOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
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
        private PromoEligibility $promoEligibility,
        private BookingOrder $order,
        private SummerSlamMainEvent $summerSlam,
    ) {}

    public function show(Save $save, Show $show): Response|RedirectResponse
    {
        $this->assertOwnShow($save, $show);

        if ($show->isInterbrandMainEvent()) {
            return to_route('gm.ppv.entrants', [$save, $show]);
        }

        $this->cards->ensureSlots($show);
        $week = $show->week_number;
        $brand = $show->brand ?? $save->player_brand;
        $active = $this->injuries->activeByWrestler($save->id, $week);
        $reigns = $this->titles->currentReigns($save);

        $roster = $this->roster->roster($save, $brand)->map(function ($w) use ($week, $active, $reigns): array {
            $row = WrestlerPresenter::row($w, $week, $active, $reigns);
            $blocked = match (true) {
                ($row['injury']['severity'] ?? null) === 'serious' => 'Seriously injured',
                $row['sitting_out'] && $w->buried_week !== null => 'Buried alive',
                $row['sitting_out'] => 'Sitting out (blocked defection)',
                default => null,
            };

            return [...$row, 'bookable' => $blocked === null, 'blocked_reason' => $blocked];
        })->values();

        $types = $this->randomizer->eligibleTypes($show, $brand)->map(fn (MatchType $t): array => CardPresenter::matchType($t))->values();

        return Inertia::render('gm/card', [
            'show' => CardPresenter::show($show),
            'booking_order' => BookingOrderPresenter::for($save, $show, $this->order),
            'slots' => MatchSlot::with('participants.saveWrestler.wrestler')->where('show_id', $show->id)->orderBy('position')->get()->map(fn (MatchSlot $s): array => CardPresenter::slot($s, $save->player_brand))->values(),
            'match_types' => $types,
            'roster' => $roster,
            'title_context' => $this->titleContext->forShow($save, $show, $brand),
            // House rule (2026-11-01): the bigger draw closes SummerSlam.
            'summerslam' => ($headliner = $this->summerSlam->cardHeadliner($show)) === null
                ? null
                : ['main_event' => $headliner === $save->player_brand, 'headliner' => $headliner->label()],
            // Active Effects: Buried Alive losers still sitting out.
            'buried_alive' => SaveWrestler::with('wrestler')
                ->where('save_id', $save->id)
                ->where('brand', $brand)
                ->whereNotNull('buried_week')
                ->where('unbookable_until_week', '>', $week)
                ->orderBy('id')
                ->get()
                ->map(fn (SaveWrestler $w): array => [
                    'id' => $w->id,
                    'name' => $w->name(),
                    'weeks_left' => (int) $w->unbookable_until_week - $week,
                ])
                ->values(),
            'promo_slots' => PromoSlot::with('promoType')->where('show_id', $show->id)->orderBy('position')->get()->map(fn (PromoSlot $p): array => [
                'position' => $p->position,
                'promo_type_id' => $p->promo_type_id,
                'before_position' => $p->before_position,
                'target_wrestler_id' => $p->target_wrestler_id,
                'target_wrestler_b_id' => $p->target_wrestler_b_id,
                'target_championship_id' => $p->target_championship_id,
                'succeeded' => $p->succeeded,
                'backfired' => $p->backfired,
                'outcome_note' => $p->outcome_note,
            ])->values(),
            'promo_types' => PromoType::orderBy('sort_order')->get()->map(fn (PromoType $t): array => [
                'id' => $t->id,
                'key' => $t->key->value,
                'name' => $t->name,
                'amount' => $t->base_amount,
                'success_rate' => $t->successRate(),
                'success_range' => $t->successRange(),
                'needs_wrestler' => $t->needs_wrestler,
                'needs_rival_pair' => $t->needs_rival_pair,
                'needs_diva_pair' => $t->needs_diva_pair,
                'needs_championship' => $t->needs_championship,
                'fatigue_bars' => $t->fatigue_bars,
                'recovery_bars' => (int) config('gm.promos.recovery_bars.'.$t->key->value, 0),
                'targets_rival' => $t->targets_rival,
                'variable_amount' => $t->variable_amount,
                'effect' => $t->effect,
                'requirement' => $t->requirement,
            ])->values(),
            'promo_eligibility' => $show->promoSlotCount() > 0 ? $this->promoEligibility->forShow($show, $brand) : (object) [],
        ]);
    }

    public function book(Request $request, Save $save, Show $show, int $position): RedirectResponse|HttpResponse
    {
        $this->assertOwnShow($save, $show);
        $this->order->assertEditable($save, $show);

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
            'referee_id' => ['nullable', 'integer'],
        ]);

        DB::transaction(fn () => $this->cards->book($show, $position, Booking::fromArray($data), $save->player_brand));

        // Randomize / Preset confirm via fetch (§24); a redirect would re-send the PUT.
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->noContent();
        }

        return back();
    }

    public function clear(Save $save, Show $show, int $position): RedirectResponse
    {
        $this->assertOwnShow($save, $show);
        $this->order->assertEditable($save, $show);
        $this->cards->clear($show, $position, $save->player_brand);

        return back();
    }

    public function cashIn(Request $request, Save $save, Show $show): RedirectResponse
    {
        // Where it goes: before this match position, or after the last one.
        $position = $request->validate(['position' => ['nullable', 'integer', 'min:1']])['position'] ?? null;
        $this->assertOwnShow($save, $show);
        $this->order->assertEditable($save, $show);
        $briefcase = $this->mitb->held($save)->first(fn ($b): bool => $b->brand === $save->player_brand)
            ?? throw new GameRuleException('You have no Money in the Bank briefcase to cash in.');

        DB::transaction(fn () => $this->mitb->cashIn($show, $briefcase, $position === null ? null : (int) $position));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$briefcase->holder->name()} is cashing in!"]);

        return back();
    }

    /**
     * Finish this card and open the week's next one (booking order).
     */
    public function confirm(Save $save, Show $show): RedirectResponse
    {
        $this->assertOwnShow($save, $show);
        $next = $this->order->confirm($save, $show);

        return to_route('gm.card.show', [$save, $next]);
    }

    /**
     * Reopen a confirmed card (and every card after it) while the week hasn't aired.
     */
    public function unlock(Save $save, Show $show): RedirectResponse
    {
        $this->assertOwnShow($save, $show);
        $this->order->unlock($save, $show);

        return to_route('gm.card.show', [$save, $show]);
    }

    private function assertOwnShow(Save $save, Show $show): void
    {
        if ($show->brand !== null && $show->brand !== $save->player_brand) {
            throw new GameRuleException("That's the rival brand's card.");
        }
    }
}
