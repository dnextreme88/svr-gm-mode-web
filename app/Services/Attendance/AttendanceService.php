<?php

namespace App\Services\Attendance;

use App\Enums\FixedSlotKind;
use App\Enums\NewsEventType;
use App\Enums\PromoTypeKey;
use App\Enums\ShowStatus;
use App\Models\Championship;
use App\Models\MatchSlot;
use App\Models\NewsEvent;
use App\Models\PromoSlot;
use App\Models\SaveWrestler;
use App\Models\Show;
use App\Services\News\NewsRecorder;
use App\Services\Week\WeekContext;
use Illuminate\Support\Collection;

/**
 * Fan attendance (house rule, 2026-11-01): once a weekly show, or the last
 * card of a PPV night, has aired, scores the night (`AttendanceCalculator`)
 * and stores the crowd on every card of it. Win streaks and rivalry heat
 * are taken going into the week (`AirShows::start`).
 *
 * @phpstan-import-type DrawFacts from AttendanceCalculator
 * @phpstan-import-type SlotDraw from AttendanceCalculator
 */
class AttendanceService
{
    public function __construct(
        private AttendanceCalculator $calculator,
        private NewsRecorder $news,
    ) {}

    /**
     * Score the night `$show` belongs to, once all of its cards have aired
     * (`$show` itself is closing).
     */
    public function tally(Show $show, WeekContext $context): void
    {
        $cards = $this->nightCards($show);

        if ($cards->contains(fn (Show $card): bool => ! $card->is($show) && $card->status !== ShowStatus::Aired)) {
            return;
        }

        $kind = $this->kind($show);
        $factors = $this->calculator->factors($this->facts($cards, $context));
        $spread = (int) config('gm.attendance.noise');
        $noise = $context->rng('attendance.'.self::nightKey($show))->int(-$spread, $spread);
        $score = $this->calculator->score($factors, $noise);
        $crowd = $this->calculator->crowd($kind, $score);
        $breakdown = [
            'kind' => $kind,
            'factors' => $factors,
            'noise' => $noise,
            'score' => $score,
            'gate' => $this->calculator->gate($kind, $crowd),
        ];

        foreach ($cards as $card) {
            $card->forceFill(['attendance' => $crowd, 'attendance_breakdown' => $breakdown])->save();
        }

        $show->forceFill(['attendance' => $crowd, 'attendance_breakdown' => $breakdown]);

        if ($show->isPpv()) {
            $this->addCrowdToPpvResults($show, $crowd);
        }
    }

    /**
     * The cards of the night a show is on, in air order: a weekly show on
     * its own; a PPV's brand cards, then its interbrand card.
     *
     * @return Collection<int, Show>
     */
    public function nightCards(Show $show): Collection
    {
        if (! $show->isPpv() || $show->ppv_event_id === null) {
            return collect([$show]);
        }

        return Show::with('ppvEvent')
            ->where('save_id', $show->save_id)
            ->where('week_number', $show->week_number)
            ->where('ppv_event_id', $show->ppv_event_id)
            ->get()
            ->sortBy(fn (Show $s): string => ($s->isInterbrand() ? '1' : '0').($s->brand->value ?? 'z'))
            ->values()
            ->toBase();
    }

    /**
     * `weekly`, `brand_ppv` or `shared_ppv` (Royal Rumble, WrestleMania,
     * SummerSlam, Survivor Series).
     */
    public function kind(Show $show): string
    {
        if (! $show->isPpv()) {
            return 'weekly';
        }

        return $show->ppvEvent?->isShared() ? 'shared_ppv' : 'brand_ppv';
    }

    /**
     * One key per night: a weekly show's own, or the PPV's for all its cards.
     */
    public static function nightKey(Show $show): string
    {
        return $show->isPpv() ? "{$show->week_number}.ppv" : $show->streamKey();
    }

    /**
     * What the night had on it, for the calculator.
     *
     * @param  Collection<int, Show>  $cards
     * @return DrawFacts
     */
    public function facts(Collection $cards, WeekContext $context): array
    {
        $slotsByCard = $cards->map(fn (Show $card): Collection => MatchSlot::with(['matchType', 'titleChampionship', 'participants.saveWrestler.wrestler'])
            ->where('show_id', $card->id)
            ->where(fn ($q) => $q->whereNull('fixed_kind')->orWhere('fixed_kind', '!=', FixedSlotKind::MitbCashIn))
            ->orderBy('position')
            ->get()
            ->toBase());

        $slots = $slotsByCard->flatten(1);
        $booked = $slots->filter(fn (MatchSlot $s): bool => $s->isBooked());
        $matchOne = $slotsByCard->first()?->first();
        $mainEvent = $slotsByCard->last()?->last();
        $rivalries = $this->rivalriesGoingIn($context);
        $streaks = $this->streaksGoingIn($context, $booked);
        $hot = (int) config('gm.attendance.hot_heat');

        $participants = $booked->flatMap(fn (MatchSlot $s) => $s->participants)->unique('save_wrestler_id');
        $promos = PromoSlot::with('promoType')->whereIn('show_id', $cards->pluck('id'))->whereNotNull('promo_type_id')->get();
        $rival = $promos->filter(fn (PromoSlot $p): bool => in_array($p->promoType?->key, [PromoTypeKey::InterferencePromotion, PromoTypeKey::SlanderPromotion], true));
        $other = $promos->diff($rival);

        return [
            'match_one_booked' => $matchOne?->isBooked() ?? false,
            'main_event_booked' => $mainEvent?->isBooked() ?? false,
            'every_slot_booked' => $slots->isNotEmpty() && $booked->count() === $slots->count(),
            'main_event' => $mainEvent?->isBooked() ? $this->slotDraw($mainEvent, $rivalries) : null,
            'match_one' => $matchOne?->isBooked() ? $this->slotDraw($matchOne, $rivalries) : null,
            'hot_rivalries' => count(array_filter($rivalries, fn (array $r): bool => $r['heat'] >= $hot
                && $booked->contains(fn (MatchSlot $s): bool => $this->faceEachOther($s, $r)))),
            'legends' => $participants->filter(fn ($p): bool => $p->saveWrestler->wrestler->isLegend())->count(),
            'rival_promos' => ['booked' => $rival->count(), 'succeeded' => $rival->where('succeeded', true)->count()],
            'other_promos' => ['booked' => $other->count(), 'succeeded' => $other->where('succeeded', true)->count()],
            'best_streak' => $streaks === [] ? 0 : max($streaks),
        ];
    }

    /**
     * @param  list<array{a: int, b: int, heat: int}>  $rivalries
     * @return SlotDraw
     */
    private function slotDraw(MatchSlot $slot, array $rivalries): array
    {
        $titles = array_values(array_filter([$slot->titleChampionship, ...$this->contested($slot)]));

        return [
            'title' => match (true) {
                $titles === [] => null,
                collect($titles)->contains(fn ($c): bool => (bool) $c->is_world) => 'world',
                default => 'other',
            },
            'gimmick' => $slot->matchType?->isGimmick() ?? false,
            'rivals' => collect($rivalries)->contains(fn (array $r): bool => $this->faceEachOther($slot, $r)),
        ];
    }

    /**
     * The titles a Winner Takes All put on the line.
     *
     * @return list<Championship>
     */
    private function contested(MatchSlot $slot): array
    {
        $ids = (array) ($slot->contested_title_ids ?? []);

        return $ids === [] ? [] : array_values(Championship::whereIn('id', $ids)->get()->all());
    }

    /**
     * Whether a rivalry's two rivals are on opposite sides of a match.
     *
     * @param  array{a: int, b: int, heat: int}  $rivalry
     */
    private function faceEachOther(MatchSlot $slot, array $rivalry): bool
    {
        $sideOf = [];

        foreach ($slot->sides() as $side => $ids) {
            foreach ($ids as $id) {
                $sideOf[$id] = $side;
            }
        }

        return isset($sideOf[$rivalry['a']], $sideOf[$rivalry['b']]) && $sideOf[$rivalry['a']] !== $sideOf[$rivalry['b']];
    }

    /**
     * Active rivalries and their heat going into the week.
     *
     * @return list<array{a: int, b: int, heat: int}>
     */
    private function rivalriesGoingIn(WeekContext $context): array
    {
        return array_values(array_map(fn (array $r): array => ['a' => (int) $r['a'], 'b' => (int) $r['b'], 'heat' => (int) $r['heat']], (array) ($context->data['rivalries_start'] ?? [])));
    }

    /**
     * Win streaks going into the week of everyone competing on the night.
     *
     * @param  Collection<int, MatchSlot>  $booked
     * @return list<int>
     */
    private function streaksGoingIn(WeekContext $context, Collection $booked): array
    {
        $ids = $booked->flatMap(fn (MatchSlot $s): array => $s->competitorIds())->unique()->values();

        // The snapshot only lists streaks above 0. Without one (a week begun
        // before attendance existed), today's streaks stand in.
        $start = isset($context->data['streaks_start'])
            ? (array) $context->data['streaks_start']
            : SaveWrestler::whereIn('id', $ids)->pluck('win_streak', 'id')->all();

        return array_values($ids->map(fn (int $id): int => (int) ($start[$id] ?? 0))->all());
    }

    /**
     * The PPV main event news gains the crowd: "41,302 fans were there."
     */
    private function addCrowdToPpvResults(Show $show, int $crowd): void
    {
        $events = NewsEvent::where('save_id', $show->save_id)
            ->where('week_number', $show->week_number)
            ->where('type', NewsEventType::PpvResults)
            ->get();

        foreach ($events as $event) {
            $this->news->rewrite($event, [...$event->payload, 'crowd' => ' '.number_format($crowd).' fans were in the building.']);
        }
    }
}
