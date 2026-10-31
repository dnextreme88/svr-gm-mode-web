<?php

namespace App\Services\News;

use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Models\NewsEvent;
use App\Models\Save;
use App\Models\Show;
use App\Services\Attendance\AttendanceNights;
use App\Services\Calendar\CalendarService;
use App\Support\Rng\Rng;
use Illuminate\Support\Collection;

/**
 * Lays out a week's News page (§19): 3 headline cards (Raw, SmackDown,
 * Neutral), 4 story cards (2 per brand) and a ticker. The week's events are
 * one pool: headlines and stories are drawn from it with the save's seeded
 * RNG (weighted by priority), so a week always lays out the same way.
 */
class NewsComposer
{
    /**
     * Rival-targeting promo results take the neutral headline (§5, §19),
     * unless it's a PPV week: then the PPV's main event does.
     */
    private const FORCED_NEUTRAL = ['slander_result', 'interference_promo_result'];

    public function __construct(
        private CalendarService $calendar,
        private NewsTemplates $templates,
        private AttendanceNights $nights,
    ) {}

    /**
     * Every played week, newest first, whether or not it has news. House
     * rule: only weeks that have aired. News recorded during the current
     * week (a signing, a trade) waits until that week is over.
     *
     * @return list<int>
     */
    public function weeks(Save $save): array
    {
        $latest = $save->current_week - 1;

        return $latest < 1 ? [] : range($latest, 1);
    }

    /**
     * Every played week's page, newest first, from one query.
     *
     * @return list<array<string, mixed>>
     */
    public function pages(Save $save): array
    {
        $byWeek = NewsEvent::where('save_id', $save->id)->get()->groupBy('week_number');
        $shows = Show::with('ppvEvent')->where('save_id', $save->id)->whereNotNull('attendance')->orderBy('id')->get()->toBase()->groupBy('week_number');

        return array_map(fn (int $week): array => $this->compose($save, $week, $byWeek->get($week, collect()), $this->nights->group($shows->get($week, collect()))), $this->weeks($save));
    }

    /**
     * @param  Collection<int, NewsEvent>|null  $events  the week's events (queried when null)
     * @param  list<array{name: string, ppv: bool, crowd: int}>|null  $nights  the week's crowds (queried when null)
     * @return array{week: int, season: int, season_week: int, headlines: array<string, array<string, mixed>|null>, stories: array<string, list<array<string, mixed>>>, ticker: list<array<string, mixed>>, attendance: list<array{name: string, ppv: bool, crowd: int}>}
     */
    public function compose(Save $save, int $week, ?Collection $events = null, ?array $nights = null): array
    {
        $events ??= NewsEvent::where('save_id', $save->id)->where('week_number', $week)->get();
        $nights ??= $this->nights->forWeek($save, $week);
        $rows = $this->dedupe($events->sortBy([['priority', 'desc'], ['id', 'asc']])->values());
        $matchRanks = $this->matchRanks($events);
        $rng = $save->rng('news.page', $week);

        $headlines = [];
        $stories = ['raw' => [], 'smackdown' => []];
        $picked = [];

        foreach (['raw', 'smackdown', 'neutral'] as $slot) {
            $pool = $rows->filter(fn (array $e): bool => $e['slot'] === $slot && $e['tier'] !== NewsTier::Ticker->value)->values();
            // House rule: in a PPV week the PPV wins the WWE headline: its main
            // event (or the Royal Rumble), biggest first.
            $headline = $slot === 'neutral' ? $pool->filter(fn (array $e): bool => $e['type'] === NewsEventType::PpvResults->value)->sortByDesc('priority')->first() : null;
            $headline ??= $slot === 'neutral' ? $pool->first(fn (array $e): bool => in_array($e['type'], self::FORCED_NEUTRAL, true)) : null;
            // House rule (2026-11-01): then the week's biggest crowd.
            $headline ??= $slot === 'neutral' ? $pool->first(fn (array $e): bool => $e['type'] === NewsEventType::Attendance->value) : null;
            // House rule: a title change always takes its card's headline.
            $headline ??= $pool->filter(fn (array $e): bool => in_array($e['type'], [NewsEventType::TitleChange->value, NewsEventType::FactionTitleWon->value], true) && $e['tier'] === NewsTier::Headline->value)
                ->sortByDesc('priority')
                ->first();
            $headline ??= $this->weightedTake($pool->filter(fn (array $e): bool => $e['tier'] === NewsTier::Headline->value), 1, $rng)[0] ?? null;

            // A slot with no headline gets one of its stories promoted: the
            // biggest match first (PPV → title → main event → gimmick, see
            // `gm.news.match_result_priority`), else a weighted draw.
            if ($headline === null) {
                // Quiet-week filler only leads when there is nothing else.
                $real = $pool->reject(fn (array $e): bool => NewsEventType::from($e['type'])->isFallback());
                $promotable = $real->isEmpty() ? $pool : $real;
                $headline = $promotable->filter(fn (array $e): bool => isset($matchRanks[$e['id']]))
                    ->sortBy([fn (array $a, array $b): int => $matchRanks[$b['id']] <=> $matchRanks[$a['id']], fn (array $a, array $b): int => $b['priority'] <=> $a['priority']])
                    ->first()
                    ?? $this->weightedTake($promotable, 1, $rng)[0]
                    ?? null;

                if ($headline !== null) {
                    $headline['promoted'] = true;
                }
            }

            $headlines[$slot] = $headline;

            if ($headline !== null) {
                $picked[$headline['id']] = true;
            }

            if ($slot !== 'neutral') {
                $rest = $pool->reject(fn (array $e): bool => isset($picked[$e['id']]));
                $stories[$slot] = $this->weightedTake($rest, (int) config('gm.news.stories_per_brand'), $rng);

                foreach ($stories[$slot] as $story) {
                    $picked[$story['id']] = true;
                }
            }
        }

        // Whatever wasn't picked runs in the ticker, biggest news first. The
        // crowds have their own line there, so attendance news doesn't repeat.
        $ticker = array_values($rows->reject(fn (array $e): bool => isset($picked[$e['id']]) || $e['type'] === NewsEventType::Attendance->value)->all());

        return [
            'week' => $week,
            'season' => $this->calendar->seasonOf($week),
            'season_week' => $this->calendar->seasonWeek($week),
            'headlines' => $headlines,
            'stories' => $stories,
            'ticker' => $ticker,
            // The ticker's gold Fan attendance line.
            'attendance' => array_map(fn (array $n): array => ['name' => $n['name'], 'ppv' => $n['ppv'], 'crowd' => $n['crowd']], $nights),
        ];
    }

    /**
     * How big each event's match is, keyed by event id: the match result's
     * priority (which encodes PPV → title → main event → gimmick), shared
     * by the bigger news that replaced it on the same match (title defence,
     * promo story…), matched on the match's dedupe key.
     *
     * @param  Collection<int, NewsEvent>  $events
     * @return array<int, int>
     */
    private function matchRanks(Collection $events): array
    {
        $ranks = [];

        foreach ($events as $e) {
            if ($e->type === NewsEventType::MatchResult && $e->dedupe_key !== null) {
                $ranks[$e->dedupe_key] = $e->priority;
            }
        }

        $ids = [];

        foreach ($events as $e) {
            if ($e->dedupe_key !== null && isset($ranks[$e->dedupe_key])) {
                $ids[$e->id] = $ranks[$e->dedupe_key];
            }
        }

        return $ids;
    }

    /**
     * Draw up to `$count` rows without replacement, weighted by priority.
     *
     * @template TRow of array{priority: int}
     *
     * @param  Collection<int, TRow>  $rows
     * @return list<TRow>
     */
    private function weightedTake(Collection $rows, int $count, Rng $rng): array
    {
        $left = $rows->values()->all();
        $taken = [];

        while ($left !== [] && count($taken) < $count) {
            $index = (int) $rng->weightedPick(array_map(fn (array $e): int => max(1, (int) $e['priority']), $left));
            $taken[] = $left[$index];
            array_splice($left, $index, 1);
        }

        return $taken;
    }

    /**
     * Events sharing a dedupe key (e.g. `title_change` and `streak_snapped`
     * from one match) keep the highest tier; the rest drop to Story. A plain
     * `match_result` is dropped when another event covers the same match.
     *
     * @param  Collection<int, NewsEvent>  $events
     * @return Collection<int, array{id: int, type: string, tier: string, slot: string, priority: int, text: string, summary: string, payload: array<string, mixed>, promoted: bool}>
     */
    private function dedupe(Collection $events): Collection
    {
        $rows = $events->map(fn (NewsEvent $e): array => [
            'id' => $e->id,
            'type' => $e->type->value,
            'tier' => $e->tier->value,
            'slot' => match ($e->brand) {
                NewsBrand::Raw => 'raw',
                NewsBrand::Smackdown => 'smackdown',
                NewsBrand::Both => 'neutral',
            },
            'priority' => $e->priority,
            'text' => $e->rendered_text,
            'summary' => $this->templates->summary($e->type, $e->payload),
            'payload' => $e->payload,
            'promoted' => false,
            'dedupe_key' => $e->dedupe_key,
        ]);

        $isResult = fn (array $row): bool => $row['type'] === NewsEventType::MatchResult->value;
        $covered = $rows->whereNotNull('dedupe_key')->reject($isResult)->pluck('dedupe_key')->flip();
        $rows = $rows->reject(fn (array $row): bool => $isResult($row) && $covered->has($row['dedupe_key']))->values();

        $winners = [];

        foreach ($rows->whereNotNull('dedupe_key')->groupBy('dedupe_key') as $key => $group) {
            $top = $group->sortBy([fn (array $a, array $b): int => $this->rank($a['tier']) <=> $this->rank($b['tier']), fn (array $a, array $b): int => $b['priority'] <=> $a['priority']])->first();
            $winners[$key] = $top['id'];
        }

        return $rows->map(function (array $row) use ($winners): array {
            if ($row['dedupe_key'] !== null && $winners[$row['dedupe_key']] !== $row['id'] && $row['tier'] === NewsTier::Headline->value) {
                $row['tier'] = NewsTier::Story->value;
            }

            unset($row['dedupe_key']);

            return $row;
        })->values();
    }

    private function rank(string $tier): int
    {
        return match ($tier) {
            NewsTier::Headline->value => 0,
            NewsTier::Story->value => 1,
            default => 2,
        };
    }
}
