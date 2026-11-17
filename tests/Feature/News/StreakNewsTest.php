<?php

use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Models\NewsEvent;
use App\Models\Save;
use App\Services\Matches\ShowResolver;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| House rule (2026-11-17): a win streak makes the news at 10 wins, then 15
| and every 5 after; a snapped streak only when it had reached 10.
*/

function streakWeek(string $winner, int $edgeStreak): Save
{
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    GameBuilder::wrestler($save, 'Edge')->update(['win_streak' => $edgeStreak]);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);
    FixedOutcomes::queue($winner);

    app(WeekResolver::class)->resolve($save->refresh());

    return $save;
}

function streakNews(Save $save, NewsEventType $type): ?NewsEvent
{
    return NewsEvent::where('save_id', $save->id)->where('type', $type)->first();
}

it('marks 10, 15, 20 and 25 wins as milestones, and nothing below 10', function () {
    $milestones = array_values(array_filter(range(1, 26), fn (int $n) => ShowResolver::isStreakMilestone($n)));

    expect($milestones)->toBe([10, 15, 20, 25]);
});

it('makes a 10-win streak a headline', function () {
    $news = streakNews(streakWeek('Edge', 9), NewsEventType::WinStreak);

    expect($news)->not->toBeNull()
        ->and($news->tier)->toBe(NewsTier::Headline);
});

it('says nothing about a 3-win streak', function () {
    expect(streakNews(streakWeek('Edge', 2), NewsEventType::WinStreak))->toBeNull();
});

it('snaps only a streak of 10 or more', function () {
    expect(streakNews(streakWeek('Kane', 10), NewsEventType::StreakSnapped))->not->toBeNull()
        ->and(streakNews(streakWeek('Kane', 9), NewsEventType::StreakSnapped))->toBeNull();
});
