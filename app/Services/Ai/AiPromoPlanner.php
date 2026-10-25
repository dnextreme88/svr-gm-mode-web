<?php

namespace App\Services\Ai;

use App\Enums\Brand;
use App\Enums\PromoTypeKey;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\MatchSlot;
use App\Models\PpvEvent;
use App\Models\PromoSlot;
use App\Models\PromoType;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\Calendar\CalendarService;
use App\Services\Promos\PromoEligibility;
use App\Services\Promos\PromoService;
use App\Services\Rivalries\RivalryService;
use App\Services\Titles\TitleService;
use App\Support\Rng\Rng;

/**
 * The rival GM's promos (house rule, `gm.ai.promos`). They're booked after
 * the matches, from this priority list, each checked against
 * `PromoEligibility`:
 *
 *  1. Low on cash (or saving up for renewals, `AiRosterManager::needsCash`):
 *     Advertising, then Merchandise Sponsorship.
 *  2. PPV Hype, 1 or 2 weeks (rolled once per PPV) before its own brand-only PPVs.
 *  3. Interference, every 4 weeks.
 *  4. Slander, once the rolled gap (3, 5 or 6 weeks) since the last one has passed.
 *  5. Start a Rivalry, when the rivalry plan wants one.
 *  6. A weighted pick of the situational promos that fit this week (one a card).
 *  7. Advertising, then Merchandise Sponsorship.
 *
 * Wrestlers in tiring promos stay within the careful fatigue limit.
 */
class AiPromoPlanner
{
    /** @var array<int, int> */
    private array $fatigue = [];

    public function __construct(
        private PromoService $promos,
        private PromoEligibility $eligibility,
        private CalendarService $calendar,
        private TitleService $titles,
        private RivalryService $rivalries,
        private AiBooker $booker,
        private AiRosterManager $roster,
    ) {}

    public function book(Show $show, Brand $brand, int $rivalryStarts = 0): void
    {
        if ($show->brand !== $brand || $show->promoSlotCount() === 0) {
            return;
        }

        $rng = $show->game->rng("ai.promos.{$show->streamKey()}", $show->week_number);
        $this->fatigue = $this->booker->plannedFatigue($show, $brand);
        $types = PromoType::all()->keyBy(fn (PromoType $t): string => $t->key->value);
        $used = [];

        for ($position = 1; $position <= $show->promoSlotCount(); $position++) {
            if (PromoSlot::where('show_id', $show->id)->where('position', $position)->whereNotNull('promo_type_id')->exists()) {
                continue;
            }

            foreach ($this->candidates($show, $brand, $rng, $rivalryStarts, $used) as $candidate) {
                [$key, $a, $b, $championship] = $candidate + [1 => null, 2 => null, 3 => null];

                try {
                    $this->promos->book($show, $position, $types[$key->value], $a, $b, $championship);
                } catch (GameRuleException) {
                    continue;
                }

                $used[] = $key;

                if ($key === PromoTypeKey::StartRivalry) {
                    $rivalryStarts--;
                }

                foreach (array_filter([$a, $b]) as $id) {
                    $this->fatigue[$id] = ($this->fatigue[$id] ?? 0) + $types[$key->value]->fatigue_bars * (int) config('gm.fatigue.bar_size');
                }

                break;
            }
        }
    }

    /**
     * Every promo worth trying for the next slot, best first.
     *
     * @param  list<PromoTypeKey>  $used
     * @return list<array{0: PromoTypeKey, 1?: int|null, 2?: int|null, 3?: int|null}>
     */
    private function candidates(Show $show, Brand $brand, Rng $rng, int $rivalryStarts, array $used): array
    {
        $money = [[PromoTypeKey::Advertising], [PromoTypeKey::MerchandiseSponsorship]];
        $money = array_values(array_filter($money, fn (array $c): bool => ! in_array($c[0], $used, true))) ?: [[PromoTypeKey::Advertising]];

        if ($this->roster->needsCash($show->game, $brand)) {
            return $money;
        }

        $list = [];

        if ($this->ppvHypeWeek($show, $brand)) {
            $list[] = [PromoTypeKey::PpvHype];
        }

        if ($this->weeksSince($show, $brand, PromoTypeKey::InterferencePromotion) >= (int) config('gm.ai.promos.interference_every_weeks')) {
            $target = $this->tiringTarget($show, $brand, PromoTypeKey::InterferencePromotion, $rng);

            if ($target !== null) {
                $list[] = [PromoTypeKey::InterferencePromotion, $target];
            }
        }

        if ($this->slanderDue($show, $brand)) {
            $list[] = [PromoTypeKey::SlanderPromotion];
        }

        if ($rivalryStarts > 0) {
            $pair = $this->rivalryPair($show, $brand, $rng);

            if ($pair !== null) {
                $list[] = [PromoTypeKey::StartRivalry, ...$pair];
            }
        }

        // One situational promo a card, so the money promos keep the lights on.
        $situationalUsed = array_intersect(array_map(fn (PromoTypeKey $k): string => $k->value, $used), array_keys((array) config('gm.ai.promos.situational_weights'))) !== [];
        $list = [...$list, ...($situationalUsed ? [] : $this->situational($show, $brand, $rng, $used)), ...$money];

        return array_values(array_filter($list, fn (array $c): bool => ! in_array($c[0], $used, true)
            || in_array($c, $money, true)
            || $c[0] === PromoTypeKey::StartRivalry));
    }

    /**
     * This is the week before one of the brand's own PPVs that was rolled
     * for its PPV Hype (1 or 2 weeks out, once per PPV per season).
     */
    public function ppvHypeWeek(Show $show, Brand $brand): bool
    {
        $season = $this->calendar->seasonOf($show->week_number);

        foreach ([1, 2] as $ahead) {
            $ppv = $this->calendar->ppvsForWeek($show->week_number + $ahead, $brand)->first(fn (PpvEvent $p): bool => ! $p->isShared());

            if ($ppv === null) {
                continue;
            }

            $rolled = (int) $show->game->rng("ai.ppvhype.{$season}.{$ppv->key->value}", 0)->weightedPick($this->weights('gm.ai.promos.ppv_hype_weeks_before'));

            if ($rolled === $ahead) {
                return true;
            }
        }

        return false;
    }

    /**
     * The gap after each Slander is rolled from the week it ran (week 0
     * before the first), so it stays the same however often it's checked.
     */
    public function slanderDue(Show $show, Brand $brand): bool
    {
        $last = $this->lastWeek($show, $brand, PromoTypeKey::SlanderPromotion) ?? 0;
        $gap = (int) $show->game->rng("ai.slander.{$brand->value}.{$last}", 0)->weightedPick($this->weights('gm.ai.promos.slander_gap_weeks'));

        return $show->week_number - $last >= $gap;
    }

    private function weeksSince(Show $show, Brand $brand, PromoTypeKey $key): int
    {
        // Like Slander, the first one counts from week 0.
        return $show->week_number - ($this->lastWeek($show, $brand, $key) ?? 0);
    }

    private function lastWeek(Show $show, Brand $brand, PromoTypeKey $key): ?int
    {
        $week = PromoSlot::query()
            ->join('shows', 'shows.id', '=', 'promo_slots.show_id')
            ->join('promo_types', 'promo_types.id', '=', 'promo_slots.promo_type_id')
            ->where('shows.save_id', $show->save_id)
            ->where('shows.brand', $brand)
            ->where('shows.id', '!=', $show->id)
            ->where('promo_types.key', $key)
            ->max('shows.week_number');

        return $week === null ? null : (int) $week;
    }

    /**
     * @param  list<PromoTypeKey>  $used
     * @return list<array{0: PromoTypeKey, 1?: int|null, 2?: int|null, 3?: int|null}>
     */
    private function situational(Show $show, Brand $brand, Rng $rng, array $used): array
    {
        $options = [];
        $rules = $this->eligibility->forShow($show, $brand);
        $roster = SaveWrestler::with('wrestler')->where('save_id', $show->save_id)->where('brand', $brand)->get()->keyBy('id');
        $booked = $this->bookedIds($show);

        $titleIds = $rules[PromoTypeKey::TitleMatchHype->value]['championship_ids'];

        if ($titleIds !== []) {
            $options['title_match_hype'] = [PromoTypeKey::TitleMatchHype, null, null, $this->hypedTitle($brand, $titleIds, $rng)];
        }

        $joined = $rules[PromoTypeKey::NewSuperstar->value]['wrestler_ids'];

        if ($joined !== []) {
            $options['new_superstar'] = [PromoTypeKey::NewSuperstar, $rng->pick($joined)];
        }

        $longOut = $rules[PromoTypeKey::ComebackReminder->value]['wrestler_ids'];

        if ($longOut !== []) {
            $options['comeback_reminder'] = [PromoTypeKey::ComebackReminder, $rng->pick($longOut)];
        }

        $inactive = collect($rules[PromoTypeKey::InactiveSuperstar->value]['wrestler_ids'])
            ->sortByDesc(fn (int $id): int => (($this->fatigue[$id] ?? 0) * 1000) + (int) $roster->get($id)?->weeks_unused)
            ->first();

        if ($inactive !== null) {
            $options['inactive_superstar'] = [PromoTypeKey::InactiveSuperstar, $inactive];
        }

        $limit = (int) config('gm.ai.booking.careful_max_fatigue') - (int) config('gm.fatigue.bar_size');
        $rivalry = collect($rules[PromoTypeKey::ContinueRivalry->value]['pairs'])
            ->first(fn (array $p): bool => ($this->fatigue[$p[0]] ?? 0) <= $limit && ($this->fatigue[$p[1]] ?? 0) <= $limit);

        if ($rivalry !== null) {
            $options['continue_rivalry'] = [PromoTypeKey::ContinueRivalry, $rivalry[0], $rivalry[1]];
        }

        if ($this->mainEventWorthHyping($show, $brand)) {
            $options['main_event_hype'] = [PromoTypeKey::MainEventHype];
        }

        $interview = collect($rules[PromoTypeKey::InterviewSpace->value]['wrestler_ids'])
            ->reject(fn (int $id): bool => in_array($id, $booked, true))
            ->values();

        if ($interview->isNotEmpty()) {
            $spotlight = $interview->filter(function (int $id) use ($roster): bool {
                $w = $roster->get($id);

                return $w !== null && ($this->titles->isChampion($w) || $w->win_streak >= 3 || $w->loss_streak >= 3);
            })->values();

            $options['interview_space'] = [PromoTypeKey::InterviewSpace, $rng->pick(($spotlight->isNotEmpty() ? $spotlight : $interview)->all())];
        }

        $divaLimit = (int) config('gm.ai.booking.careful_max_fatigue') - 2 * (int) config('gm.fatigue.bar_size');
        $divas = collect($rules[PromoTypeKey::DivaBikiniContest->value]['wrestler_ids'])
            ->filter(fn (int $id): bool => ($this->fatigue[$id] ?? 0) <= $divaLimit)
            ->sortBy(fn (int $id): int => in_array($id, $booked, true) ? 1 : 0)
            ->values();

        if ($divas->count() >= 2) {
            $options['diva_bikini_contest'] = [PromoTypeKey::DivaBikiniContest, $divas[0], $divas[1]];
        }

        $options = array_filter($options, fn (array $c): bool => ! in_array($c[0], $used, true));
        $weights = array_intersect_key($this->weights('gm.ai.promos.situational_weights'), $options);
        $picked = [];

        while ($weights !== []) {
            $key = $rng->weightedPick($weights);
            unset($weights[$key]);

            if (isset($options[$key])) {
                $picked[] = $options[$key];
            }
        }

        return $picked;
    }

    /**
     * Which title to hype: world 60%, second tier 30%, the rest 10%, from
     * the title matches actually on the card.
     *
     * @param  list<int>  $titleIds
     */
    private function hypedTitle(Brand $brand, array $titleIds, Rng $rng): int
    {
        $order = Championship::where('brand', $brand)->orderBy('sort_order')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $tierWeights = (array) config('gm.ai.promos.title_hype_weights');
        $weights = [];

        foreach ($titleIds as $id) {
            $tier = match (array_search($id, $order, true)) {
                0 => 'world',
                1 => 'second',
                default => 'other',
            };

            $weights[$id] = (int) $tierWeights[$tier];
        }

        return (int) $rng->weightedPick($weights);
    }

    private function mainEventWorthHyping(Show $show, Brand $brand): bool
    {
        $main = MatchSlot::with('participants')
            ->where('show_id', $show->id)
            ->whereNull('fixed_kind')
            ->orderByDesc('position')
            ->first();

        if ($main === null || ! $main->isBooked()) {
            return false;
        }

        if ($main->title_championship_id !== null) {
            return true;
        }

        $ids = $main->competitorIds();

        return count($ids) === 2 && $this->rivalries->between($show->game, $ids[0], $ids[1]) !== null;
    }

    /**
     * A healthy non-Diva for Interference, unbooked first, within the
     * careful limit after the promo's fatigue.
     */
    private function tiringTarget(Show $show, Brand $brand, PromoTypeKey $key, Rng $rng): ?int
    {
        $limit = $this->promoLimit($key);
        $booked = $this->bookedIds($show);
        $ids = collect($this->eligibility->forShow($show, $brand)[$key->value]['wrestler_ids'])
            ->filter(fn (int $id): bool => ($this->fatigue[$id] ?? 0) <= $limit)
            ->values();

        if ($ids->isEmpty()) {
            return null;
        }

        $unbooked = $ids->reject(fn (int $id): bool => in_array($id, $booked, true))->values();

        return $rng->pick(($unbooked->isNotEmpty() ? $unbooked : $ids)->all());
    }

    /**
     * Two same-gender wrestlers not in a rivalry, fit enough for Start a
     * Rivalry, unbooked first.
     *
     * @return array{0: int, 1: int}|null
     */
    private function rivalryPair(Show $show, Brand $brand, Rng $rng): ?array
    {
        $limit = $this->promoLimit(PromoTypeKey::StartRivalry);
        $booked = $this->bookedIds($show);
        // Nobody already in this card's other Start a Rivalry.
        $pending = PromoSlot::where('show_id', $show->id)->whereNotNull('promo_type_id')->get()->flatMap(fn (PromoSlot $p): array => $p->targetIds())->all();
        $ids = collect($this->eligibility->forShow($show, $brand)[PromoTypeKey::StartRivalry->value]['wrestler_ids'])
            ->filter(fn (int $id): bool => ($this->fatigue[$id] ?? 0) <= $limit && ! in_array($id, $pending, true))
            ->values();
        $people = SaveWrestler::with('wrestler')->whereIn('id', $ids)->get();

        foreach ($rng->shuffle([true, false]) as $divas) {
            $group = $people->filter(fn (SaveWrestler $w): bool => $w->wrestler->isDiva() === $divas)
                ->sortBy(fn (SaveWrestler $w): int => in_array($w->id, $booked, true) ? 1 : 0)
                ->values();

            if ($group->count() >= 2) {
                $unbooked = $group->reject(fn (SaveWrestler $w): bool => in_array($w->id, $booked, true))->values();
                $from = $unbooked->count() >= 2 ? $unbooked : $group;
                $pair = $rng->sample(array_map('intval', $from->modelKeys()), 2);

                return [$pair[0], $pair[1]];
            }
        }

        return null;
    }

    /**
     * @return array<array-key, float>
     */
    private function weights(string $key): array
    {
        return array_map(fn (mixed $w): float => (float) $w, (array) config($key));
    }

    private function promoLimit(PromoTypeKey $key): int
    {
        return (int) config('gm.ai.booking.careful_max_fatigue')
            - (int) config("gm.promos.fatigue_bars.{$key->value}", 0) * (int) config('gm.fatigue.bar_size');
    }

    /**
     * @return list<int>
     */
    private function bookedIds(Show $show): array
    {
        return array_values(array_unique(MatchSlot::with('participants')
            ->where('show_id', $show->id)
            ->get()
            ->flatMap(fn (MatchSlot $s): array => $s->participantIds())
            ->map(fn ($id): int => (int) $id)
            ->all()));
    }
}
