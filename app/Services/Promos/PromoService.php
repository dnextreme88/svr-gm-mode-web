<?php

namespace App\Services\Promos;

use App\Enums\Brand;
use App\Enums\LedgerCategory;
use App\Enums\PromoAlignment;
use App\Enums\PromoTypeKey;
use App\Enums\RosterType;
use App\Enums\ShowStatus;
use App\Exceptions\GameRuleException;
use App\Models\MatchSlot;
use App\Models\PromoSlot;
use App\Models\PromoType;
use App\Models\Rivalry;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Fatigue\FatigueZones;
use App\Services\Finance\LedgerService;
use App\Services\Injuries\InjuryService;
use App\Services\Injuries\InjuryStatus;
use App\Services\Mail\EmailService;
use App\Services\Rivalries\RivalryService;
use App\Support\Rng\Rng;

/**
 * The promo system (§5): two slots per weekly show, each rolled against its
 * success rate with the save's seeded RNG. The cost is paid either way.
 * Each promo airs before a chosen match (Before Main Event by default), and
 * promos with a fatigue cost tire and can injure their wrestlers right there.
 *
 * Popularity and rating effects are deferred, so those promos record their
 * outcome without applying a formula.
 */
class PromoService
{
    /**
     * Ends a crashed Interview Space's outcome note when the attack started
     * a rivalry (the story mentions it).
     */
    public const CRASH_RIVALRY_NOTE = 'rivalry started';

    public function __construct(
        private LedgerService $ledger,
        private RivalryService $rivalries,
        private PromoEligibility $eligibility,
        private InjuryService $injuries,
        private EmailService $emails,
    ) {}

    public function book(Show $show, int $position, PromoType $type, ?int $targetId = null, ?int $targetBId = null, ?int $championshipId = null, ?int $beforePosition = null, ?PromoAlignment $alignment = null): PromoSlot
    {
        $brand = $show->brand ?? throw new GameRuleException('Promos run on brand shows.');

        if ($show->status !== ShowStatus::Booking) {
            throw new GameRuleException("{$show->name} has already aired.");
        }

        if ($show->promoSlotCount() === 0) {
            throw new GameRuleException('Promos aren’t available on PPV cards.');
        }

        if ($position < 1 || $position > $show->promoSlotCount()) {
            throw new GameRuleException("{$show->name} has {$show->promoSlotCount()} promo slots.");
        }

        // House rule: two promos can't air back to back, so each spot before
        // a match holds one promo. Without a pick, the promo takes the main
        // event's spot, or the latest one still free.
        $taken = $this->takenSpots($show, $position);
        $beforePosition ??= max(array_diff(range(1, $show->matchSlotCount()), $taken) ?: [$show->matchSlotCount()]);

        if ($beforePosition < 1 || $beforePosition > $show->matchSlotCount()) {
            throw new GameRuleException('A promo has to air before one of the card’s matches.');
        }

        if (in_array($beforePosition, $taken, true)) {
            $spot = $beforePosition === $show->matchSlotCount() ? 'the main event' : "match {$beforePosition}";

            throw new GameRuleException("Another promo already airs before {$spot}. Two promos can't air back to back.");
        }

        $target = $targetId === null ? null : $this->rosterWrestler($show, $brand, $targetId);
        $targetB = $targetBId === null ? null : $this->rosterWrestler($show, $brand, $targetBId);
        $rules = $this->eligibility->forPromo($show, $brand, $type);

        if (! $rules['available']) {
            throw new GameRuleException("{$type->name}: {$rules['reason']}");
        }

        if ($type->needs_wrestler && $target === null) {
            throw new GameRuleException("{$type->name} needs a superstar.");
        }

        if ($type->needsPair() && ($target === null || $targetB === null || $target->id === $targetB->id)) {
            throw new GameRuleException("{$type->name} needs two different superstars.");
        }

        foreach (array_filter([$target, $targetB]) as $wrestler) {
            if (! in_array($wrestler->id, $rules['wrestler_ids'], true)) {
                throw new GameRuleException("{$wrestler->name()} can’t take part in {$type->name}. Needs: {$type->requirement}");
            }

            // House rule: a wrestler gets one of each promo per card.
            $twin = PromoSlot::where('show_id', $show->id)
                ->where('position', '!=', $position)
                ->where('promo_type_id', $type->id)
                ->get()
                ->first(fn (PromoSlot $s): bool => in_array($wrestler->id, $s->targetIds(), true));

            if ($twin !== null) {
                throw new GameRuleException("{$wrestler->name()} is already in Promo {$twin->position}'s {$type->name}.");
            }
        }

        if (in_array($type->key, [PromoTypeKey::ContinueRivalry, PromoTypeKey::CallOut], true) && $this->rivalries->between($show->game, (int) $targetId, (int) $targetBId) === null) {
            throw new GameRuleException("{$type->name} needs an existing rivalry between those two.");
        }

        // Call-out (house rule, 2026-11-15): the first target does the
        // talking, as a face or a heel. The rival GM and Simulate to here
        // leave it to a seeded coin flip.
        if ($type->key === PromoTypeKey::CallOut) {
            $alignment ??= $show->game->rng("promo.alignment.{$show->streamKey()}.{$position}", $show->week_number)->pick(PromoAlignment::cases());
        } else {
            $alignment = null;
        }

        if ($type->needs_championship && ($championshipId === null || ! in_array($championshipId, $rules['championship_ids'], true))) {
            throw new GameRuleException('Title Match Hype needs a championship with a saved title match on this card.');
        }

        $slot = PromoSlot::firstOrCreate(['show_id' => $show->id, 'position' => $position]);
        $slot->update([
            'promo_type_id' => $type->id,
            'before_position' => $beforePosition,
            'target_wrestler_id' => $target?->id,
            'target_wrestler_b_id' => $targetB?->id,
            'target_championship_id' => $type->needs_championship ? $championshipId : null,
            'alignment' => $alignment,
            'cost_or_profit' => null,
            'succeeded' => null,
            'backfired' => false,
            'outcome_note' => null,
        ]);

        return $slot;
    }

    /**
     * The spots (the match a promo airs before) the show's other promos hold.
     *
     * @return list<int>
     */
    public function takenSpots(Show $show, int $exceptPosition): array
    {
        return array_values(PromoSlot::where('show_id', $show->id)
            ->where('position', '!=', $exceptPosition)
            ->whereNotNull('promo_type_id')
            ->get()
            ->map(fn (PromoSlot $s): int => $s->before_position ?? $show->matchSlotCount())
            ->all());
    }

    public function clear(Show $show, int $position): void
    {
        if ($show->status !== ShowStatus::Booking) {
            throw new GameRuleException("{$show->name} has already aired.");
        }

        PromoSlot::where('show_id', $show->id)->where('position', $position)->update([
            'promo_type_id' => null,
            'before_position' => null,
            'target_wrestler_id' => null,
            'target_wrestler_b_id' => null,
            'target_championship_id' => null,
        ]);
    }

    /**
     * Air the promos placed before match slot $position. Promos with no
     * placement air before the main event.
     */
    public function resolveBefore(Show $show, int $position, Rng $rng): void
    {
        $this->resolveWhere($show, $rng, $position);
    }

    /**
     * Air every promo that hasn't aired yet (anything left once the matches are done).
     */
    public function resolve(Show $show, Rng $rng): void
    {
        $this->resolveWhere($show, $rng, null);
    }

    private function resolveWhere(Show $show, Rng $rng, ?int $position): void
    {
        $brand = $show->brand;

        if ($brand === null) {
            return;
        }

        $mainEvent = $show->matchSlotCount();
        $slots = PromoSlot::with(['promoType', 'target.wrestler', 'targetB.wrestler', 'targetChampionship'])
            ->where('show_id', $show->id)
            ->whereNotNull('promo_type_id')
            ->whereNull('succeeded')
            ->orderBy('position')
            ->get()
            ->filter(fn (PromoSlot $s): bool => $position === null || ($s->before_position ?? $mainEvent) === $position);

        foreach ($slots as $slot) {
            $this->resolveOne($show, $brand, $slot, $rng);
        }
    }

    /**
     * Merchandise Sponsorship's gamble for this airing (house rule,
     * 2026-11-15): a success chance and, separately, a payout, each drawn
     * with equal odds from `gm.promos.merchandise_chances` / `_payouts`.
     *
     * @return array{amount: int, chance: float}
     */
    public function merchandiseTier(Rng $rng): array
    {
        $chance = (float) $rng->pick(array_values((array) config('gm.promos.merchandise_chances')));
        $amount = (int) $rng->pick(array_values((array) config('gm.promos.merchandise_payouts')));

        return ['amount' => $amount, 'chance' => $chance];
    }

    /**
     * The promos that backfire when overused (house rule).
     */
    public const BACKFIRING = [PromoTypeKey::SlanderPromotion, PromoTypeKey::InterferencePromotion];

    /**
     * House rule (2026-10-31): Slander and Interference backfire automatically
     * once the brand has already aired `max_uses` of that promo within the
     * last `window_weeks` weeks, this one's week included. Each use counts,
     * two on one card included; the earlier ones roll as usual.
     */
    public function overused(Show $show, Brand $brand, PromoSlot $slot): bool
    {
        $key = $slot->promoType?->key;

        if (! in_array($key, self::BACKFIRING, true)) {
            return false;
        }

        $window = (int) config('gm.promos.overuse.window_weeks');

        $earlier = PromoSlot::whereHas('show', fn ($q) => $q
            ->where('save_id', $show->save_id)
            ->where('brand', $brand)
            ->whereBetween('week_number', [$show->week_number - $window + 1, $show->week_number]))
            ->whereHas('promoType', fn ($q) => $q->where('key', $key))
            ->whereNotNull('succeeded')
            ->whereKeyNot($slot->id)
            ->count();

        return $earlier >= (int) config('gm.promos.overuse.max_uses');
    }

    private function resolveOne(Show $show, Brand $brand, PromoSlot $slot, Rng $rng): void
    {
        $type = $slot->promoType;

        if ($type === null) {
            return;
        }

        $save = $show->game;
        $week = $show->week_number;
        $tier = $type->key === PromoTypeKey::MerchandiseSponsorship ? $this->merchandiseTier($rng) : null;
        $amount = $tier['amount'] ?? $type->base_amount;
        $backfired = $this->overused($show, $brand, $slot);
        $succeeded = ! $backfired && $rng->chance($tier['chance'] ?? $type->successRate());

        // The cost is paid whether or not the promo works.
        if ($amount < 0) {
            // House rule (2026-11-17): a promo's money is settled once the night's news is in.
            $this->ledger->post($save, $brand, LedgerCategory::PromoCost, $amount, "{$type->name} promo", $week, $slot, $show->id, settled: false);
        }

        $note = $succeeded ? 'Success' : ($backfired ? 'Backfired (overused)' : 'Failed');

        $this->tire($show, $type, $slot, $rng);

        // An Inactive Superstar promo only works on someone who isn't booked on the card.
        if ($type->key === PromoTypeKey::InactiveSuperstar && $slot->target_wrestler_id !== null && $this->bookedOn($show, $slot->target_wrestler_id)) {
            $succeeded = false;
            $note = 'Booked on the card';
        }

        // ASSUMPTION: a hype for a title match that's no longer on the card fizzles.
        if ($type->needs_championship && ! $this->titleMatchOnCard($show, $slot->target_championship_id)) {
            $succeeded = false;
            $note = 'No title match on the card';
        }

        if ($succeeded) {
            $note = match ($type->key) {
                PromoTypeKey::Advertising => $this->advertising($show, $brand, $amount, $slot),
                PromoTypeKey::MerchandiseSponsorship => $this->income($show, $brand, $amount, $slot, $type->name),
                PromoTypeKey::StartRivalry => $this->startRivalry($show, $slot),
                PromoTypeKey::ContinueRivalry => $this->continueRivalry($show, $slot),
                PromoTypeKey::DivaBikiniContest => $this->bikiniContest($show, $slot),
                PromoTypeKey::CallOut => $this->callOut($show, $slot),
                PromoTypeKey::NewSuperstar => $this->debut($show, $brand, $slot),
                PromoTypeKey::InactiveSuperstar => 'Rested: recovers '.(int) config('gm.promos.recovery_bars.inactive_superstar').' extra fatigue bars',
                default => 'Success (popularity and rating effects are deferred)',
            };
        }

        // House rule (2026-11-20): the treatment works either way, just less
        // when it fails.
        if ($type->key === PromoTypeKey::SpeedUpInjury && $slot->target !== null) {
            $note = $this->injuries->speedUp($slot->target, $succeeded, $week) ?? 'No injury to treat';
        }

        // House rule: a failed Interview Space for someone not booked on the
        // card gets crashed by another superstar (see `crashInterview`).
        if ($type->key === PromoTypeKey::InterviewSpace && ! $succeeded && $slot->target !== null && ! $this->bookedOn($show, $slot->target->id)) {
            $note = $this->crashInterview($show, $brand, $slot, $rng) ?? $note;
        }

        // House rule (2026-11-15): Interference pays off when it works and
        // costs extra when it fails or backfires, on top of its cost.
        if ($type->key === PromoTypeKey::InterferencePromotion) {
            $result = (int) config('gm.promos.interference_payout.'.($succeeded ? 'success' : 'failure'));

            if ($result !== 0) {
                $this->ledger->post($save, $brand, LedgerCategory::InterferenceResult, $result, $succeeded ? 'Interference paid off' : 'Interference damage bill', $week, $slot, $show->id, settled: false);
            }
        }

        $slot->update([
            'cost_or_profit' => $amount,
            'succeeded' => $succeeded,
            'backfired' => $backfired,
            'outcome_note' => $note,
        ]);

        // The superstar who called out their rival writes to the GM.
        if ($type->key === PromoTypeKey::CallOut && $slot->target !== null) {
            $this->emails->callOut($save, $brand, $slot->target, $slot->targetB, $slot->alignment, $succeeded, $week);
        }

        // The player's Slander gets the rival GM's reaction; the rival's
        // Slander gets the player a heads-up from JR / Tazz.
        if ($type->key === PromoTypeKey::SlanderPromotion) {
            $save->isHuman($brand)
                ? $this->emails->slanderResult($save, $brand, $succeeded, $week)
                : $this->emails->rivalSlander($save, $save->player_brand, $brand, $succeeded, $week);
        }

        // The player's Interference gets the rival GM's reaction too.
        if ($type->key === PromoTypeKey::InterferencePromotion && $save->isHuman($brand)) {
            $this->emails->interferenceResult($save, $brand, $succeeded, $week);
        }

        // The news story is written once the show has aired, so it can tell
        // how the promo played into the night (PromoStoryWriter).
    }

    /**
     * Promo fatigue (house rule): every wrestler in the promo (both Divas in a
     * Bikini Contest, both rivals in Continue Rivalry) takes it, success or
     * fail, and rolls injury on the fatigue it leaves them on. Continue
     * Rivalry (house rule, 2026-11-20, `gm.promos.injury_exempt`) still tires
     * them but never injures them.
     */
    private function tire(Show $show, PromoType $type, PromoSlot $slot, Rng $rng): void
    {
        if ($type->fatigue_bars <= 0) {
            return;
        }

        foreach ($slot->targetIds() as $id) {
            $wrestler = SaveWrestler::find($id);

            if ($wrestler === null) {
                continue;
            }

            $goingIn = $wrestler->fatigue;
            $wrestler->update(['fatigue' => FatigueZones::cap($goingIn + $type->fatigue_bars * (int) config('gm.fatigue.bar_size'))]);

            if (in_array($type->key->value, (array) config('gm.promos.injury_exempt', []), true)) {
                continue;
            }

            $this->injuries->rollForPromo($wrestler->refresh(), $goingIn, $type->fatigue_bars, $show->week_number, $rng);
        }
    }

    /**
     * Interview Space gone wrong (house rule): a random healthy male
     * superstar from the same brand crashes the interview and decks the
     * wrestler (stored as the promo's second target). If neither is in a
     * rivalry yet, that starts one.
     */
    private function crashInterview(Show $show, Brand $brand, PromoSlot $slot, Rng $rng): ?string
    {
        $target = $slot->target;

        if ($target === null) {
            return null;
        }

        $injured = app(InjuryStatus::class)->seriouslyInjuredIds($show->save_id, $show->week_number);
        $candidates = SaveWrestler::with('wrestler')
            ->where('save_id', $show->save_id)
            ->where('brand', $brand)
            ->where('id', '!=', $target->id)
            ->orderBy('id')
            ->get()
            ->filter(fn (SaveWrestler $w): bool => $w->wrestler->roster_type !== RosterType::Diva && ! in_array($w->id, $injured, true) && ! $w->isSittingOut($show->week_number))
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        /** @var SaveWrestler $crasher */
        $crasher = $rng->pick($candidates->all());
        $slot->update(['target_wrestler_b_id' => $crasher->id]);

        $inRivalry = $this->rivalries->active($show->game)->flatMap(fn (Rivalry $r): array => [$r->wrestler_a_id, $r->wrestler_b_id])->all();

        if (! in_array($target->id, $inRivalry, true) && ! in_array($crasher->id, $inRivalry, true)) {
            $this->rivalries->start($show->game, $target->id, $crasher->id, $show->week_number);

            return "Crashed by {$crasher->name()}: ".self::CRASH_RIVALRY_NOTE;
        }

        return "Crashed by {$crasher->name()}";
    }

    private function bookedOn(Show $show, int $wrestlerId): bool
    {
        return MatchSlot::where('show_id', $show->id)
            ->whereHas('participants', fn ($q) => $q->where('save_wrestler_id', $wrestlerId))
            ->exists();
    }

    private function titleMatchOnCard(Show $show, ?int $championshipId): bool
    {
        return $championshipId !== null && MatchSlot::with('participants')
            ->where('show_id', $show->id)
            ->where('title_championship_id', $championshipId)
            ->get()
            ->contains(fn (MatchSlot $s): bool => $s->isBooked());
    }

    private function advertising(Show $show, Brand $brand, int $amount, PromoSlot $slot): string
    {
        // House rule (2026-11-15): a flat payout the week it airs.
        $this->ledger->post($show->game, $brand, LedgerCategory::PromoIncome, $amount, 'Advertising payout', $show->week_number, $slot, $show->id, settled: false);

        return 'Paid '.number_format($amount);
    }

    private function income(Show $show, Brand $brand, int $amount, PromoSlot $slot, string $name): string
    {
        $this->ledger->post($show->game, $brand, LedgerCategory::PromoIncome, $amount, $name, $show->week_number, $slot, $show->id, settled: false);

        return 'Paid '.number_format($amount);
    }

    private function startRivalry(Show $show, PromoSlot $slot): string
    {
        $this->rivalries->start($show->game, (int) $slot->target_wrestler_id, (int) $slot->target_wrestler_b_id, $show->week_number);

        return 'Rivalry started';
    }

    private function continueRivalry(Show $show, PromoSlot $slot): string
    {
        $rivalry = $this->rivalries->between($show->game, (int) $slot->target_wrestler_id, (int) $slot->target_wrestler_b_id);

        if ($rivalry !== null) {
            // House rule (2026-11-12): no heat, but the week isn't idle.
            $this->rivalries->hold($rivalry, $show->week_number);
        }

        return 'Heat kept alive';
    }

    /**
     * House rule (2026-11-15): two rival Divas in a Bikini Contest keep their
     * rivalry going, like Continue Rivalry.
     */
    private function bikiniContest(Show $show, PromoSlot $slot): string
    {
        $rivalry = $this->rivalries->between($show->game, (int) $slot->target_wrestler_id, (int) $slot->target_wrestler_b_id);

        if ($rivalry === null || ! $rivalry->active) {
            return 'Success (popularity and rating effects are deferred)';
        }

        $this->rivalries->hold($rivalry, $show->week_number);

        return 'Rivalry heat kept alive';
    }

    /**
     * Call-out (house rule, 2026-11-15): a success heats the rivalry up.
     */
    private function callOut(Show $show, PromoSlot $slot): string
    {
        $rivalry = $this->rivalries->between($show->game, (int) $slot->target_wrestler_id, (int) $slot->target_wrestler_b_id);

        if ($rivalry === null || ! $rivalry->active) {
            return 'Success';
        }

        $this->rivalries->recordCallOut($rivalry, $show->week_number);

        return 'Rivalry heats up (+'.(int) config('gm.rivalries.heat_per_call_out').' heat)';
    }

    private function debut(Show $show, Brand $brand, PromoSlot $slot): string
    {
        $wrestler = $slot->target;

        if ($wrestler !== null && $wrestler->debuted_week === null) {
            $wrestler->update(['debuted_week' => $show->week_number]);
        }

        return 'Debut made';
    }

    private function rosterWrestler(Show $show, Brand $brand, int $id): SaveWrestler
    {
        return SaveWrestler::with('wrestler')->where('save_id', $show->save_id)->where('brand', $brand)->whereKey($id)->first()
            ?? throw new GameRuleException('Promo targets must be on your roster.');
    }
}
