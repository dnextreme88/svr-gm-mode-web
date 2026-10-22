<?php

namespace App\Services\News;

use App\Enums\NewsBrand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Models\NewsEvent;
use App\Models\Save;
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
     * Rival-targeting promo results always take the neutral headline (§5, §19).
     */
    private const FORCED_NEUTRAL = ['slander_result', 'interference_promo_result'];

    public function __construct(
        private CalendarService $calendar,
        private NewsTemplates $templates,
    ) {}

    /**
     * Every played week, newest first, whether or not it has news. The
     * current week is included once something has been recorded in it.
     *
     * @return list<int>
     */
    public function weeks(Save $save): array
    {
        $latestNews = (int) NewsEvent::where('save_id', $save->id)->max('week_number');
        $latest = max($save->current_week - 1, $latestNews);

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

        return array_map(fn (int $week): array => $this->compose($save, $week, $byWeek->get($week, collect())), $this->weeks($save));
    }

    /**
     * @param  Collection<int, NewsEvent>|null  $events  the week's events (queried when null)
     * @return array{week: int, season: int, season_week: int, headlines: array<string, array<string, mixed>|null>, stories: array<string, list<array<string, mixed>>>, ticker: list<array<string, mixed>>, fan_support: null}
     */
    public function compose(Save $save, int $week, ?Collection $events = null): array
    {
        $events ??= NewsEvent::where('save_id', $save->id)->where('week_number', $week)->get();
        $rows = $this->dedupe($events->sortBy([['priority', 'desc'], ['id', 'asc']])->values());
        $rng = $save->rng('news.page', $week);

        $headlines = [];
        $stories = ['raw' => [], 'smackdown' => []];
        $picked = [];

        foreach (['raw', 'smackdown', 'neutral'] as $slot) {
            $pool = $rows->filter(fn (array $e): bool => $e['slot'] === $slot && $e['tier'] !== NewsTier::Ticker->value)->values();
            $headline = $slot === 'neutral' ? $pool->first(fn (array $e): bool => in_array($e['type'], self::FORCED_NEUTRAL, true)) : null;
            $headline ??= $this->weightedTake($pool->filter(fn (array $e): bool => $e['tier'] === NewsTier::Headline->value), 1, $rng)[0] ?? null;

            // A slot with no headline gets one of its stories promoted.
            if ($headline === null) {
                $headline = $this->weightedTake($pool, 1, $rng)[0] ?? null;

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

        // Whatever wasn't picked runs in the ticker, biggest news first.
        $ticker = array_values($rows->reject(fn (array $e): bool => isset($picked[$e['id']]))->all());

        return [
            'week' => $week,
            'season' => $this->calendar->seasonOf($week),
            'season_week' => $this->calendar->seasonWeek($week),
            'headlines' => $headlines,
            'stories' => $stories,
            'ticker' => $ticker,
            // Fan support trend comes from the deferred Popularity system.
            'fan_support' => null,
        ];
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
