<?php

namespace App\Services\Ai;

use App\Enums\Brand;
use App\Enums\PromoTypeKey;
use App\Enums\ShowStatus;
use App\Models\PromoSlot;
use App\Models\Rivalry;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Rivalries\RivalryService;
use Illuminate\Support\Collection;

/**
 * How many rivalries the rival GM keeps going (house rule, `gm.ai.rivalries`):
 * at most 7 on its brand. Below 7 it waits 2 weeks after the last one
 * ended (none ended yet counts as waited), then starts one a week through
 * Start a Rivalry, so a season starts from 0 (house rule, 2026-11-11: no
 * forced reset). Rivalries are always same-gender.
 */
class AiRivalryPlanner
{
    public function __construct(
        private RivalryService $rivalries,
    ) {}

    /**
     * Active rivalries with both wrestlers on the brand.
     *
     * @return Collection<int, Rivalry>
     */
    public function brandRivalries(Show $show, Brand $brand): Collection
    {
        $ids = $this->brandIds($show, $brand);

        return $this->rivalries->active($show->game)
            ->filter(fn (Rivalry $r): bool => in_array($r->wrestler_a_id, $ids, true) && in_array($r->wrestler_b_id, $ids, true))
            ->values()
            ->toBase();
    }

    /**
     * This card's rivalry plan.
     *
     * @param  bool  $promos  whether Start a Rivalry promos can run on this card (not a PPV, not low on cash)
     * @return array{matches: list<array{0: int, 1: int}>, promo_starts: int}
     */
    public function plan(Show $show, Brand $brand, bool $promos): array
    {
        $rng = $show->game->rng("ai.rivalries.{$show->streamKey()}", $show->week_number);
        $active = $this->brandRivalries($show, $brand);
        $chance = (float) config('gm.ai.rivalries.rivalry_match_chance');

        $matches = array_values($active
            ->filter(fn (Rivalry $r): bool => $r->wrestlerA->wrestler->isDiva() === $r->wrestlerB->wrestler->isDiva())
            ->filter(fn (): bool => $rng->chance($chance))
            ->map(fn (Rivalry $r): array => [(int) $r->wrestler_a_id, (int) $r->wrestler_b_id])
            ->all());

        $promoSlots = $promos ? $show->promoSlotCount() : 0;

        $wantsOne = $active->count() < (int) config('gm.ai.rivalries.max_per_brand')
            && $this->waited($show, $brand)
            && ! $this->startedThisWeek($show, $brand);

        return ['matches' => $matches, 'promo_starts' => $wantsOne && $promoSlots > 0 ? 1 : 0];
    }

    /**
     * Two weeks since the brand last dropped below the cap (its latest
     * ended rivalry). None ended yet counts as waited.
     */
    private function waited(Show $show, Brand $brand): bool
    {
        $ids = $this->brandIds($show, $brand);
        $lastEnded = Rivalry::where('save_id', $show->save_id)
            ->whereNotNull('ended_week')
            ->whereIn('wrestler_a_id', $ids)
            ->whereIn('wrestler_b_id', $ids)
            ->max('ended_week');

        return $lastEnded === null || $show->week_number - (int) $lastEnded >= (int) config('gm.ai.rivalries.refill_wait_weeks');
    }

    /**
     * One new rivalry a week: a rivalry already started this week, or a
     * Start a Rivalry promo already on one of this week's cards, is it.
     */
    private function startedThisWeek(Show $show, Brand $brand): bool
    {
        $ids = $this->brandIds($show, $brand);

        $started = Rivalry::where('save_id', $show->save_id)
            ->where('started_week', $show->week_number)
            ->where(fn ($q) => $q->whereIn('wrestler_a_id', $ids)->orWhereIn('wrestler_b_id', $ids))
            ->exists();

        $promo = PromoSlot::whereHas('show', fn ($q) => $q->where('save_id', $show->save_id)->where('week_number', $show->week_number)->where('brand', $brand)->where('status', '!=', ShowStatus::Aired))
            ->whereHas('promoType', fn ($q) => $q->where('key', PromoTypeKey::StartRivalry))
            ->exists();

        return $started || $promo;
    }

    /**
     * @return list<int>
     */
    private function brandIds(Show $show, Brand $brand): array
    {
        return array_values(array_map('intval', SaveWrestler::where('save_id', $show->save_id)->where('brand', $brand)->pluck('id')->all()));
    }
}
