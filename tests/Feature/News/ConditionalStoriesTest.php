<?php

use App\Enums\ConditionalStory;
use App\Enums\ConditionalStoryOutcome;
use App\Enums\InjurySeverity;
use App\Enums\MatchMethod;
use App\Enums\NewsEventType;
use App\Enums\PromoTypeKey;
use App\Models\ConditionalStoryTrigger;
use App\Models\GauntletBout;
use App\Models\MatchSlot;
use App\Models\NewsEvent;
use App\Models\PromoType;
use App\Models\Rivalry;
use App\Models\Save;
use App\Models\SaveFaction;
use App\Services\Factions\FactionService;
use App\Services\Injuries\InjuryService;
use App\Services\Matches\Stories\ConditionalStoryTexts;
use App\Services\News\NewsTemplates;
use App\Services\Promos\PromoService;
use App\Services\Rivalries\RivalryService;
use App\Services\Week\WeekResolver;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| Conditional match stories (house rule, 2026-11-07): Hornswoggle's
| shillelagh, Undertaker's lights-out, Kane's pyro, the Dudleys' tables and
| Team Xtreme's ladder. Simulated matches only, never Book to Win.
*/

beforeEach(function () {
    GameBuilder::passiveRival();
    // Every story's chances forced; tests turn them down where needed.
    config([
        'gm.stories.enabled' => true,
        'gm.stories.shillelagh.chance' => 1.0,
        'gm.stories.lights_out.win_chance' => 1.0,
        'gm.stories.pyro.win_chance' => 1.0,
        'gm.stories.tables.land_chance' => 1.0,
        'gm.stories.tables.win_chance' => 1.0,
        'gm.stories.ladder.land_chance' => 1.0,
        'gm.stories.ladder.win_chance' => 1.0,
    ]);
});

function conditionalStoryWeek(Save $save): void
{
    app(WeekResolver::class)->resolve($save->refresh());
}

/**
 * @return Collection<int, ConditionalStoryTrigger>
 */
function conditionalTriggers(ConditionalStory $story): Collection
{
    return ConditionalStoryTrigger::where('story', $story)->orderBy('id')->get()->toBase();
}

function conditionalSummary(MatchSlot $slot): string
{
    $event = NewsEvent::where('dedupe_key', "slot:{$slot->id}")->where('type', NewsEventType::MatchResult)->sole();

    return app(NewsTemplates::class)->summary($event->type, $event->payload);
}

/**
 * A heated rivalry, kept apart this week with a Continue Rivalry promo on
 * the player's card.
 */
function conditionalRivalry(Save $save, string $helper, string $rival, int $heat = 70): Rivalry
{
    $rivalry = app(RivalryService::class)->start($save, GameBuilder::wrestler($save, $helper)->id, GameBuilder::wrestler($save, $rival)->id, 1);
    $rivalry->update(['heat' => $heat]);

    app(PromoService::class)->book(
        Card::show($save),
        1,
        PromoType::where('key', PromoTypeKey::ContinueRivalry)->sole(),
        GameBuilder::wrestler($save, $helper)->id,
        GameBuilder::wrestler($save, $rival)->id,
    );

    return $rivalry->refresh();
}

describe("Hornswoggle's shillelagh", function () {
    it('credits Hornswoggle when Finlay wins, without touching the result', function () {
        $save = GameBuilder::inSeason(raw: ['Fit Finlay', 'Hornswoggle', 'Psicosis'], smackdown: ['Batista']);
        $slot = Card::book($save, Card::show($save), 1, Card::singles(), [['Fit Finlay'], ['Psicosis']]);
        FixedOutcomes::queue('Fit Finlay');

        conditionalStoryWeek($save);

        $trigger = conditionalTriggers(ConditionalStory::HornswoggleShillelagh)->sole();
        expect($trigger->outcome)->toBe(ConditionalStoryOutcome::Success)
            ->and($trigger->tokens['title'])->toBe('Hornswoggle to the rescue!')
            ->and($trigger->tokens['text'])->toContain("Fit Finlay's shillelagh")
            ->and(conditionalSummary($slot))->toContain('Hornswoggle slid out from under the ring')
            ->and($slot->refresh()->result->booked)->toBeFalse();
    });

    it("doesn't happen when Finlay loses, or Hornswoggle is hurt, in the match or on the other brand", function (string $case) {
        $save = match ($case) {
            'other brand' => GameBuilder::inSeason(raw: ['Fit Finlay', 'Psicosis'], smackdown: ['Batista', 'Hornswoggle']),
            default => GameBuilder::inSeason(raw: ['Fit Finlay', 'Hornswoggle', 'Psicosis'], smackdown: ['Batista']),
        };
        $sides = $case === 'in the match' ? [['Fit Finlay', 'Hornswoggle'], ['Psicosis', 'Fit Finlay']] : [['Fit Finlay'], ['Psicosis']];

        if ($case === 'in the match') {
            $save = GameBuilder::inSeason(raw: ['Fit Finlay', 'Hornswoggle', 'Psicosis', 'Tajiri'], smackdown: ['Batista']);
            $sides = [['Fit Finlay', 'Hornswoggle'], ['Psicosis', 'Tajiri']];
        }

        if ($case === 'hurt') {
            app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Hornswoggle'), InjurySeverity::Serious, 1, $save->rng('inj.hornswoggle'));
        }

        Card::book($save, Card::show($save), 1, count($sides[0]) === 2 ? Card::type('tag_team', 'Normal Tag') : Card::singles(), $sides);
        FixedOutcomes::queue($case === 'loses' ? 'Psicosis' : 'Fit Finlay');

        conditionalStoryWeek($save);

        expect(conditionalTriggers(ConditionalStory::HornswoggleShillelagh))->toBeEmpty();
    })->with(['loses', 'hurt', 'in the match', 'other brand']);

    it('locks out for 8 weeks once it happens', function (int $weeksAgo, bool $happens) {
        $save = GameBuilder::inSeason(raw: ['Fit Finlay', 'Hornswoggle', 'Psicosis'], smackdown: ['Batista']);
        $slot = Card::book($save, Card::show($save), 1, Card::singles(), [['Fit Finlay'], ['Psicosis']]);
        $save->update(['current_week' => 12]);
        ConditionalStoryTrigger::create([
            'save_id' => $save->id, 'story' => ConditionalStory::HornswoggleShillelagh, 'outcome' => ConditionalStoryOutcome::Success,
            'season' => 1, 'week_number' => 12 - $weeksAgo, 'match_slot_id' => $slot->id, 'tokens' => [], 'seen_at' => now(),
        ]);
        Card::book($save->refresh(), Card::show($save), 1, Card::singles(), [['Fit Finlay'], ['Psicosis']]);
        FixedOutcomes::queue('Fit Finlay');

        conditionalStoryWeek($save);

        expect(conditionalTriggers(ConditionalStory::HornswoggleShillelagh)->where('week_number', 12))->toHaveCount($happens ? 1 : 0);
    })->with([
        'week 4 → week 11 locked' => [7, false],
        'week 4 → week 12 open' => [8, true],
    ]);
});

describe("Undertaker's lights-out", function () {
    it('makes the rival lose, decisively, and says how', function () {
        $save = GameBuilder::inSeason(raw: ['Undertaker', 'Randy Orton', 'Rey Mysterio'], smackdown: ['Batista']);
        $rivalry = conditionalRivalry($save, 'Undertaker', 'Randy Orton');
        $slot = Card::book($save, Card::show($save), 1, Card::singles(), [['Randy Orton'], ['Rey Mysterio']]);
        // A scripted Orton win: the story overrides it.
        FixedOutcomes::queue('Randy Orton');

        conditionalStoryWeek($save);

        $result = $slot->refresh()->result;
        $trigger = conditionalTriggers(ConditionalStory::UndertakerLightsOut)->sole();
        expect($result->winner_side)->toBe(1)
            ->and($result->booked)->toBeFalse()
            ->and($result->method)->not->toBe(MatchMethod::Dq)
            ->and($trigger->outcome)->toBe(ConditionalStoryOutcome::Success)
            ->and($trigger->rivalry_id)->toBe($rivalry->id)
            ->and($trigger->tokens['text'])->toContain('the arena went dark for about 10 seconds')
            ->and($trigger->tokens['text'])->toMatch('/^With the referee (down in the corner|distracted by Rey Mysterio),/')
            ->and($trigger->tokens['text'])->toMatch('/(Chokeslam|Tombstone Piledriver)/')
            ->and(conditionalSummary($slot))->toContain('vowed to get revenge on Undertaker');
    });

    it('lets the rival win on a miss, and tries again in the next match', function () {
        config(['gm.stories.lights_out.win_chance' => 0.0]);
        $save = GameBuilder::inSeason(raw: ['Undertaker', 'Randy Orton', 'Rey Mysterio', 'Edge'], smackdown: ['Batista']);
        conditionalRivalry($save, 'Undertaker', 'Randy Orton');
        $show = Card::show($save);
        $first = Card::book($save, $show, 1, Card::singles(), [['Randy Orton'], ['Rey Mysterio']]);
        Card::book($save, $show, 2, Card::singles(), [['Edge'], ['Randy Orton']]);

        conditionalStoryWeek($save);

        expect($first->refresh()->result->winner_side)->toBe(0)
            ->and(conditionalTriggers(ConditionalStory::UndertakerLightsOut)->pluck('outcome')->all())->toBe([ConditionalStoryOutcome::Failure, ConditionalStoryOutcome::Failure])
            ->and(conditionalTriggers(ConditionalStory::UndertakerLightsOut)->first()->tokens['title'])->toBe('Lights out, but not this time');
    });

    it('never happens again in a rivalry once it has succeeded', function () {
        $save = GameBuilder::inSeason(raw: ['Undertaker', 'Randy Orton', 'Rey Mysterio', 'Edge'], smackdown: ['Batista']);
        conditionalRivalry($save, 'Undertaker', 'Randy Orton');
        $show = Card::show($save);
        Card::book($save, $show, 1, Card::singles(), [['Randy Orton'], ['Rey Mysterio']]);
        Card::book($save, $show, 2, Card::singles(), [['Edge'], ['Randy Orton']]);

        conditionalStoryWeek($save);

        expect(conditionalTriggers(ConditionalStory::UndertakerLightsOut))->toHaveCount(1);
    });

    it("doesn't happen below 60 heat, without a Continue Rivalry, when they meet this week, or in a Steel Cage", function (string $case) {
        $save = GameBuilder::inSeason(raw: ['Undertaker', 'Randy Orton', 'Rey Mysterio', 'Edge'], smackdown: ['Batista']);
        $show = Card::show($save);

        if ($case === 'no promo') {
            app(RivalryService::class)->start($save, GameBuilder::wrestler($save, 'Undertaker')->id, GameBuilder::wrestler($save, 'Randy Orton')->id, 1)->update(['heat' => 70]);
        } else {
            conditionalRivalry($save, 'Undertaker', 'Randy Orton', $case === 'heat 59' ? 59 : 70);
        }

        $type = $case === 'steel cage' ? Card::type('steel_cage', 'Single') : Card::singles();
        Card::book($save, $show, 1, $type, [['Randy Orton'], ['Rey Mysterio']]);

        if ($case === 'they meet') {
            Card::book($save, $show, 2, Card::singles(), [['Undertaker'], ['Randy Orton']]);
        }

        conditionalStoryWeek($save);

        expect(conditionalTriggers(ConditionalStory::UndertakerLightsOut))->toBeEmpty();
    })->with(['heat 59', 'no promo', 'they meet', 'steel cage']);

    it('plays out in the rival’s Gauntlet bout', function () {
        $save = GameBuilder::inSeason(raw: ['Undertaker', 'Randy Orton', 'Rey Mysterio', 'Edge', 'Kane', 'Carlito', 'Snitsky'], smackdown: ['Batista']);
        conditionalRivalry($save, 'Undertaker', 'Randy Orton');
        $slot = Card::book($save, Card::show($save), 1, Card::type('gauntlet', '6-Man Gauntlet'), [['Randy Orton'], ['Rey Mysterio'], ['Edge'], ['Kane'], ['Carlito'], ['Snitsky']]);

        conditionalStoryWeek($save);

        $first = GauntletBout::where('match_slot_id', $slot->id)->where('round', 1)->sole();
        expect($first->winner_side)->toBe(1)
            ->and($first->booked)->toBeFalse()
            ->and(conditionalTriggers(ConditionalStory::UndertakerLightsOut)->sole()->tokens['what_changed'])->toContain('eliminated in bout 1');
    });
});

describe("Kane's pyro", function () {
    it('distracts the rival into a loss', function () {
        $save = GameBuilder::inSeason(raw: ['Kane', 'Edge', 'Rey Mysterio'], smackdown: ['Batista']);
        conditionalRivalry($save, 'Kane', 'Edge');
        $slot = Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Rey Mysterio']]);

        conditionalStoryWeek($save);

        expect($slot->refresh()->result->winner_side)->toBe(1)
            ->and(conditionalTriggers(ConditionalStory::KanePyro)->sole()->tokens['text'])->toMatch('/pyro exploded from the (entrance ramp|ring turnbuckles)/');
    });

    it("tells of a rival who wasn't fazed on a miss", function () {
        config(['gm.stories.pyro.win_chance' => 0.0]);
        $save = GameBuilder::inSeason(raw: ['Kane', 'Edge', 'Rey Mysterio'], smackdown: ['Batista']);
        conditionalRivalry($save, 'Kane', 'Edge');
        $slot = Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Rey Mysterio']]);

        conditionalStoryWeek($save);

        expect($slot->refresh()->result->winner_side)->toBe(0)
            ->and(conditionalTriggers(ConditionalStory::KanePyro)->sole()->tokens['text'])->toContain("wasn't fazed by the shenanigans");
    });
});

describe('Dudley Boyz: get the tables!', function () {
    it('puts an opponent through a table and wins, never by DQ', function () {
        $save = GameBuilder::inSeason(raw: ['Bubba Ray Dudley', 'D-Von Dudley', 'Carlito', 'Snitsky'], smackdown: ['Batista']);
        $slot = Card::book($save, Card::show($save), 1, Card::type('tag_team', 'Normal Tag'), [['Carlito', 'Snitsky'], ['Bubba Ray Dudley', 'D-Von Dudley']]);
        FixedOutcomes::queue('Carlito');

        conditionalStoryWeek($save);

        $trigger = conditionalTriggers(ConditionalStory::DudleyTables)->sole();
        expect($slot->refresh()->result->winner_side)->toBe(1)
            ->and($slot->result->method)->not->toBe(MatchMethod::Dq)
            ->and($trigger->outcome)->toBe(ConditionalStoryOutcome::Success)
            ->and($trigger->tokens['text'])->toContain('pulled a table from underneath it')
            ->and($trigger->tokens['text'])->toMatch('/With the referee (down in the corner|distracted by the chaos outside the ring),/');
    });

    it('always wins a Tables match once the spot lands', function () {
        config(['gm.stories.tables.win_chance' => 0.0]);
        $save = GameBuilder::inSeason(raw: ['Bubba Ray Dudley', 'D-Von Dudley', 'Carlito', 'Snitsky'], smackdown: ['Batista']);
        $slot = Card::book($save, Card::show($save), 1, Card::type('table', 'Tornado Tag'), [['Carlito', 'Snitsky'], ['Bubba Ray Dudley', 'D-Von Dudley']]);

        conditionalStoryWeek($save);

        expect($slot->refresh()->result->winner_side)->toBe(1);
    });

    it('cools down 4 weeks after it lands, but tries again after a miss', function (float $land, int $expected) {
        config(['gm.stories.tables.land_chance' => $land]);
        $save = GameBuilder::inSeason(raw: ['Bubba Ray Dudley', 'D-Von Dudley', 'Carlito', 'Snitsky'], smackdown: ['Batista']);
        $show = Card::show($save);
        Card::book($save, $show, 1, Card::type('tag_team', 'Normal Tag'), [['Carlito', 'Snitsky'], ['Bubba Ray Dudley', 'D-Von Dudley']]);
        Card::book($save, $show, 2, Card::type('tag_team', 'Tornado Tag'), [['Bubba Ray Dudley', 'D-Von Dudley'], ['Carlito', 'Snitsky']]);

        conditionalStoryWeek($save);

        expect(conditionalTriggers(ConditionalStory::DudleyTables))->toHaveCount($expected);
    })->with([
        'lands: once' => [1.0, 1],
        'misses: every match' => [0.0, 2],
    ]);

    it("doesn't happen once the Dudley Boyz have disbanded", function () {
        $save = GameBuilder::inSeason(raw: ['Bubba Ray Dudley', 'D-Von Dudley', 'Carlito', 'Snitsky'], smackdown: ['Batista']);
        app(FactionService::class)->disband($save, SaveFaction::where('save_id', $save->id)->where('faction_name', 'Dudley Boyz')->sole());
        Card::book($save, Card::show($save), 1, Card::type('tag_team', 'Normal Tag'), [['Carlito', 'Snitsky'], ['Bubba Ray Dudley', 'D-Von Dudley']]);

        conditionalStoryWeek($save);

        expect(conditionalTriggers(ConditionalStory::DudleyTables))->toBeEmpty();
    });
});

describe("Team Xtreme's ladder spot", function () {
    it('tells it with Lita when she manages them, and wins a Ladder match outright', function () {
        config(['gm.stories.ladder.win_chance' => 0.0]);
        $save = GameBuilder::inSeason(raw: ['Jeff Hardy', 'Matt Hardy', 'Lita', 'Carlito', 'Snitsky'], smackdown: ['Batista']);
        $slot = Card::book($save, Card::show($save), 1, Card::type('tag_team', 'Normal Tag vs. w/ Manager'), [['Carlito', 'Snitsky'], ['Jeff Hardy', 'Matt Hardy']], managers: [1 => 'Lita']);

        conditionalStoryWeek($save);

        $trigger = conditionalTriggers(ConditionalStory::HardyLadder)->sole();
        expect($trigger->tokens['text'])->toContain('Lita held it steady')
            ->and($trigger->tokens['text'])->toMatch('/(Swanton Bomb|Leg Drop)/');

        $ladder = GameBuilder::inSeason(raw: ['Jeff Hardy', 'Matt Hardy', 'Carlito', 'Snitsky'], smackdown: ['Batista'], seed: 'ladder');
        $match = Card::book($ladder, Card::show($ladder), 1, Card::type('ladder', 'Tornado Tag'), [['Carlito', 'Snitsky'], ['Jeff Hardy', 'Matt Hardy']]);
        conditionalStoryWeek($ladder);

        expect($match->refresh()->result->winner_side)->toBe(1);
    });

    it('tells a fall off the ladder on a miss', function () {
        config(['gm.stories.ladder.land_chance' => 0.0]);
        $save = GameBuilder::inSeason(raw: ['Jeff Hardy', 'Matt Hardy', 'Carlito', 'Snitsky'], smackdown: ['Batista']);
        Card::book($save, Card::show($save), 1, Card::type('tag_team', 'Normal Tag'), [['Carlito', 'Snitsky'], ['Jeff Hardy', 'Matt Hardy']]);

        conditionalStoryWeek($save);

        expect(conditionalTriggers(ConditionalStory::HardyLadder)->sole()->tokens['text'])->toContain("Team Xtreme's big moment never came");
    });
});

describe('the live results pop-up', function () {
    it('shows a story after simulating one match, and not after Book to Win', function (bool $booked) {
        $save = GameBuilder::inSeason(raw: ['Fit Finlay', 'Hornswoggle', 'Psicosis'], smackdown: ['Batista']);
        $slot = Card::book($save, Card::show($save), 1, Card::singles(), [['Fit Finlay'], ['Psicosis']]);
        FixedOutcomes::queue('Fit Finlay');
        app(WeekResolver::class)->begin($save->refresh());
        app(WeekResolver::class)->decide($save->refresh(), $slot->id, $booked ? 0 : null);

        $this->get(route('gm.results', [$save, 1]))
            ->assertInertia(fn (Assert $page) => $page->has('conditional_stories', $booked ? 0 : 1));
    })->with(['simulated' => false, 'Book to Win' => true]);

    it('queues every story from Simulate all in air order, until seen', function () {
        $save = GameBuilder::inSeason(raw: ['Fit Finlay', 'Hornswoggle', 'Psicosis', 'Bubba Ray Dudley', 'D-Von Dudley', 'Carlito', 'Snitsky'], smackdown: ['Batista']);
        $show = Card::show($save);
        Card::book($save, $show, 1, Card::type('tag_team', 'Normal Tag'), [['Carlito', 'Snitsky'], ['Bubba Ray Dudley', 'D-Von Dudley']]);
        Card::book($save, $show, 2, Card::singles(), [['Fit Finlay'], ['Psicosis']]);
        FixedOutcomes::queue('Fit Finlay');
        app(WeekResolver::class)->begin($save->refresh());
        app(WeekResolver::class)->simulateAll($save->refresh());

        $this->get(route('gm.results', [$save, 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('conditional_stories', 2)
                ->where('conditional_stories.0.story', 'dudley_tables')
                ->where('conditional_stories.1.story', 'hornswoggle_shillelagh')
                ->where('conditional_stories.1.title', 'Hornswoggle to the rescue!'));

        foreach (ConditionalStoryTrigger::all() as $trigger) {
            $this->post(route('gm.results.stories.seen', [$save, 1, $trigger]))->assertRedirect();
        }

        $this->get(route('gm.results', [$save, 1]))
            ->assertInertia(fn (Assert $page) => $page->has('conditional_stories', 0));
    });

    it('never pops up after Simulate to here', function () {
        $save = GameBuilder::inSeason(raw: ['Fit Finlay', 'Hornswoggle', 'Psicosis'], smackdown: ['Batista']);
        Card::book($save, Card::show($save), 1, Card::singles(), [['Fit Finlay'], ['Psicosis']]);
        FixedOutcomes::queue('Fit Finlay');

        conditionalStoryWeek($save);

        expect(ConditionalStoryTrigger::count())->toBe(1)
            ->and(ConditionalStoryTrigger::whereNull('seen_at')->count())->toBe(0);
    });
});

it('writes every story as one paragraph of 3–5 sentences (story-text rule)', function () {
    foreach (array_keys(ConditionalStoryTexts::STORIES) as $key) {
        $tokens = collect(['finlay', 'victim', 'rival', 'scorer', 'setter', 'thrower', 'climber', 'partner', 'move', 'location', 'match_word'])
            ->mapWithKeys(fn (string $t): array => [$t => 'Name'])
            ->merge(['opening' => 'With the referee down in the corner,', 'outcome' => 'The Dudleys took the win.'])
            ->merge(collect(['rival', 'setter', 'climber', 'victim'])->flatMap(fn (string $p): array => ["{$p}_he" => 'he', "{$p}_He" => 'He', "{$p}_him" => 'him', "{$p}_his" => 'his']))
            ->all();
        $text = ConditionalStoryTexts::render($key, $tokens);
        $sentences = preg_match_all('/[.!?](\s|$)/', $text);

        expect($text)->not->toContain('{')
            ->and($text)->not->toContain("\n")
            ->and($sentences)->toBeGreaterThanOrEqual(3)
            ->and($sentences)->toBeLessThanOrEqual(5);
    }
});
