<?php

namespace App\Services\Ai;

use App\Enums\Brand;
use App\Enums\PromoTypeKey;
use App\Enums\ShowStatus;
use App\Models\MatchSlot;
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
 *
 * House rule (2026-11-20): it keeps its rivalries going, booking every one
 * as a 1v1 on each card it can (`rivalry_match_chance`; one it can't pair on
 * its weekly show gets a Continue Rivalry promo, AiPromoPlanner). A rivalry
 * at 60+ heat with 4+ matches together is blown off at its next PPV: a 60%
 * roll books the rivals 1v1 on that PPV, else on its next weekly show after
 * it (plans in `save_brands.ai_plans.blowoffs`). The match is kept on the
 * rivalry (`blowoff_slot_id`), which concludes once it airs.
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
     * @return array{matches: list<array{0: int, 1: int}>, promo_starts: int, blowoffs: list<int>}
     */
    public function plan(Show $show, Brand $brand, bool $promos): array
    {
        $rng = $show->game->rng("ai.rivalries.{$show->streamKey()}", $show->week_number);
        $active = $this->brandRivalries($show, $brand)
            ->filter(fn (Rivalry $r): bool => $r->wrestlerA->wrestler->isDiva() === $r->wrestlerB->wrestler->isDiva())
            ->values();
        $chance = (float) config('gm.ai.rivalries.rivalry_match_chance');
        [$now, $later] = $this->blowoffs($show, $brand, $active);

        // A blow-off due on this card comes first; one booked already, or
        // waiting for the weekly show after the PPV, isn't booked here.
        $pair = fn (Rivalry $r): array => [(int) $r->wrestler_a_id, (int) $r->wrestler_b_id];
        $blowoffs = $active->filter(fn (Rivalry $r): bool => in_array($r->id, $now, true));
        $others = $active
            ->reject(fn (Rivalry $r): bool => in_array($r->id, [...$now, ...$later], true) || $r->blowoff_slot_id !== null)
            ->filter(fn (): bool => $rng->chance($chance));

        $matches = [...$blowoffs->map($pair)->values()->all(), ...$others->map($pair)->values()->all()];

        $promoSlots = $promos ? $show->promoSlotCount() : 0;

        $wantsOne = $this->brandRivalries($show, $brand)->count() < (int) config('gm.ai.rivalries.max_per_brand')
            && $this->waited($show, $brand)
            && ! $this->startedThisWeek($show, $brand);

        return [
            'matches' => $matches,
            'promo_starts' => $wantsOne && $promoSlots > 0 ? 1 : 0,
            'blowoffs' => array_values($blowoffs->pluck('id')->map(fn ($id): int => (int) $id)->all()),
        ];
    }

    /**
     * Which of its rivalries are blown off on this card, and which wait for
     * the weekly show after a PPV (house rule, 2026-11-20). At the first card
     * of a PPV it can book, each due rivalry (60+ heat, 4+ matches together)
     * rolls once: 60% it's the PPV, else the next weekly show after it. A
     * planned blow-off that couldn't be booked tries again on the next card.
     *
     * @param  Collection<int, Rivalry>  $active
     * @return array{0: list<int>, 1: list<int>}
     */
    private function blowoffs(Show $show, Brand $brand, Collection $active): array
    {
        $row = $show->game->brand($brand);
        $plans = $row->ai_plans ?? [];
        $ids = array_map('intval', $active->pluck('id')->all());
        // A rivalry that ended some other way drops its plan.
        $blowoffs = array_filter((array) ($plans['blowoffs'] ?? []), fn ($plan, $id): bool => in_array((int) $id, $ids, true), ARRAY_FILTER_USE_BOTH);

        if ($show->isPpv()) {
            foreach ($active as $rivalry) {
                if (isset($blowoffs[$rivalry->id]) || ! $this->due($rivalry, $show->week_number)) {
                    continue;
                }

                $rng = $show->game->rng("ai.blowoff.{$brand->value}.{$rivalry->wrestler_a_id}-{$rivalry->wrestler_b_id}", $show->week_number);
                $blowoffs[$rivalry->id] = [
                    'at' => $rng->chance((float) config('gm.ai.rivalries.blowoff_ppv_chance')) ? 'ppv' : 'weekly',
                    'ppv_week' => $show->week_number,
                ];
            }
        }

        $now = [];
        $later = [];

        foreach ($blowoffs as $id => $plan) {
            $rivalry = $active->firstWhere('id', (int) $id);

            if ($rivalry === null || $rivalry->blowoff_slot_id !== null) {
                continue;
            }

            $ppvWeek = (int) $plan['ppv_week'];
            $onThis = $show->isPpv()
                ? $plan['at'] === 'ppv'
                : ($plan['at'] === 'weekly' ? $show->week_number >= $ppvWeek : $show->week_number > $ppvWeek);

            if ($onThis) {
                $now[] = (int) $id;
            } elseif ($show->isPpv() && $plan['at'] === 'weekly') {
                $later[] = (int) $id;
            }
        }

        if (($plans['blowoffs'] ?? []) !== $blowoffs) {
            $plans['blowoffs'] = $blowoffs;
            $row->update(['ai_plans' => $plans]);
        }

        return [$now, $later];
    }

    /** Hot enough, and met often enough, for its blow-off. */
    private function due(Rivalry $rivalry, int $week): bool
    {
        return $rivalry->heat >= (int) config('gm.ai.rivalries.blowoff_min_heat')
            && count($this->rivalries->matchSlotIds($rivalry, $week)) >= (int) config('gm.ai.rivalries.blowoff_min_matches');
    }

    /**
     * Mark each blow-off rivalry's 1v1 on this card (house rule,
     * 2026-11-20), so it concludes once that match airs.
     *
     * @param  list<int>  $rivalryIds
     */
    public function markBlowoffs(Show $show, array $rivalryIds): void
    {
        foreach (Rivalry::whereIn('id', $rivalryIds)->get() as $rivalry) {
            $slot = MatchSlot::with('participants')->where('show_id', $show->id)->get()
                ->first(function (MatchSlot $slot) use ($rivalry): bool {
                    $ids = $slot->competitorIds();

                    return count($ids) === 2
                        && in_array($rivalry->wrestler_a_id, $ids, true)
                        && in_array($rivalry->wrestler_b_id, $ids, true);
                });

            if ($slot !== null) {
                $rivalry->update(['blowoff_slot_id' => $slot->id]);
            }
        }
    }

    /**
     * Its rivalries whose rivals share no match on this card (house rule,
     * 2026-11-20), hottest first: each gets a Continue Rivalry promo if a
     * slot is free.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function unpaired(Show $show, Brand $brand): array
    {
        $slots = MatchSlot::with('participants')->where('show_id', $show->id)->get();
        $roles = (array) config('gm.rivalries.meeting_roles');

        return array_values($this->brandRivalries($show, $brand)
            ->filter(fn (Rivalry $r): bool => $r->blowoff_slot_id === null)
            ->reject(fn (Rivalry $r): bool => $slots->contains(function (MatchSlot $slot) use ($r, $roles): bool {
                $ids = $slot->participants->filter(fn ($p): bool => in_array($p->role->value, $roles, true))->pluck('save_wrestler_id')->map(fn ($id): int => (int) $id)->all();

                return in_array($r->wrestler_a_id, $ids, true) && in_array($r->wrestler_b_id, $ids, true);
            }))
            ->sortByDesc('heat')
            ->map(fn (Rivalry $r): array => [(int) $r->wrestler_a_id, (int) $r->wrestler_b_id])
            ->all());
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
