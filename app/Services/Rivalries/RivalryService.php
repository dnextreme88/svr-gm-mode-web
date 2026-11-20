<?php

namespace App\Services\Rivalries;

use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\ParticipantRole;
use App\Enums\ShowStatus;
use App\Enums\ShowType;
use App\Models\MatchSlot;
use App\Models\Rivalry;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\News\NewsRecorder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Rivalries and their heat (§3 Rivalry, §19). ASSUMPTION: the spec leaves
 * heat undefined; the numbers live in config('gm.rivalries').
 *
 * House rule (2026-11-12): two superstars meeting twice in 4 weeks start a
 * rivalry at 10 heat. After that, heat is settled once a week
 * (settleWeek): a week the rivals share a match, as opponents or partners,
 * adds 10, a PPV they share a match at 20; a week with neither but a
 * Continue Rivalry promo holds it; any other week loses 20, and at 0 it
 * ends. Reaching 100 ends it the following week, with a headline.
 */
class RivalryService
{
    public function __construct(private NewsRecorder $news) {}

    /**
     * @return Collection<int, Rivalry>
     */
    public function active(Save $save): Collection
    {
        return Rivalry::with(['wrestlerA.wrestler', 'wrestlerB.wrestler'])
            ->where('save_id', $save->id)
            ->where('active', true)
            ->orderByDesc('heat')
            ->get();
    }

    /**
     * The two wrestler ids, the alphabetically first name first.
     *
     * @return array{0: int, 1: int}
     */
    public function alphabetical(int $a, int $b): array
    {
        $names = SaveWrestler::with('wrestler')->whereIn('id', [$a, $b])->get()->mapWithKeys(fn (SaveWrestler $w): array => [$w->id => $w->name()]);

        return strcasecmp((string) $names->get($a), (string) $names->get($b)) <= 0 ? [$a, $b] : [$b, $a];
    }

    public function between(Save $save, int $a, int $b): ?Rivalry
    {
        return Rivalry::where('save_id', $save->id)
            ->where('active', true)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('wrestler_a_id', $a)->where('wrestler_b_id', $b))
                ->orWhere(fn ($q) => $q->where('wrestler_a_id', $b)->where('wrestler_b_id', $a)))
            ->first();
    }

    /**
     * Start a rivalry (Start a Rivalry promo, or repeated meetings).
     */
    public function start(Save $save, int $a, int $b, int $week): Rivalry
    {
        $existing = $this->between($save, $a, $b);

        if ($existing !== null) {
            return $existing;
        }

        // House rule: a rivalry is named alphabetically ("Jeff Hardy vs. Rob
        // Van Dam"), so the first wrestler is the one whose name sorts first.
        [$first, $second] = $this->alphabetical($a, $b);

        $rivalry = Rivalry::create([
            'save_id' => $save->id,
            'wrestler_a_id' => $first,
            'wrestler_b_id' => $second,
            'heat' => (int) config('gm.rivalries.start_heat'),
            'started_week' => $week,
            'last_activity_week' => $week,
        ])->load(['wrestlerA.wrestler', 'wrestlerB.wrestler']);

        // House rule (2026-11-15): the brand it started on, for the Rivalries page.
        $rivalry->update(['brand' => $rivalry->wrestlerA->brand ?? $rivalry->wrestlerB->brand]);

        $this->news->record($save, NewsEventType::RivalryStarted, NewsTier::Story, $this->brandOf($rivalry), [
            'wrestler_a' => $rivalry->wrestlerA->name(),
            'wrestler_b' => $rivalry->wrestlerB->name(),
        ], ['wrestler_ids' => [$a, $b]], priority: 25, week: $week);

        return $rivalry;
    }

    /**
     * Add heat (shared matches, a Rival Interference finish). Reaching the
     * maximum marks the week, and the rivalry ends the week after.
     */
    public function heatUp(Rivalry $rivalry, int $amount, int $week): void
    {
        $threshold = (int) config('gm.rivalries.heating_up_threshold');
        $max = (int) config('gm.rivalries.max_heat');
        $before = $rivalry->heat;
        $after = min($max, $before + $amount);

        $rivalry->update([
            'heat' => $after,
            'last_activity_week' => $week,
            'maxed_week' => $after >= $max ? ($rivalry->maxed_week ?? $week) : null,
        ]);

        if ($before < $threshold && $after >= $threshold) {
            $rivalry->loadMissing(['wrestlerA.wrestler', 'wrestlerB.wrestler']);
            $this->news->record($rivalry->game, NewsEventType::RivalryHeatingUp, NewsTier::Story, $this->brandOf($rivalry), [
                'wrestler_a' => $rivalry->wrestlerA->name(),
                'wrestler_b' => $rivalry->wrestlerB->name(),
            ], ['wrestler_ids' => [$rivalry->wrestler_a_id, $rivalry->wrestler_b_id]], priority: 30, week: $week);
        }
    }

    /**
     * Record singles-style opponents meeting in a match: enough recent
     * meetings start a rivalry. Heat for rivals already feuding is settled
     * at the end of the week (settleWeek), not here.
     */
    public function recordMeeting(Save $save, int $winnerId, int $loserId, int $week): void
    {
        if ($this->between($save, $winnerId, $loserId) !== null) {
            return;
        }

        $meetings = $this->recentMeetings($save, $winnerId, $loserId, $week) + 1;

        if ($meetings >= (int) config('gm.rivalries.auto_create_meetings')) {
            $this->start($save, $winnerId, $loserId, $week);
        }
    }

    /**
     * A Continue Rivalry promo (house rule, 2026-11-12): no heat either way,
     * but the week doesn't count as idle.
     */
    public function hold(Rivalry $rivalry, int $week): void
    {
        $rivalry->update(['last_activity_week' => max($rivalry->last_activity_week, $week)]);
    }

    /**
     * A successful Call-out promo (house rule, 2026-11-15): the rivalry heats
     * up and the week isn't idle.
     */
    public function recordCallOut(Rivalry $rivalry, int $week): void
    {
        $this->heatUp($rivalry, (int) config('gm.rivalries.heat_per_call_out'), $week);
        $this->hold($rivalry, $week);
    }

    /**
     * A rival ran in and cost their rival the match (house rule, 2026-11-11):
     * the rivalry between them heats up.
     *
     * @param  list<int>  $loserIds
     */
    public function recordInterference(Save $save, int $interfererId, array $loserIds, int $week): void
    {
        foreach ($loserIds as $loserId) {
            $rivalry = $this->between($save, $interfererId, $loserId);

            if ($rivalry !== null && $rivalry->active) {
                $this->heatUp($rivalry, (int) config('gm.rivalries.heat_per_interference'), $week);
            }
        }
    }

    /**
     * Settle every active rivalry's heat once the week has aired (house
     * rule, 2026-11-12), in this order:
     *  - one that reached the maximum in an earlier week ends, with a headline;
     *  - the week it started changes nothing (it stays at the start heat);
     *  - sharing a match adds heat: a weekly show once, a PPV once more;
     *  - with no shared match, a Continue Rivalry promo (or a Rival
     *    Interference finish) this week holds it;
     *  - otherwise it loses the idle decay, and at 0 it ends.
     */
    public function settleWeek(Save $save, int $week): void
    {
        $rivalries = Rivalry::where('save_id', $save->id)->where('active', true)->get();

        if ($rivalries->isEmpty()) {
            return;
        }

        $shared = $this->sharedMatches($save, $week);

        foreach ($rivalries as $rivalry) {
            $rivalry->weeks_active++;

            if ($rivalry->maxed_week !== null && $rivalry->maxed_week < $week) {
                $this->conclude($save, $rivalry, $week);

                continue;
            }

            // House rule (2026-11-20): the rival GM's blow-off match has aired.
            if ($this->blowoffAired($rivalry, $week)) {
                $this->conclude($save, $rivalry, $week);

                continue;
            }

            $key = $this->pairKey($rivalry->wrestler_a_id, $rivalry->wrestler_b_id);
            $gain = (($shared[$key]['weekly'] ?? false) ? (int) config('gm.rivalries.heat_per_meeting') : 0)
                + (($shared[$key]['ppv'] ?? false) ? (int) config('gm.rivalries.heat_per_ppv_meeting') : 0);

            if ($rivalry->started_week === $week) {
                // The week it starts it stays at the start heat.
            } elseif ($gain > 0) {
                $this->heatUp($rivalry, $gain, $week);
            } elseif ($rivalry->last_activity_week < $week) {
                $rivalry->heat = max(0, $rivalry->heat - (int) config('gm.rivalries.idle_decay'));

                if ($rivalry->heat === 0) {
                    $rivalry->active = false;
                    $rivalry->ended_week = $week;
                }
            }

            $rivalry->save();

            // ASSUMPTION: heat stands in for fan feedback until Popularity exists.
            if ($rivalry->active && $rivalry->weeks_active === (int) config('gm.news.rivalry_milestone_weeks') && $rivalry->heat >= (int) config('gm.rivalries.heating_up_threshold')) {
                $rivalry->loadMissing(['wrestlerA.wrestler', 'wrestlerB.wrestler']);
                $this->news->record($save, NewsEventType::RivalryMilestone, NewsTier::Headline, $this->brandOf($rivalry), [
                    'wrestler_a' => $rivalry->wrestlerA->name(),
                    'wrestler_b' => $rivalry->wrestlerB->name(),
                    'weeks' => $rivalry->weeks_active,
                ], ['wrestler_ids' => [$rivalry->wrestler_a_id, $rivalry->wrestler_b_id]], priority: 60, week: $week);
            }
        }
    }

    /**
     * Whether the rival GM's planned blow-off match (house rule, 2026-11-20)
     * aired by this week with both rivals in it.
     */
    private function blowoffAired(Rivalry $rivalry, int $week): bool
    {
        if ($rivalry->blowoff_slot_id === null) {
            return false;
        }

        $slot = MatchSlot::with(['show', 'participants'])->find($rivalry->blowoff_slot_id);

        if ($slot === null || $slot->show->status !== ShowStatus::Aired || $slot->show->week_number > $week) {
            return false;
        }

        $ids = $slot->competitorIds();

        return in_array($rivalry->wrestler_a_id, $ids, true) && in_array($rivalry->wrestler_b_id, $ids, true);
    }

    /**
     * Every aired match both rivals took part in while the rivalry ran
     * (house rule, 2026-11-20): as competitors, managers or the special
     * referee (`gm.rivalries.meeting_roles`), newest first. It's the
     * rivalry's match history on /rivalries and the rival GM's count of
     * matches together.
     *
     * @return list<int>
     */
    public function matchSlotIds(Rivalry $rivalry, ?int $untilWeek = null): array
    {
        $roles = $this->meetingRoles();
        $last = $rivalry->ended_week ?? $untilWeek;

        return array_values(array_map('intval', MatchSlot::query()
            ->join('shows', 'shows.id', '=', 'match_slots.show_id')
            ->where('shows.save_id', $rivalry->save_id)
            ->where('shows.status', ShowStatus::Aired)
            ->where('shows.week_number', '>=', $rivalry->started_week)
            ->when($last !== null, fn ($q) => $q->where('shows.week_number', '<=', $last))
            ->whereHas('participants', fn ($q) => $q->where('save_wrestler_id', $rivalry->wrestler_a_id)->whereIn('role', $roles))
            ->whereHas('participants', fn ($q) => $q->where('save_wrestler_id', $rivalry->wrestler_b_id)->whereIn('role', $roles))
            ->orderByDesc('shows.week_number')
            // In a PPV week the weekly show airs after the PPV night.
            ->orderByRaw('CASE WHEN shows.show_type = ? THEN 0 ELSE 1 END', [ShowType::Weekly->value])
            ->orderByDesc('match_slots.position')
            ->pluck('match_slots.id')
            ->all()));
    }

    /**
     * @return list<string>
     */
    private function meetingRoles(): array
    {
        return array_values(array_map('strval', (array) config('gm.rivalries.meeting_roles', [ParticipantRole::Competitor->value])));
    }

    /**
     * End a rivalry with a `rivalry_concluded` headline: one that reached
     * the maximum heat ends the week after (house rule, 2026-11-12), and a
     * rival GM blow-off once its match airs (house rule, 2026-11-20).
     */
    public function conclude(Save $save, Rivalry $rivalry, int $week): void
    {
        $rivalry->active = false;
        $rivalry->ended_week = $week;
        $rivalry->save();
        $rivalry->loadMissing(['wrestlerA.wrestler', 'wrestlerB.wrestler']);

        $this->news->record($save, NewsEventType::RivalryConcluded, NewsTier::Headline, $this->brandOf($rivalry), [
            'wrestler_a' => $rivalry->wrestlerA->name(),
            'wrestler_b' => $rivalry->wrestlerB->name(),
            'weeks' => $week - $rivalry->started_week,
        ], ['wrestler_ids' => [$rivalry->wrestler_a_id, $rivalry->wrestler_b_id]], priority: 70, week: $week, dedupeKey: "rivalry:{$rivalry->id}");
    }

    /**
     * Which pairs shared a match this week, on a weekly show and at a PPV,
     * keyed by pairKey(). Opponents and partners both count, and (house
     * rule, 2026-11-20) so does a rival who manages or referees a match the
     * other is in (`gm.rivalries.meeting_roles`).
     *
     * @return array<string, array{weekly?: bool, ppv?: bool}>
     */
    private function sharedMatches(Save $save, int $week): array
    {
        $pairs = [];
        $roles = $this->meetingRoles();
        $slots = MatchSlot::with(['show', 'participants'])
            ->whereHas('show', fn ($q) => $q->where('save_id', $save->id)->where('week_number', $week)->where('status', ShowStatus::Aired))
            ->get();

        foreach ($slots as $slot) {
            $ids = $slot->participants
                ->filter(fn ($p): bool => in_array($p->role->value, $roles, true))
                ->pluck('save_wrestler_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $kind = $slot->show->isPpv() ? 'ppv' : 'weekly';

            foreach ($ids as $i => $a) {
                foreach (array_slice($ids, $i + 1) as $b) {
                    $pairs[$this->pairKey($a, $b)][$kind] = true;
                }
            }
        }

        return $pairs;
    }

    private function pairKey(int $a, int $b): string
    {
        return min($a, $b).'-'.max($a, $b);
    }

    private function recentMeetings(Save $save, int $a, int $b, int $week): int
    {
        $window = (int) config('gm.rivalries.auto_create_window_weeks');

        return MatchSlot::query()
            ->whereHas('show', fn ($q) => $q->where('save_id', $save->id)->where('status', ShowStatus::Aired)->where('week_number', '>', $week - $window))
            ->whereHas('participants', fn ($q) => $q->where('save_wrestler_id', $a)->where('role', ParticipantRole::Competitor))
            ->whereHas('participants', fn ($q) => $q->where('save_wrestler_id', $b)->where('role', ParticipantRole::Competitor))
            ->count();
    }

    private function brandOf(Rivalry $rivalry): NewsBrand
    {
        $rivalry->loadMissing(['wrestlerA', 'wrestlerB']);
        $a = $rivalry->wrestlerA->brand;
        $b = $rivalry->wrestlerB->brand;

        return $a !== null && $a === $b ? NewsBrand::from($a->value) : NewsBrand::Both;
    }
}
