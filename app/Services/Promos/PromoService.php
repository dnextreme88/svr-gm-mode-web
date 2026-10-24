<?php

namespace App\Services\Promos;

use App\Enums\Brand;
use App\Enums\LedgerCategory;
use App\Enums\PromoTypeKey;
use App\Enums\ShowStatus;
use App\Exceptions\GameRuleException;
use App\Models\MatchSlot;
use App\Models\PromoSlot;
use App\Models\PromoType;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Fatigue\FatigueZones;
use App\Services\Finance\LedgerService;
use App\Services\Injuries\InjuryService;
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
    public function __construct(
        private LedgerService $ledger,
        private RivalryService $rivalries,
        private PromoEligibility $eligibility,
        private InjuryService $injuries,
        private EmailService $emails,
    ) {}

    public function book(Show $show, int $position, PromoType $type, ?int $targetId = null, ?int $targetBId = null, ?int $championshipId = null, ?int $beforePosition = null): PromoSlot
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
            throw new GameRuleException("{$type->name} needs a wrestler.");
        }

        if ($type->needsPair() && ($target === null || $targetB === null || $target->id === $targetB->id)) {
            throw new GameRuleException("{$type->name} needs two different wrestlers.");
        }

        foreach (array_filter([$target, $targetB]) as $wrestler) {
            if (! in_array($wrestler->id, $rules['wrestler_ids'], true)) {
                throw new GameRuleException("{$wrestler->name()} can’t take part in {$type->name}. Needs: {$type->requirement}");
            }
        }

        if ($type->key === PromoTypeKey::ContinueRivalry && $this->rivalries->between($show->game, (int) $targetId, (int) $targetBId) === null) {
            throw new GameRuleException('Continue Rivalry needs an existing rivalry between those two.');
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
     * Merchandise Sponsorship pays more the more consistently it's used (§5).
     * ASSUMPTION: base + step per consecutive week, capped, reset after idle weeks.
     */
    public function merchandiseAmount(Show $show, Brand $brand): int
    {
        $cfg = (array) config('gm.promos.merchandise');
        $streak = 0;
        $idle = 0;

        for ($week = $show->week_number - 1; $week > 0 && $idle < (int) $cfg['reset_after_idle_weeks']; $week--) {
            if ($this->usedIn($show, $brand, PromoTypeKey::MerchandiseSponsorship, $week)) {
                $streak++;
                $idle = 0;
            } else {
                $idle++;
            }
        }

        return min((int) $cfg['cap'], (int) $cfg['base'] + $streak * (int) $cfg['step']);
    }

    /**
     * Slander backfires automatically if used more than once in 4 weeks (§5).
     */
    public function slanderOverused(Show $show, Brand $brand): bool
    {
        $window = (int) config('gm.promos.slander_overuse_window_weeks');

        for ($week = $show->week_number - $window + 1; $week < $show->week_number; $week++) {
            if ($this->usedIn($show, $brand, PromoTypeKey::SlanderPromotion, $week)) {
                return true;
            }
        }

        return false;
    }

    private function resolveOne(Show $show, Brand $brand, PromoSlot $slot, Rng $rng): void
    {
        $type = $slot->promoType;

        if ($type === null) {
            return;
        }

        $save = $show->game;
        $week = $show->week_number;
        $amount = $type->key === PromoTypeKey::MerchandiseSponsorship ? $this->merchandiseAmount($show, $brand) : $type->base_amount;
        $backfired = $type->key === PromoTypeKey::SlanderPromotion && $this->slanderOverused($show, $brand);
        $succeeded = ! $backfired && $rng->chance($type->successRate());

        // The cost is paid whether or not the promo works.
        if ($amount < 0) {
            $this->ledger->post($save, $brand, LedgerCategory::PromoCost, $amount, "{$type->name} promo", $week, $slot);
        }

        $note = $succeeded ? 'Success' : ($backfired ? 'Backfired (used twice in 4 weeks)' : 'Failed');

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
                PromoTypeKey::NewSuperstar => $this->debut($show, $brand, $slot),
                PromoTypeKey::InactiveSuperstar => 'Rested: recovers '.(int) config('gm.promos.recovery_bars.inactive_superstar').' extra fatigue bars',
                default => 'Success (popularity and rating effects are deferred)',
            };
        }

        $slot->update([
            'cost_or_profit' => $amount,
            'succeeded' => $succeeded,
            'backfired' => $backfired,
            'outcome_note' => $note,
        ]);

        if ($type->key === PromoTypeKey::SlanderPromotion) {
            $this->emails->slanderResult($save, $brand, $succeeded, $week);
        }

        // The news story is written once the show has aired, so it can tell
        // how the promo played into the night (PromoStoryWriter).
    }

    /**
     * Promo fatigue (house rule): the wrestlers take it success or fail, and
     * roll injury on the fatigue they had going into the promo.
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
            $this->injuries->rollForPromo($wrestler->refresh(), $goingIn, $type->fatigue_bars, $show->week_number, $rng);
        }
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
        // A guaranteed flat payout next week.
        $this->ledger->post($show->game, $brand, LedgerCategory::PromoIncome, $amount, 'Advertising payout', $show->week_number + 1, $slot);

        return 'Pays out next week';
    }

    private function income(Show $show, Brand $brand, int $amount, PromoSlot $slot, string $name): string
    {
        $this->ledger->post($show->game, $brand, LedgerCategory::PromoIncome, $amount, $name, $show->week_number, $slot);

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
            $this->rivalries->heatUp($rivalry, (int) config('gm.rivalries.heat_per_promo'), $show->week_number);
        }

        return 'Heat kept alive';
    }

    private function debut(Show $show, Brand $brand, PromoSlot $slot): string
    {
        $wrestler = $slot->target;

        if ($wrestler !== null && $wrestler->debuted_week === null) {
            $wrestler->update(['debuted_week' => $show->week_number]);
        }

        return 'Debut made';
    }

    private function usedIn(Show $show, Brand $brand, PromoTypeKey $key, int $week): bool
    {
        return PromoSlot::whereHas('show', fn ($q) => $q->where('save_id', $show->save_id)->where('brand', $brand)->where('week_number', $week))
            ->whereHas('promoType', fn ($q) => $q->where('key', $key))
            ->exists();
    }

    private function rosterWrestler(Show $show, Brand $brand, int $id): SaveWrestler
    {
        return SaveWrestler::with('wrestler')->where('save_id', $show->save_id)->where('brand', $brand)->whereKey($id)->first()
            ?? throw new GameRuleException('Promo targets must be on your roster.');
    }
}
