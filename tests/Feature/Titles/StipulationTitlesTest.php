<?php

use App\Enums\Brand;
use App\Enums\ContendershipVoidReason;
use App\Enums\InjurySeverity;
use App\Enums\MatchMethod;
use App\Enums\Stipulation;
use App\Enums\TitleShotKind;
use App\Enums\TitleShotStatus;
use App\Enums\TitleVacancyPromptStatus;
use App\Enums\WonVia;
use App\Exceptions\GameRuleException;
use App\Models\Championship;
use App\Models\GmNotice;
use App\Models\MatchType;
use App\Models\NewsEvent;
use App\Models\Save;
use App\Models\Show;
use App\Models\TitleShot;
use App\Models\TitleVacancyPrompt;
use App\Services\Card\Booking;
use App\Services\Card\CardRandomizer;
use App\Services\Card\CardService;
use App\Services\Contracts\ContractService;
use App\Services\Injuries\InjuryService;
use App\Services\Titles\ContendershipService;
use App\Services\Titles\LastChanceService;
use App\Services\Titles\TitleService;
use App\Services\Week\WeekResolver;
use App\Support\Rng\SeededRng;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| Title stipulations (house rule, 2026-10-31): Champion vs. Contender for any
| title the champion holds, Winner Takes All and #1 Contender's Match.
*/

const SD_ROSTER = ['Daivari', 'Eugene', 'Jamie Noble', 'Batista', 'Kurt Angle', 'Chris Benoit', 'Booker T', 'Matt Hardy', 'Jeff Hardy', 'Vito'];

function sdSave(): Save
{
    GameBuilder::passiveRival();

    return GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: SD_ROSTER, player: Brand::Smackdown);
}

function sdShow(Save $save): Show
{
    return Card::show($save->refresh(), Brand::Smackdown);
}

function sdTitle(string $key): Championship
{
    return Championship::where('key', $key)->firstOrFail();
}

/**
 * A second (or later) title for someone: the award rule only lets a
 * wrestler hold one title at award time, but champions win more in matches.
 */
function holdAlso(Save $save, string $key, string $name): void
{
    app(TitleService::class)->award($save, sdTitle($key), GameBuilder::wrestler($save, $name), $save->current_week);
}

/**
 * @param  list<list<string>>  $sides
 */
function bookStip(Save $save, MatchType $type, array $sides, ?Stipulation $stipulation, ?string $stipTitle = null, ?string $title = null, int $position = 1): void
{
    $ids = array_map(fn (array $side): array => array_map(fn (string $n): int => GameBuilder::wrestler($save, $n)->id, $side), $sides);

    app(CardService::class)->book(sdShow($save), $position, new Booking(
        $type,
        $ids,
        [],
        $title === null ? null : sdTitle($title)->id,
        $stipulation,
        $stipTitle === null ? null : sdTitle($stipTitle)->id,
    ), Brand::Smackdown);
}

function airTitleWeek(Save $save): void
{
    app(WeekResolver::class)->resolve($save->refresh());
}

/**
 * Daivari holds the World Heavyweight and Cruiserweight titles.
 */
function daivariDoubleChamp(): Save
{
    $save = sdSave();
    GameBuilder::giveTitle($save, 'world_heavyweight', ['Daivari']);
    holdAlso($save, 'cruiserweight', 'Daivari');

    return $save;
}

describe('Champion vs. Contender for any title the champion holds', function () {
    it('lets a double champion put either title up, one at a time', function () {
        $save = daivariDoubleChamp();
        $firstBlood = Card::type('first_blood', 'Single');

        bookStip($save, $firstBlood, [['Daivari'], ['Eugene']], Stipulation::ChampionVsContender, 'world_heavyweight');
        bookStip($save, $firstBlood, [['Daivari'], ['Eugene']], Stipulation::ChampionVsContender, 'cruiserweight');

        expect(sdShow($save)->matchSlots()->where('position', 1)->first()->stipulation_championship_id)->toBe(sdTitle('cruiserweight')->id);
    });

    it('is off when a title is on the line', function () {
        $save = daivariDoubleChamp();

        bookStip($save, Card::type('first_blood', 'Single'), [['Daivari'], ['Eugene']], Stipulation::ChampionVsContender, 'cruiserweight', 'world_heavyweight');
    })->throws(GameRuleException::class, 'title is not on the line');

    it('needs a contender who holds no title', function () {
        $save = daivariDoubleChamp();
        GameBuilder::giveTitle($save, 'united_states', ['Eugene']);

        bookStip($save, Card::singles(), [['Daivari'], ['Eugene']], Stipulation::ChampionVsContender, 'world_heavyweight');
    })->throws(GameRuleException::class, 'needs a contender without a title');

    it('writes news for both results: determined contender, lucky champion', function () {
        $save = daivariDoubleChamp();
        bookStip($save, Card::singles(), [['Daivari'], ['Eugene']], Stipulation::ChampionVsContender, 'cruiserweight');
        FixedOutcomes::queue('Daivari');
        airTitleWeek($save);

        $survived = NewsEvent::where('type', 'champion_survived_challenge')->sole();
        expect($survived->payload['champion'])->toBe('Daivari');

        $save = daivariDoubleChamp();
        bookStip($save, Card::singles(), [['Daivari'], ['Eugene']], Stipulation::ChampionVsContender, 'cruiserweight');
        FixedOutcomes::queue('Eugene');
        airTitleWeek($save);

        $earned = NewsEvent::where('save_id', $save->id)->where('type', 'contender_earned_shot')->sole();
        expect($earned->payload['pronoun'])->toBe('He')
            ->and(TitleShot::where('save_id', $save->id)->sole()->championship_id)->toBe(sdTitle('cruiserweight')->id);
    });
});

describe('Winner Takes All', function () {
    function threeChamps(): Save
    {
        $save = sdSave();
        GameBuilder::giveTitle($save, 'world_heavyweight', ['Daivari']);
        GameBuilder::giveTitle($save, 'united_states', ['Eugene']);
        GameBuilder::giveTitle($save, 'cruiserweight', ['Jamie Noble']);

        return $save;
    }

    it('gives the winner of a triple threat every contested title', function () {
        $save = threeChamps();
        bookStip($save, Card::type('single', 'Normal Triple Threat'), [['Daivari'], ['Eugene'], ['Jamie Noble']], Stipulation::WinnerTakesAll);
        FixedOutcomes::queue('Eugene');

        airTitleWeek($save);

        $titles = app(TitleService::class);
        $eugene = GameBuilder::wrestler($save, 'Eugene');

        expect($titles->titlesHeldBy($eugene))->toEqualCanonicalizing(['World Heavyweight Championship', 'United States Championship', 'Cruiserweight Championship'])
            ->and(NewsEvent::where('type', 'winner_takes_all')->count())->toBe(1)
            ->and(NewsEvent::where('type', 'title_change')->count())->toBe(0);
    });

    it('moves no title on a DQ', function () {
        $save = threeChamps();
        bookStip($save, Card::singles(), [['Daivari'], ['Eugene']], Stipulation::WinnerTakesAll);
        FixedOutcomes::queue('Eugene', MatchMethod::Dq);

        airTitleWeek($save);

        expect(app(TitleService::class)->titlesHeldBy(GameBuilder::wrestler($save, 'Eugene')))->toBe(['United States Championship']);
    });

    it('refuses a non-champion, a 2 vs 2 and a title already on the card', function () {
        $save = threeChamps();

        expect(fn () => bookStip($save, Card::singles(), [['Daivari'], ['Batista']], Stipulation::WinnerTakesAll))
            ->toThrow(GameRuleException::class, 'must be a champion')
            ->and(fn () => bookStip($save, Card::type('tag_team', 'Normal Tag'), [['Daivari', 'Batista'], ['Eugene', 'Kurt Angle']], Stipulation::WinnerTakesAll))
            ->toThrow(GameRuleException::class, '1 vs 1 or a triple threat');

        bookStip($save, Card::singles(), [['Daivari'], ['Batista']], null, null, 'world_heavyweight', 2);

        expect(fn () => bookStip($save, Card::singles(), [['Daivari'], ['Eugene']], Stipulation::WinnerTakesAll))
            ->toThrow(GameRuleException::class, 'already on this card');
    });

    it('draws the celebration or the revenge story by its weights', function () {
        foreach (['celebrate' => ['celebrate' => 100, 'revenge' => 0], 'revenge' => ['celebrate' => 0, 'revenge' => 100]] as $expected => $weights) {
            config(['gm.news.winner_takes_all_variants' => $weights]);
            $save = threeChamps();
            bookStip($save, Card::singles(), [['Daivari'], ['Eugene']], Stipulation::WinnerTakesAll);
            FixedOutcomes::queue('Eugene');
            airTitleWeek($save);

            expect(NewsEvent::where('save_id', $save->id)->where('type', 'winner_takes_all')->sole()->payload['outcome'])->toBe($expected);
        }
    });
});

describe('#1 Contender\'s Match', function () {
    function battleRoyal(): MatchType
    {
        return Card::type('battle_royal', '6-Man');
    }

    it('makes the winner of a 6-man Battle Royal the #1 contender, with no deadline', function () {
        $save = sdSave();
        GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
        bookStip($save, battleRoyal(), [['Kurt Angle'], ['Chris Benoit'], ['Booker T'], ['Matt Hardy'], ['Jeff Hardy'], ['Vito']], Stipulation::NumberOneContender, 'world_heavyweight');
        FixedOutcomes::queue('Vito');

        airTitleWeek($save);

        $shot = TitleShot::sole();
        expect($shot->kind)->toBe(TitleShotKind::NumberOneContender)
            ->and($shot->due_week)->toBeNull()
            ->and($shot->contender_ids)->toBe([GameBuilder::wrestler($save, 'Vito')->id])
            ->and(NewsEvent::where('type', 'number_one_contender')->count())->toBe(1);
    });

    it('can be a Gauntlet, the only stipulation a Gauntlet takes', function () {
        $save = sdSave();
        GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
        $gauntlet = Card::type('gauntlet', '6-Man Gauntlet');

        bookStip($save, $gauntlet, [['Kurt Angle'], ['Chris Benoit'], ['Booker T'], ['Matt Hardy'], ['Jeff Hardy'], ['Vito']], Stipulation::NumberOneContender, 'world_heavyweight');

        expect(fn () => bookStip($save, $gauntlet, [['Kurt Angle'], ['Chris Benoit'], ['Booker T'], ['Matt Hardy'], ['Jeff Hardy'], ['Vito']], Stipulation::LoserLeavesShow))
            ->toThrow(GameRuleException::class, "can't have a Loser Leaves Show stipulation");
    });

    it('keeps the champion of that title out of it', function () {
        $save = sdSave();
        GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);

        bookStip($save, Card::singles(), [['Batista'], ['Vito']], Stipulation::NumberOneContender, 'world_heavyweight');
    })->throws(GameRuleException::class, 'for their own title');

    it('reserves the title match for exactly the contender, then is fulfilled by it', function () {
        $save = sdSave();
        GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
        bookStip($save, Card::singles(), [['Kurt Angle'], ['Vito']], Stipulation::NumberOneContender, 'world_heavyweight');
        FixedOutcomes::queue('Vito');
        airTitleWeek($save);

        expect(fn () => bookStip($save, Card::singles(), [['Batista'], ['Kurt Angle']], null, null, 'world_heavyweight'))
            ->toThrow(GameRuleException::class, 'reserved for its #1 contender')
            ->and(fn () => bookStip($save, Card::type('single', 'Normal Triple Threat'), [['Batista'], ['Vito'], ['Kurt Angle']], null, null, 'world_heavyweight'))
            ->toThrow(GameRuleException::class, 'reserved for its #1 contender')
            ->and(fn () => bookStip($save, Card::singles(), [['Kurt Angle'], ['Chris Benoit']], Stipulation::NumberOneContender, 'world_heavyweight'))
            ->toThrow(GameRuleException::class, 'already has a #1 contender');

        bookStip($save, Card::singles(), [['Batista'], ['Vito']], null, null, 'world_heavyweight');
        FixedOutcomes::queue('Batista');
        airTitleWeek($save);

        expect(TitleShot::sole()->status)->toBe(TitleShotStatus::Fulfilled);
    });

    it('never has Randomize or Preset give the title to anyone else', function () {
        config(['gm.card.randomize_title_chance' => 1.0]);
        $save = sdSave();
        GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
        app(ContendershipService::class)->create($save, sdTitle('world_heavyweight'), [GameBuilder::wrestler($save, 'Vito')->id], [GameBuilder::wrestler($save, 'Batista')->id], sdShow($save)->matchSlots()->first(), 1);
        $vito = GameBuilder::wrestler($save, 'Vito')->id;

        foreach (range(1, 20) as $seed) {
            foreach (app(CardRandomizer::class)->preset(sdShow($save), new SeededRng("no1-{$seed}"), Brand::Smackdown, replace: true)['proposals'] as $proposal) {
                if ($proposal->booking->titleChampionshipId === sdTitle('world_heavyweight')->id) {
                    expect(array_values(array_diff($proposal->booking->competitorIds(), [GameBuilder::wrestler($save, 'Batista')->id])))->toBe([$vito]);
                }
            }
        }
    });

    it('is voided when the contender leaves the brand or is seriously injured, with a notice', function () {
        $save = sdSave();
        GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
        GameBuilder::giveTitle($save, 'united_states', ['Chris Benoit']);
        $slot = sdShow($save)->matchSlots()->first();
        $contenderships = app(ContendershipService::class);
        $contenderships->create($save, sdTitle('world_heavyweight'), [GameBuilder::wrestler($save, 'Vito')->id], [GameBuilder::wrestler($save, 'Batista')->id], $slot, 1);
        $contenderships->create($save, sdTitle('united_states'), [GameBuilder::wrestler($save, 'Kurt Angle')->id], [GameBuilder::wrestler($save, 'Chris Benoit')->id], $slot, 1);

        app(ContractService::class)->release(GameBuilder::wrestler($save, 'Vito'), 1);
        app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Kurt Angle'), InjurySeverity::Serious, 1, $save->rng('inj'));

        expect(TitleShot::orderBy('id')->pluck('void_reason')->all())->toBe([ContendershipVoidReason::Released, ContendershipVoidReason::SeriousInjury])
            ->and(GmNotice::count())->toBe(2)
            ->and(NewsEvent::where('type', 'contendership_voided')->count())->toBe(2);
    });

    it('asks the player to award or forfeit a vacated title, and blocks the week until then', function () {
        $save = sdSave();
        GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
        $contenderships = app(ContendershipService::class);
        $contenderships->create($save, sdTitle('world_heavyweight'), [GameBuilder::wrestler($save, 'Vito')->id], [GameBuilder::wrestler($save, 'Batista')->id], sdShow($save)->matchSlots()->first(), 1);

        app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Batista'), InjurySeverity::Serious, 1, $save->rng('inj'));
        $prompt = TitleVacancyPrompt::sole();

        expect($prompt->status)->toBe(TitleVacancyPromptStatus::Pending)
            ->and(fn () => app(WeekResolver::class)->resolve($save->refresh()))
            ->toThrow(GameRuleException::class, 'goes to its #1 contender');

        $contenderships->award($prompt->refresh(), 1);

        expect(app(TitleService::class)->titlesHeldBy(GameBuilder::wrestler($save, 'Vito')))->toBe(['World Heavyweight Championship'])
            ->and(TitleShot::sole()->status)->toBe(TitleShotStatus::Fulfilled)
            ->and(NewsEvent::where('type', 'contender_awarded_title')->count())->toBe(1);
    });

    it('has the rival award its own vacated title', function () {
        $save = sdSave();
        GameBuilder::giveTitle($save, 'wwe', ['Edge']);
        app(ContendershipService::class)->create($save, sdTitle('wwe'), [GameBuilder::wrestler($save, 'Kane')->id], [GameBuilder::wrestler($save, 'Edge')->id], Card::show($save)->matchSlots()->first(), 1);

        app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Edge'), InjurySeverity::Serious, 1, $save->rng('inj'));
        app(ContendershipService::class)->settleRival($save->refresh(), 1);

        expect(app(TitleService::class)->titlesHeldBy(GameBuilder::wrestler($save, 'Kane')))->toBe(['WWE Championship']);
    });
});

describe('Randomize and a Last Chance loser', function () {
    it('never proposes Vito against Jeff Hardy after losing his Last Chance, until Jeff loses the title', function () {
        config(['gm.card.randomize_title_chance' => 1.0]);
        $save = sdSave();
        $reign = GameBuilder::giveTitle($save, 'world_heavyweight', ['Jeff Hardy']);
        $vito = GameBuilder::wrestler($save, 'Vito')->id;
        app(LastChanceService::class)->lockOut($reign, [$vito], 1, sdShow($save)->matchSlots()->first()->id);

        $vitoChallenges = function () use ($save, $vito): bool {
            foreach (range(1, 25) as $seed) {
                $proposal = app(CardRandomizer::class)->proposeSlot(sdShow($save), 1, Card::singles(), new SeededRng("lc-{$seed}"), [], [], Brand::Smackdown);

                if ($proposal->booking->titleChampionshipId === sdTitle('world_heavyweight')->id && in_array($vito, $proposal->booking->competitorIds(), true)) {
                    return true;
                }
            }

            return false;
        };

        expect($vitoChallenges())->toBeFalse();

        app(TitleService::class)->changeHands($save, sdTitle('world_heavyweight'), GameBuilder::wrestler($save, 'Batista'), 1, WonVia::Match, null, null);

        expect($vitoChallenges())->toBeTrue();
    });
});
