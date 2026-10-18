<?php

namespace App\Services\Titles;

use App\Enums\Brand;
use App\Enums\FixedSlotKind;
use App\Enums\MitbStatus;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\ParticipantRole;
use App\Enums\PpvKey;
use App\Enums\ShowStatus;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\MatchParticipant;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Models\MoneyInTheBank;
use App\Models\PpvEvent;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Calendar\CalendarService;
use App\Services\Injuries\InjuryStatus;
use App\Services\News\NewsRecorder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Money in the Bank briefcases (§10): award, cash-in, expiry and voiding.
 */
class MitbService
{
    public function __construct(
        private CalendarService $calendar,
        private TitleService $titles,
        private InjuryStatus $injuries,
        private NewsRecorder $news,
    ) {}

    public function heldBy(SaveWrestler $wrestler): ?MoneyInTheBank
    {
        return MoneyInTheBank::where('save_wrestler_id', $wrestler->id)
            ->where('status', MitbStatus::Held)
            ->first();
    }

    /**
     * @return Collection<int, MoneyInTheBank>
     */
    public function held(Save $save): Collection
    {
        return MoneyInTheBank::with('holder.wrestler')
            ->where('save_id', $save->id)
            ->where('status', MitbStatus::Held)
            ->get();
    }

    /**
     * The winner earns a shot at their own brand's world title. The briefcase
     * expires after 52 weeks or when the next WrestleMania resolves.
     */
    public function award(Save $save, SaveWrestler $winner, int $week): MoneyInTheBank
    {
        $brand = $winner->brand ?? throw new GameRuleException('The briefcase holder must be on a brand.');
        $nextMania = $this->nextWrestleManiaWeek($week);

        $briefcase = MoneyInTheBank::create([
            'save_id' => $save->id,
            'season' => $this->calendar->seasonOf($week),
            'brand' => $brand,
            'save_wrestler_id' => $winner->id,
            'won_week' => $week,
            'expires_week' => min($week + (int) config('gm.mitb.expiry_weeks'), $nextMania),
            'status' => MitbStatus::Held,
        ]);

        $this->news->record($save, NewsEventType::MitbWinner, NewsTier::Headline, NewsBrand::Both, [
            'wrestler' => $winner->name(),
        ], ['wrestler_ids' => [$winner->id]], priority: 75, week: $week);

        return $briefcase;
    }

    /**
     * Add a surprise cash-in to a card: a 1-on-1 world title match against the
     * current champion, appended after everything else. Allowed until the
     * week airs, even once the card is otherwise booked.
     */
    public function cashIn(Show $show, MoneyInTheBank $briefcase): MatchSlot
    {
        if (! $briefcase->isHeld()) {
            throw new GameRuleException('That briefcase has already been used.');
        }

        if ($show->status !== ShowStatus::Booking) {
            throw new GameRuleException("{$show->name} has already aired.");
        }

        if ($show->brand !== null && $show->brand !== $briefcase->brand) {
            throw new GameRuleException('A briefcase can only be cashed in on its own brand.');
        }

        $holder = $briefcase->holder;
        $championship = $this->worldTitle($briefcase->brand);
        $reign = $this->titles->currentReign($show->game, $championship);

        if ($reign === null) {
            throw new GameRuleException("The {$championship->name} is vacant; there's no one to cash in on.");
        }

        foreach ([$holder->id, ...$reign->holderIds()] as $id) {
            $wrestler = SaveWrestler::with('wrestler')->findOrFail($id);

            if ($this->injuries->isSeriouslyInjured($wrestler, $show->week_number)) {
                throw new GameRuleException("{$wrestler->name()} is seriously injured.");
            }
        }

        if (MatchSlot::where('show_id', $show->id)->where('fixed_kind', FixedSlotKind::MitbCashIn)->exists()) {
            throw new GameRuleException('This card already has a cash-in.');
        }

        $position = (int) MatchSlot::where('show_id', $show->id)->max('position') + 1;
        $slot = MatchSlot::create([
            'show_id' => $show->id,
            'position' => $position,
            'match_type_id' => MatchType::where('family', 'single')->where('variant', 'No Manager')->value('id'),
            'title_championship_id' => $championship->id,
            'fixed_kind' => FixedSlotKind::MitbCashIn,
            'owner_brand' => $briefcase->brand,
            'cost' => 0,
        ]);

        MatchParticipant::create(['match_slot_id' => $slot->id, 'save_wrestler_id' => $reign->holderIds()[0], 'side' => 0, 'role' => ParticipantRole::Competitor]);
        MatchParticipant::create(['match_slot_id' => $slot->id, 'save_wrestler_id' => $holder->id, 'side' => 1, 'role' => ParticipantRole::Competitor]);

        $briefcase->update(['cash_in_match_id' => $slot->id]);

        return $slot;
    }

    /**
     * Settle a cash-in once its match has resolved.
     */
    public function settleCashIn(MatchSlot $slot, bool $won, string $championName, int $week): void
    {
        $briefcase = MoneyInTheBank::with('holder.wrestler')->where('cash_in_match_id', $slot->id)->first();

        if ($briefcase === null) {
            return;
        }

        $briefcase->update([
            'status' => $won ? MitbStatus::CashedInWon : MitbStatus::CashedInLost,
            'resolved_week' => $week,
        ]);

        $this->news->record($briefcase->game, NewsEventType::MitbCashIn, NewsTier::Headline, NewsBrand::Both, [
            'wrestler' => $briefcase->holder->name(),
            'champion' => $championName,
            'result' => $won ? 'a new champion is crowned' : 'the champion survives',
        ], ['wrestler_ids' => [$briefcase->save_wrestler_id]], priority: 90, week: $week);
    }

    /**
     * Expire briefcases past their week, and warn when one has 4 weeks left.
     */
    public function expire(Save $save, int $week, bool $wrestleManiaResolved = false): void
    {
        $warn = (int) config('gm.mitb.expiring_warning_weeks');

        foreach ($this->held($save) as $briefcase) {
            if ($wrestleManiaResolved || $briefcase->expires_week <= $week + 1) {
                $briefcase->update(['status' => MitbStatus::Expired, 'resolved_week' => $week, 'resolution_note' => $wrestleManiaResolved ? 'wrestlemania' : 'expired']);

                continue;
            }

            if ($briefcase->expires_week - ($week + 1) === $warn) {
                $this->news->record($save, NewsEventType::MitbExpiring, NewsTier::Ticker, $briefcase->brand, [
                    'wrestler' => $briefcase->holder->name(),
                ], ['wrestler_ids' => [$briefcase->save_wrestler_id]], week: $week);
            }
        }
    }

    /**
     * Void a briefcase when its holder leaves the brand (trade, defection,
     * re-draft, Loser Leaves Show, release or expiry).
     */
    public function voidFor(SaveWrestler $wrestler, string $reason, int $week): ?MoneyInTheBank
    {
        $briefcase = $this->heldBy($wrestler);

        $briefcase?->update([
            'status' => MitbStatus::Voided,
            'resolved_week' => $week,
            'resolution_note' => $reason,
        ]);

        return $briefcase;
    }

    public function worldTitle(Brand $brand): Championship
    {
        return Championship::where('brand', $brand)->where('is_world', true)->firstOrFail();
    }

    private function nextWrestleManiaWeek(int $week): int
    {
        $maniaWeek = (int) PpvEvent::where('key', PpvKey::Wrestlemania)->value('season_week');
        $season = $this->calendar->seasonOf($week);
        $thisSeason = $this->calendar->absoluteWeek($season, $maniaWeek);

        return $thisSeason > $week ? $thisSeason : $this->calendar->absoluteWeek($season + 1, $maniaWeek);
    }
}
