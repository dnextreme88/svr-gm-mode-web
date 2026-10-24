<?php

use App\Enums\Brand;
use App\Enums\InjurySeverity;
use App\Enums\MatchMethod;
use App\Enums\NewsEventType;
use App\Enums\PromoTypeKey;
use App\Models\MatchSlot;
use App\Models\NewsEvent;
use App\Models\PromoType;
use App\Models\Save;
use App\Models\Show;
use App\Services\Injuries\InjuryService;
use App\Services\Matches\MatchResolver;
use App\Services\News\NewsTemplates;
use App\Services\News\PromoStories;
use App\Services\Promos\PromoService;
use App\Services\Promos\PromoStoryWriter;
use App\Services\Rivalries\RivalryService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| House rule: every aired promo writes its own news story, picked by what
| happened around it on the show, with at least 4 equally likely variants.
*/

function storyPromo(Save $save, PromoTypeKey $key, ?string $wrestler = null, ?string $wrestlerB = null, ?int $championshipId = null): void
{
    $show = Card::show($save->refresh());

    app(PromoService::class)->book(
        $show,
        1,
        PromoType::where('key', $key)->firstOrFail(),
        $wrestler === null ? null : GameBuilder::wrestler($save, $wrestler)->id,
        $wrestlerB === null ? null : GameBuilder::wrestler($save, $wrestlerB)->id,
        $championshipId,
    );
}

function seriouslyHurt(Save $save, string $name): void
{
    app(InjuryService::class)->injure(GameBuilder::wrestler($save, $name), InjurySeverity::Serious, 1, $save->rng("hurt.{$name}"));
}

function storyWeek(Save $save): void
{
    app(WeekResolver::class)->resolve($save->refresh());
}

function rawPromoStory(?NewsEventType $type = null): NewsEvent
{
    return NewsEvent::where('type', $type ?? NewsEventType::PromoResult)->where('brand', 'raw')->sole();
}

describe('the story catalog', function () {
    it('has at least 4 variants for every situation that stands on its own', function () {
        foreach (PromoStories::keys() as $key) {
            if (! str_ends_with($key, '+')) {
                expect(count(PromoStories::variants($key)))->toBeGreaterThanOrEqual(4, $key);
            }
        }
    });

    it('covers every promo type', function () {
        $promos = array_values(array_unique(array_map(fn (string $key): string => explode('.', $key)[0], PromoStories::keys())));

        expect($promos)->toEqualCanonicalizing(PromoTypeKey::values());
    });

    it('only uses placeholders the writer fills in', function () {
        $known = ['brand', 'rival_brand', 'show', 'wrestler', 'wrestler_b', 'opponent', 'winner', 'loser', 'champion', 'challenger', 'title', 'how', 'where', 'streak', 'weeks', 'injury', 'weeks_left', 'amount', 'ppv', 'ppv_weeks', 'pronoun', 'rivalry'];

        foreach (PromoStories::keys() as $key) {
            foreach (PromoStories::variants($key) as [$headline, $summary]) {
                preg_match_all('/\{(\w+)\}/', $headline.$summary, $m);

                expect(array_diff($m[1], $known))->toBe([], $key);
            }
        }
    });
});

describe('Interview Space', function () {
    beforeEach(function () {
        config(['gm.promos.success_rates.interview_space' => 1.0]);
    });

    it('falls back to the spotlight interview when there is nothing about the wrestler’s form', function () {
        $save = GameBuilder::inSeason(raw: ['Rob Van Dam', 'Kane', 'Edge'], smackdown: ['Batista']);
        storyPromo($save, PromoTypeKey::InterviewSpace, 'Rob Van Dam');

        storyWeek($save);
        $story = rawPromoStory();

        expect($story->payload['story'])->toBe('interview_space.spotlight')
            ->and(app(NewsTemplates::class)->summary(NewsEventType::PromoResult, $story->payload))
            ->toMatch('/^Rob Van Dam had an? (sit-down interview|interview) tonight and was being (goofy|enthusiastic)\. It was a great interview and gave insights on how he would be used and ready to take over and dominate the Raw division!$/');
    });

    it('gets crashed when it fails: a healthy male superstar decks the wrestler and a rivalry starts', function () {
        config(['gm.promos.success_rates.interview_space' => 0.0]);
        $save = GameBuilder::inSeason(raw: ['Rob Van Dam', 'Kane', 'Trish Stratus', 'Edge'], smackdown: ['Batista']);
        seriouslyHurt($save, 'Kane');
        storyPromo($save, PromoTypeKey::InterviewSpace, 'Rob Van Dam');

        storyWeek($save);
        $story = rawPromoStory();
        $summary = app(NewsTemplates::class)->summary(NewsEventType::PromoResult, $story->payload);
        $crasher = $story->payload['wrestler_b'];

        expect($story->payload['story'])->toBe('interview_space.crashed')
            // Not the injured Kane, not the Diva.
            ->and($crasher)->not->toBeIn(['Kane', 'Trish Stratus', 'Rob Van Dam'])
            ->and($summary)->toContain("{$crasher} crashed the party and decked Rob Van Dam with authority. What's gonna happen next between these two? The attack has started a rivalry between the two.")
            ->and(app(RivalryService::class)->between($save, GameBuilder::wrestler($save, 'Rob Van Dam')->id, GameBuilder::wrestler($save, $crasher)->id))->not->toBeNull();
    });

    it('leaves out the rivalry when either wrestler is already in one', function () {
        config(['gm.promos.success_rates.interview_space' => 0.0]);
        $save = GameBuilder::inSeason(raw: ['Rob Van Dam', 'Kane', 'Edge'], smackdown: ['Batista']);
        app(RivalryService::class)->start($save, GameBuilder::wrestler($save, 'Rob Van Dam')->id, GameBuilder::wrestler($save, 'Edge')->id, 1);
        storyPromo($save, PromoTypeKey::InterviewSpace, 'Rob Van Dam');

        storyWeek($save);
        $story = rawPromoStory();

        expect($story->payload['story'])->toBe('interview_space.crashed')
            ->and($story->payload['rivalry'])->toBe('')
            ->and(app(NewsTemplates::class)->summary(NewsEventType::PromoResult, $story->payload))->toEndWith('What\'s gonna happen next between these two?');
    });

    it('spins a win as inspired by the interview', function () {
        $save = GameBuilder::inSeason(raw: ['Rob Van Dam', 'Kane'], smackdown: ['Batista']);
        Card::book($save, Card::show($save), 1, Card::singles(), [['Rob Van Dam'], ['Kane']]);
        storyPromo($save, PromoTypeKey::InterviewSpace, 'Rob Van Dam');
        FixedOutcomes::queue('Rob Van Dam', MatchMethod::Submission);

        storyWeek($save);
        $story = rawPromoStory();

        expect($story->payload['story'])->toBe('interview_space.success_won')
            ->and($story->payload['opponent'])->toBe('Kane')
            ->and($story->payload['how'])->toBe('by submission')
            ->and($story->rendered_text)->toContain('Rob Van Dam');
    });

    it('blames a loss on what was said in the interview', function () {
        $save = GameBuilder::inSeason(raw: ['Rob Van Dam', 'Kane'], smackdown: ['Batista']);
        Card::book($save, Card::show($save), 1, Card::singles(), [['Rob Van Dam'], ['Kane']]);
        storyPromo($save, PromoTypeKey::InterviewSpace, 'Rob Van Dam');
        FixedOutcomes::queue('Kane');

        storyWeek($save);

        $story = rawPromoStory();

        expect($story->payload['story'])->toBe('interview_space.success_lost')
            ->and(app(NewsTemplates::class)->summary(NewsEventType::PromoResult, $story->payload))->toContain('Kane');
    });

    it('draws an unbooked interview from the generic pool plus the wrestler’s form', function () {
        $save = GameBuilder::inSeason(raw: ['Rob Van Dam', 'Kane', 'Edge'], smackdown: ['Batista']);
        GameBuilder::wrestler($save, 'Rob Van Dam')->update(['win_streak' => 4]);
        Card::book($save, Card::show($save), 1, Card::singles(), [['Kane'], ['Edge']]);
        storyPromo($save, PromoTypeKey::InterviewSpace, 'Rob Van Dam');
        FixedOutcomes::queue('Kane');

        storyWeek($save);
        $story = rawPromoStory();
        $show = Show::where('save_id', $save->id)->where('brand', Brand::Raw)->where('week_number', 1)->firstOrFail();

        expect($story->payload['story'])->toBeIn(['interview_space.unbooked', 'interview_space.unbooked_win_streak+']);

        // Every variant of both situations can come up.
        $seen = [];

        foreach (range(1, 60) as $i) {
            app(PromoStoryWriter::class)->write($show, $save->rng("story-test.{$i}", 1));
            $latest = NewsEvent::where('type', NewsEventType::PromoResult)->where('brand', 'raw')->latest('id')->first();
            $seen[$latest->payload['story'].'#'.$latest->payload['variant']] = true;
        }

        expect(array_keys($seen))->toHaveCount(6);
    });
});

describe('Main Event Hype', function () {
    it('headlines a hyped main event that crowns a new champion', function () {
        config(['gm.promos.success_rates.main_event_hype' => 1.0]);
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        $title = GameBuilder::giveTitle($save, 'intercontinental', ['Kane'])->championship_id;
        Card::book($save, Card::show($save), 6, Card::singles(), [['Edge'], ['Kane']], titleId: $title);
        storyPromo($save, PromoTypeKey::MainEventHype);
        FixedOutcomes::queue('Edge');

        storyWeek($save);
        $story = rawPromoStory(NewsEventType::MainEventHypeResult);

        expect($story->payload['story'])->toBe('main_event_hype.success_title_change')
            ->and($story->payload['winner'])->toBe('Edge')
            ->and($story->payload['title'])->toBe('Intercontinental Championship')
            ->and(NewsEvent::where('type', NewsEventType::MatchResult)->where('brand', 'raw')->count())->toBe(0);
    });

    it('still tells the main event story when the hype fails', function () {
        config(['gm.promos.success_rates.main_event_hype' => 0.0]);
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        Card::book($save, Card::show($save), 6, Card::singles(), [['Edge'], ['Kane']]);
        storyPromo($save, PromoTypeKey::MainEventHype);
        FixedOutcomes::queue('Kane', MatchMethod::Dq);

        storyWeek($save);
        $story = rawPromoStory();

        expect($story->payload['story'])->toBe('main_event_hype.failed_match')
            ->and($story->payload['how'])->toBe('by disqualification');
    });
});

describe('Title Match Hype', function () {
    beforeEach(function () {
        config(['gm.promos.success_rates.title_match_hype' => 1.0]);
    });

    it('says where the title match was and that the champion retained', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian', 'Carlito'], smackdown: ['Batista']);
        $title = GameBuilder::giveTitle($save, 'intercontinental', ['Kane'])->championship_id;
        Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']], titleId: $title);
        Card::book($save, Card::show($save), 2, Card::singles(), [['Christian'], ['Carlito']]);
        storyPromo($save, PromoTypeKey::TitleMatchHype, championshipId: $title);
        FixedOutcomes::queue('Kane')->queue('Christian');

        storyWeek($save);
        $story = rawPromoStory();

        expect($story->payload['story'])->toBe('title_match_hype.success_retained')
            ->and($story->payload['where'])->toBe('to open the show')
            ->and($story->payload['winner'])->toBe('Kane');
    });

    it('calls a challenger’s DQ win a cheap escape for the champion', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        $title = GameBuilder::giveTitle($save, 'intercontinental', ['Kane'])->championship_id;
        Card::book($save, Card::show($save), 6, Card::singles(), [['Edge'], ['Kane']], titleId: $title);
        storyPromo($save, PromoTypeKey::TitleMatchHype, championshipId: $title);
        FixedOutcomes::queue('Edge', MatchMethod::Dq);

        storyWeek($save);
        $story = rawPromoStory();

        expect($story->payload['story'])->toBe('title_match_hype.title_cheap')
            ->and($story->payload['champion'])->toBe('Kane')
            ->and($story->payload['challenger'])->toBe('Edge')
            ->and($story->payload['where'])->toBe('in the main event');
    });
});

it('names the injured wrestler and the injury in a Comeback Reminder', function () {
    config(['gm.promos.success_rates.comeback_reminder' => 1.0]);
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Kane'), InjurySeverity::Serious, 1, $save->rng('inj.kane'))->update(['started_week' => -5, 'ends_week' => 4]);
    storyPromo($save, PromoTypeKey::ComebackReminder, 'Kane');

    storyWeek($save);
    $story = rawPromoStory();

    expect($story->payload['story'])->toBe('comeback_reminder.success')
        ->and($story->payload['weeks_left'])->toBe('3 weeks')
        ->and($story->rendered_text)->toContain('Kane');
});

it('makes a Submission specialist win a clean finish by submission', function () {
    config(['gm.outcomes.clean_finish_chance' => 1.0, 'gm.outcomes.submission_chance' => 0.0]);
    $save = GameBuilder::inSeason(raw: ['Chris Benoit', 'Kane'], smackdown: ['Batista']);
    $slot = Card::book($save, Card::show($save), 1, Card::singles(), [['Chris Benoit'], ['Kane']]);
    $slot = MatchSlot::with(['matchType', 'participants.saveWrestler.wrestler.specialties'])->findOrFail($slot->id);
    $benoit = GameBuilder::wrestler($save, 'Chris Benoit')->id;
    $methods = [];

    foreach (range(1, 20) as $i) {
        $resolved = app(MatchResolver::class)->resolve($slot, $save->rng("sub.{$i}"), collect());
        $methods[$resolved->winners === [$benoit] ? 'benoit' : 'kane'][] = $resolved->method;
    }

    expect(array_unique(array_map(fn (MatchMethod $m) => $m->value, $methods['benoit'])))->toBe(['submission'])
        ->and(array_unique(array_map(fn (MatchMethod $m) => $m->value, $methods['kane'])))->toBe(['pinfall']);
});
