<?php

use App\Enums\MatchMethod;
use App\Http\Presenters\CardPresenter;
use App\Models\MatchResult;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Models\NewsEvent;
use App\Services\Matches\MatchResolver;
use App\Services\Matches\ResolvedMatch;
use App\Services\Week\WeekResolver;
use App\Support\Rng\SeededRng;
use Illuminate\Support\Collection;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| Timed matches (house rule, 2026-11-08): an Iron Man or Ultimate Submission
| runs out the clock and ends on a score, never tied.
*/

function timedSlot(string $family, string $variant, ?string $referee = null): MatchSlot
{
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Shawn Michaels'], smackdown: ['Batista']);
    GameBuilder::passiveRival();
    $slot = Card::book($save, Card::show($save), 1, Card::type($family, $variant), [['Edge'], ['Kane']], referee: $referee);

    return $slot->fresh(['matchType', 'participants.saveWrestler.wrestler.specialties', 'show']);
}

/**
 * @return Collection<int, ResolvedMatch>
 */
function timedResults(MatchSlot $slot, ?int $booked = null): Collection
{
    // DQs and count-outs as likely as they get: a timed match still never ends on one.
    config(['gm.outcomes.clean_finish_chance' => 0.0, 'gm.outcomes.booked_non_decisive_chance' => 1.0]);

    return collect(range(1, 60))->map(fn (int $i) => app(MatchResolver::class)->resolve($slot, new SeededRng("timed-{$i}"), collect(), $booked));
}

it('ends an Iron Man on a score, the winner ahead, never on a DQ or count-out', function () {
    $results = timedResults(timedSlot('iron_man', 'Single'));

    foreach ($results as $r) {
        expect($r->winnerScore)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(10)
            ->and($r->loserScore)->toBeGreaterThanOrEqual(0)->toBeLessThan($r->winnerScore)
            ->and($r->method)->toBe(MatchMethod::MatchSpecific)
            ->and($r->finishEvent)->toBeNull()
            ->and($r->summary)->toEndWith("as time runs out, winning {$r->winnerScore}–{$r->loserScore}.")
            ->and($r->summary)->toContain('scores the deciding fall on');
    }

    expect($results->pluck('winnerScore')->unique()->count())->toBeGreaterThan(3);
});

it('scores a booked Iron Man too', function () {
    foreach (timedResults(timedSlot('iron_man', 'Single'), booked: 1) as $r) {
        expect($r->winnerSide)->toBe(1)
            ->and($r->winnerScore)->toBeGreaterThan($r->loserScore)
            ->and($r->isDecisive())->toBeTrue();
    }
});

it('always ends an Ultimate Submission by submission, on a score', function () {
    foreach (timedResults(timedSlot('submission', 'Ultimate Submission')) as $r) {
        expect($r->method)->toBe(MatchMethod::Submission)
            ->and($r->winnerScore)->toBeGreaterThan($r->loserScore)
            ->and($r->summary)->toContain('tap out for the last time as time runs out');
    }
});

it('scores the Special Referee Iron Man', function () {
    $r = timedResults(timedSlot('special_referee', 'Iron Man', referee: 'Shawn Michaels'))->first();

    expect(MatchType::where('family', 'special_referee')->where('variant', 'Iron Man')->value('win_condition'))->toBe('Most falls')
        ->and($r->winnerScore)->not->toBeNull();
});

it('lets count-outs happen in an Iron Man', function () {
    GameBuilder::seedCatalog();

    expect(MatchType::where('family', 'iron_man')->where('variant', 'Single')->sole())
        ->countout_enabled->toBeTrue()
        ->dq_enabled->toBeTrue();
});

it('names the score in the news', function () {
    $type = fn (string $f, string $v) => MatchType::where('family', $f)->where('variant', $v)->sole();
    $resolved = fn (MatchMethod $m) => new ResolvedMatch(0, 1, [1], [2], $m, null, null, '', 0, winnerScore: 3, loserScore: 1);
    GameBuilder::seedCatalog();

    expect($resolved(MatchMethod::MatchSpecific)->how($type('iron_man', 'Single')))->toBe('in an Iron Man match with a 3-1 score')
        ->and($resolved(MatchMethod::Submission)->how($type('submission', 'Ultimate Submission')))->toBe('in an Ultimate Submission match with a 3-1 score')
        ->and($resolved(MatchMethod::MatchSpecific)->score())->toBe('3–1');
});

it('stores the score and writes it into the match news', function () {
    $slot = timedSlot('iron_man', 'Single');
    app(WeekResolver::class)->resolve($slot->show->game->refresh());

    $result = MatchResult::where('match_slot_id', $slot->id)->sole();
    $news = NewsEvent::where('save_id', $slot->show->save_id)->get()->first(fn (NewsEvent $e): bool => str_contains((string) $e->rendered_text, 'Iron Man match with a'));

    expect($result->winner_score)->toBeGreaterThan($result->loser_score)
        ->and($news)->not->toBeNull()
        ->and($news->rendered_text)->toContain("{$result->winner_score}-{$result->loser_score} score");
});

it('shows the score beside the method on the results', function () {
    $slot = timedSlot('iron_man', 'Single');
    app(WeekResolver::class)->resolve($slot->show->game->refresh());
    $result = MatchResult::where('match_slot_id', $slot->id)->sole();

    $outcome = CardPresenter::outcome($slot->fresh(['result.championReign', 'participants.saveWrestler.wrestler', 'matchType']));

    expect($outcome['method_label'])->toBe("Most falls · {$result->winner_score}–{$result->loser_score}")
        ->and($outcome['score'])->toBe("{$result->winner_score}–{$result->loser_score}");
});
