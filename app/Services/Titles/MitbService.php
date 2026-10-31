<?php

namespace App\Services\Titles;

use App\Enums\Brand;
use App\Enums\FixedSlotKind;
use App\Enums\MitbCashInTiming;
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
use App\Models\PromoSlot;
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
    public function cashIn(Show $show, MoneyInTheBank $briefcase, ?int $position = null): MatchSlot
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

        // House rule: the GM picks where the cash-in goes (default: after the
        // main event); the matches from there on move down one spot.
        $last = (int) MatchSlot::where('show_id', $show->id)->max('position');
        $position = max(1, min($position ?? $last + 1, $last + 1));
        $this->makeRoom($show->id, $position);
        $slot = MatchSlot::create([
            'show_id' => $show->id,
            'position' => $position,
            'match_type_id' => MatchType::where('family', 'single')->where('variant', 'No Manager')->value('id'),
            'title_championship_id' => $championship->id,
            'fixed_kind' => FixedSlotKind::MitbCashIn,
            'mitb_cash_in' => MitbCashInTiming::Before,
            'owner_brand' => $briefcase->brand,
            'cost' => 0,
        ]);

        MatchParticipant::create(['match_slot_id' => $slot->id, 'save_wrestler_id' => $reign->holderIds()[0], 'side' => 0, 'role' => ParticipantRole::Competitor]);
        MatchParticipant::create(['match_slot_id' => $slot->id, 'save_wrestler_id' => $holder->id, 'side' => 1, 'role' => ParticipantRole::Competitor]);

        $briefcase->update(['cash_in_match_id' => $slot->id]);

        return $slot;
    }

    /**
     * The briefcase that could be cashed in during this world title match
     * (house rule, 2026-10-31): the brand's held briefcase, its holder not
     * in the match and fit to wrestle, and a variant of the match type with
     * one more singles side to grow into ("Hardcore Single" → "Hardcore
     * Triple Threat", a triple threat → a Fatal 4-Way). Only world title
     * matches for now; other title matches are deferred.
     */
    public function duringOption(Save $save, MatchSlot $slot, int $week): ?MoneyInTheBank
    {
        $briefcase = $this->briefcaseFor($save, $slot, $week);

        return $briefcase !== null && $slot->mitb_cash_in === null && $this->grownType($slot) !== null ? $briefcase : null;
    }

    /**
     * The briefcase that could be cashed in right after this aired world
     * title match (house rule, 2026-10-31): a new title match follows it.
     */
    public function afterOption(Save $save, MatchSlot $slot, int $week): ?MoneyInTheBank
    {
        if ($slot->result === null || $slot->mitb_cash_in !== null
            || MatchSlot::where('show_id', $slot->show_id)->where('fixed_kind', FixedSlotKind::MitbCashIn)->exists()) {
            return null;
        }

        return $this->briefcaseFor($save, $slot, $week);
    }

    /**
     * The holder runs in during the match: it grows into the next bigger
     * variant (a singles match becomes a triple threat, a triple threat a
     * Fatal 4-Way) with the holder as the new side. A w/Manager match drops
     * its managers, since the bigger variants have none.
     */
    public function cashInDuring(Save $save, MatchSlot $slot, int $week): MatchSlot
    {
        $briefcase = $this->duringOption($save, $slot, $week)
            ?? throw new GameRuleException('The briefcase can’t be cashed in on this match.');
        $type = $this->grownType($slot) ?? throw new GameRuleException('This match can’t take another wrestler.');

        $slot->participants()->where('role', ParticipantRole::Manager)->delete();
        MatchParticipant::create([
            'match_slot_id' => $slot->id,
            'save_wrestler_id' => $briefcase->save_wrestler_id,
            'side' => count($slot->sides()),
            'role' => ParticipantRole::Competitor,
        ]);
        $slot->update(['match_type_id' => $type->id, 'mitb_cash_in' => MitbCashInTiming::During]);
        $briefcase->update(['cash_in_match_id' => $slot->id]);

        return $slot->refresh()->load(['participants', 'matchType']);
    }

    /**
     * The holder cashes in right after a world title match: a new title
     * match, holder vs the champion coming out of it, goes in straight after
     * it. Later matches (and the promos before them) move down one spot.
     */
    public function cashInAfter(Save $save, MatchSlot $after, int $week): MatchSlot
    {
        $briefcase = $this->afterOption($save, $after, $week)
            ?? throw new GameRuleException('The briefcase can’t be cashed in after this match.');
        $championship = $this->worldTitle($briefcase->brand);
        $reign = $this->titles->currentReign($save, $championship)
            ?? throw new GameRuleException("The {$championship->name} is vacant; there's no one to cash in on.");
        $position = $after->position + 1;
        $this->makeRoom($after->show_id, $position);

        $slot = MatchSlot::create([
            'show_id' => $after->show_id,
            'position' => $position,
            'match_type_id' => MatchType::where('family', 'single')->where('variant', 'No Manager')->value('id'),
            'title_championship_id' => $championship->id,
            'fixed_kind' => FixedSlotKind::MitbCashIn,
            'mitb_cash_in' => MitbCashInTiming::After,
            'owner_brand' => $briefcase->brand,
            'cost' => 0,
        ]);

        MatchParticipant::create(['match_slot_id' => $slot->id, 'save_wrestler_id' => $reign->holderIds()[0], 'side' => 0, 'role' => ParticipantRole::Competitor]);
        MatchParticipant::create(['match_slot_id' => $slot->id, 'save_wrestler_id' => $briefcase->save_wrestler_id, 'side' => 1, 'role' => ParticipantRole::Competitor]);
        $briefcase->update(['cash_in_match_id' => $slot->id]);

        return $slot;
    }

    /**
     * Move the matches from `$position` on (and the promos before them) down
     * one spot, so a cash-in can go there. A cash-in is the one match that
     * can take a card past its slots: one more than a weekly show's 6 or a
     * PPV's 5 (`gm.card.cash_in_extra_slots`).
     */
    private function makeRoom(int $showId, int $position): void
    {
        $show = Show::findOrFail($showId);
        $slots = MatchSlot::where('show_id', $showId)->count();

        if ($slots >= $show->matchSlotCount() + (int) config('gm.card.cash_in_extra_slots')) {
            throw new GameRuleException('This card has no room for another match.');
        }

        MatchSlot::where('show_id', $showId)->where('position', '>=', $position)
            ->orderByDesc('position')
            ->get()
            ->each(fn (MatchSlot $s) => $s->update(['position' => $s->position + 1]));
        PromoSlot::where('show_id', $showId)->where('before_position', '>=', $position)
            ->increment('before_position');
    }

    /**
     * The player's held briefcase for a world title match on its brand,
     * with its holder outside the match and fit to wrestle.
     */
    private function briefcaseFor(Save $save, MatchSlot $slot, int $week): ?MoneyInTheBank
    {
        $briefcase = $this->held($save)->first(fn (MoneyInTheBank $b): bool => $b->brand === $save->player_brand);

        if ($briefcase === null || $slot->title_championship_id !== $this->worldTitle($briefcase->brand)->id
            || in_array($briefcase->save_wrestler_id, $slot->competitorIds(), true)) {
            return null;
        }

        $holder = $briefcase->holder;

        return $this->injuries->isSeriouslyInjured($holder, $week) || $holder->isSittingOut($week) ? null : $briefcase;
    }

    /**
     * The same family's variant with one more singles side, if it has one.
     */
    private function grownType(MatchSlot $slot): ?MatchType
    {
        $type = $slot->matchType;

        if ($type === null || array_unique($type->sides) !== [1]) {
            return null;
        }

        $want = [...$type->sides, 1];

        return MatchType::where('family', $type->family)
            ->where('gm_bookable', true)
            ->where('titles_allowed', true)
            ->orderBy('sort_order')
            ->get()
            ->first(fn (MatchType $t): bool => $t->sides === $want && $t->manager_sides === [] && ! $t->hasSpecialReferee());
    }

    /**
     * Who cashed in on this match, if anyone did.
     */
    public function cashInHolder(MatchSlot $slot): ?int
    {
        $id = MoneyInTheBank::where('cash_in_match_id', $slot->id)->value('save_wrestler_id');

        return $id === null ? null : (int) $id;
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
            if ($wrestleManiaResolved || $briefcase->expires_week <= $week) {
                $briefcase->update(['status' => MitbStatus::Expired, 'resolved_week' => $week, 'resolution_note' => $wrestleManiaResolved ? 'wrestlemania' : 'expired']);

                continue;
            }

            if ($briefcase->expires_week - $week === $warn) {
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
