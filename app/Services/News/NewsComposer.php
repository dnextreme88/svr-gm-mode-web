<?php

namespace App\Services\News;

use App\Enums\NewsBrand;
use App\Enums\NewsTier;
use App\Models\NewsEvent;
use App\Models\Save;
use App\Services\Calendar\CalendarService;
use Illuminate\Support\Collection;

/**
 * Lays out a week's News page (§19): 3 headline cards (Raw, SmackDown,
 * Neutral), 4 story cards (2 per brand) and a ticker.
 */
class NewsComposer
{
    public function __construct(private CalendarService $calendar) {}

    /**
     * Weeks that have news, newest first.
     *
     * @return list<int>
     */
    public function weeks(Save $save, int $limit = 52): array
    {
        return array_values(array_map('intval', NewsEvent::where('save_id', $save->id)
            ->distinct()
            ->orderByDesc('week_number')
            ->limit($limit)
            ->pluck('week_number')
            ->all()));
    }

    /**
     * @return array{week: int, season: int, season_week: int, headlines: array<string, array<string, mixed>|null>, stories: array<string, list<array<string, mixed>>>, ticker: list<array<string, mixed>>, fan_support: null}
     */
    public function compose(Save $save, int $week): array
    {
        $events = $this->dedupe(NewsEvent::where('save_id', $save->id)
            ->where('week_number', $week)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get());

        $headlines = [];
        $stories = ['raw' => [], 'smackdown' => []];
        $ticker = [];

        foreach (['raw', 'smackdown', 'neutral'] as $slot) {
            $inSlot = $events->filter(fn (array $e): bool => $e['slot'] === $slot)->values();
            $headline = $inSlot->first(fn (array $e): bool => $e['tier'] === NewsTier::Headline->value);

            // A slot with no headline gets its top story promoted.
            if ($headline === null) {
                $headline = $inSlot->first(fn (array $e): bool => $e['tier'] === NewsTier::Story->value);

                if ($headline !== null) {
                    $headline['promoted'] = true;
                }
            }

            $headlines[$slot] = $headline;
            $rest = $inSlot->reject(fn (array $e): bool => $headline !== null && $e['id'] === $headline['id']);

            // Leftover headlines drop to the story row; leftover stories to the ticker.
            $storyPool = $rest->filter(fn (array $e): bool => $e['tier'] !== NewsTier::Ticker->value)
                ->sortBy([fn (array $a, array $b): int => $this->rank($a['tier']) <=> $this->rank($b['tier']), fn (array $a, array $b): int => $b['priority'] <=> $a['priority']])
                ->values();

            if ($slot !== 'neutral') {
                $count = (int) config('gm.news.stories_per_brand');
                $stories[$slot] = array_values($storyPool->take($count)->all());
                $storyPool = $storyPool->slice($count);
            }

            $ticker = [...$ticker, ...$storyPool->all(), ...$rest->filter(fn (array $e): bool => $e['tier'] === NewsTier::Ticker->value)->all()];
        }

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
     * Events sharing a dedupe key (e.g. `title_change` and `streak_snapped`
     * from one match) keep the highest tier; the rest drop to Story.
     *
     * @param  Collection<int, NewsEvent>  $events
     * @return Collection<int, array{id: int, type: string, tier: string, slot: string, priority: int, text: string, payload: array<string, mixed>, promoted: bool}>
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
            'payload' => $e->payload,
            'promoted' => false,
            'dedupe_key' => $e->dedupe_key,
        ]);

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
