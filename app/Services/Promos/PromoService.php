<?php

namespace App\Services\Promos;

use App\Enums\Brand;
use App\Enums\LedgerCategory;
use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\PromoTypeKey;
use App\Enums\ShowStatus;
use App\Exceptions\GameRuleException;
use App\Models\PromoSlot;
use App\Models\PromoType;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Finance\LedgerService;
use App\Services\News\NewsRecorder;
use App\Services\Rivalries\RivalryService;
use App\Support\Rng\Rng;

/**
 * The promo system (§5): two slots per weekly show, each rolled against its
 * success rate with the save's seeded RNG. The cost is paid either way.
 *
 * Popularity and rating effects are deferred, so those promos record their
 * outcome without applying a formula.
 */
class PromoService
{
    public function __construct(
        private LedgerService $ledger,
        private RivalryService $rivalries,
        private NewsRecorder $news,
    ) {}

    public function book(Show $show, int $position, PromoType $type, ?int $targetId = null, ?int $targetBId = null): PromoSlot
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

        $week = $show->week_number;
        $target = $targetId === null ? null : $this->rosterWrestler($show, $brand, $targetId);
        $targetB = $targetBId === null ? null : $this->rosterWrestler($show, $brand, $targetBId);

        if ($type->needs_wrestler && $target === null) {
            throw new GameRuleException("{$type->name} needs a wrestler.");
        }

        if ($type->needs_rival_pair && ($target === null || $targetB === null || $target->id === $targetB->id)) {
            throw new GameRuleException("{$type->name} needs two different wrestlers.");
        }

        if ($type->key === PromoTypeKey::NewSuperstar) {
            $window = (int) config('gm.promos.new_superstar_window_weeks');

            if ($target?->joined_brand_week === null || $target->joined_brand_week < $week - $window) {
                throw new GameRuleException('New Superstar only works on a freshly signed or traded-in wrestler.');
            }
        }

        if ($type->key === PromoTypeKey::ContinueRivalry && $this->rivalries->between($show->game, (int) $targetId, (int) $targetBId) === null) {
            throw new GameRuleException('Continue Rivalry needs an existing rivalry between those two.');
        }

        $slot = PromoSlot::firstOrCreate(['show_id' => $show->id, 'position' => $position]);
        $slot->update([
            'promo_type_id' => $type->id,
            'target_wrestler_id' => $target?->id,
            'target_wrestler_b_id' => $targetB?->id,
            'cost_or_profit' => null,
            'succeeded' => null,
            'backfired' => false,
            'outcome_note' => null,
        ]);

        return $slot;
    }

    public function clear(Show $show, int $position): void
    {
        if ($show->status !== ShowStatus::Booking) {
            throw new GameRuleException("{$show->name} has already aired.");
        }

        PromoSlot::where('show_id', $show->id)->where('position', $position)->update([
            'promo_type_id' => null,
            'target_wrestler_id' => null,
            'target_wrestler_b_id' => null,
        ]);
    }

    /**
     * Resolve a show's promos when it airs.
     */
    public function resolve(Show $show, Rng $rng): void
    {
        $brand = $show->brand;

        if ($brand === null) {
            return;
        }

        $slots = PromoSlot::with(['promoType', 'target.wrestler', 'targetB.wrestler'])
            ->where('show_id', $show->id)
            ->whereNotNull('promo_type_id')
            ->orderBy('position')
            ->get();

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
        $rival = $brand === Brand::Raw ? Brand::Smackdown : Brand::Raw;
        $amount = $type->key === PromoTypeKey::MerchandiseSponsorship ? $this->merchandiseAmount($show, $brand) : $type->base_amount;
        $backfired = $type->key === PromoTypeKey::SlanderPromotion && $this->slanderOverused($show, $brand);
        $succeeded = ! $backfired && $rng->chance($type->successRate());

        // The cost is paid whether or not the promo works.
        if ($amount < 0) {
            $this->ledger->post($save, $brand, LedgerCategory::PromoCost, $amount, "{$type->name} promo", $week, $slot);
        }

        $note = $succeeded ? 'Success' : ($backfired ? 'Backfired (used twice in 4 weeks)' : 'Failed');

        if ($succeeded) {
            $note = match ($type->key) {
                PromoTypeKey::Advertising => $this->advertising($show, $brand, $amount, $slot),
                PromoTypeKey::MerchandiseSponsorship => $this->income($show, $brand, $amount, $slot, $type->name),
                PromoTypeKey::StartRivalry => $this->startRivalry($show, $slot),
                PromoTypeKey::ContinueRivalry => $this->continueRivalry($show, $slot),
                PromoTypeKey::NewSuperstar => $this->debut($show, $brand, $slot),
                default => 'Success (popularity and rating effects are deferred)',
            };
        }

        $slot->update([
            'cost_or_profit' => $amount,
            'succeeded' => $succeeded,
            'backfired' => $backfired,
            'outcome_note' => $note,
        ]);

        // Rival-targeting promos always make the neutral headline (§5, §19).
        if ($type->key === PromoTypeKey::SlanderPromotion) {
            $this->news->record($save, NewsEventType::SlanderResult, NewsTier::Headline, NewsBrand::Both, [
                'brand' => $brand->label(),
                'rival_brand' => $rival->label(),
                'outcome' => $backfired ? 'backfires' : ($succeeded ? 'succeeds' : 'fails'),
            ], [], priority: 75, week: $week);
        }

        if ($type->key === PromoTypeKey::InterferencePromotion) {
            $this->news->record($save, NewsEventType::InterferencePromoResult, NewsTier::Headline, NewsBrand::Both, [
                'brand' => $brand->label(),
                'rival_brand' => $rival->label(),
                'outcome' => $succeeded ? 'disrupts' : 'fails to disrupt',
            ], [], priority: 75, week: $week);
        }
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

            $this->news->record($show->game, NewsEventType::Debut, NewsTier::Story, $brand, [
                'wrestler' => $wrestler->name(),
                'brand' => $brand->label(),
            ], ['wrestler_ids' => [$wrestler->id]], priority: 30, week: $show->week_number);
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
