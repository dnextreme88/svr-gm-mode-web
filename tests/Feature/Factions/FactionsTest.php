<?php

use App\Enums\Brand;
use App\Enums\FactionOrigin;
use App\Enums\FactionPromptStatus;
use App\Enums\FactionType;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Models\Championship;
use App\Models\Faction;
use App\Models\FactionPrompt;
use App\Models\NewsEvent;
use App\Models\Save;
use App\Models\SaveFaction;
use App\Models\Show;
use App\Models\Wrestler;
use App\Services\Calendar\CalendarService;
use App\Services\Contracts\ContractService;
use App\Services\Factions\FactionNamer;
use App\Services\Factions\FactionService;
use App\Services\Matches\ShowResolver;
use App\Services\Roster\RosterService;
use App\Services\Week\ShowScheduler;
use App\Services\Week\Steps\FactionFormation;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekResolver;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| Factions (house rule): seeded tag teams and stables, named as one when
| they team up, formed by winning 3 times together, and disbanded.
*/

// Whatever a test sets up, no brand may end it over the roster cap (§1).
afterEach(function () {
    foreach (Save::all() as $save) {
        foreach (Brand::cases() as $brand) {
            expect(app(RosterService::class)->rosterCount($save, $brand))->toBeLessThanOrEqual(app(RosterService::class)->cap());
        }
    }
});

function factionWeek(Save $save): void
{
    app(WeekResolver::class)->resolve($save->refresh());
}

/**
 * @param  list<string>  $names
 * @return list<int>
 */
function factionIds(Save $save, array $names): array
{
    return array_map(fn (string $n): int => GameBuilder::wrestler($save, $n)->id, $names);
}

function factionNamed(Save $save, string $name): SaveFaction
{
    return SaveFaction::where('save_id', $save->id)->where('faction_name', $name)->firstOrFail();
}

/**
 * Book the same tag match $times on one show; the first named winner's side
 * wins each time.
 *
 * @param  list<string>  $winners
 * @param  list<string>  $losers
 */
function winTogether(Save $save, Brand $brand, array $winners, array $losers, int $times): void
{
    $show = Card::show($save->refresh(), $brand);

    foreach (range(1, $times) as $position) {
        Card::book($save, $show, $position, Card::type('tag_team', 'Normal Tag'), [$winners, $losers]);
        FixedOutcomes::queue($winners[0]);
    }
}

describe('seed data', function () {
    it('seeds the real-life factions with 2–5 roster members each', function () {
        GameBuilder::seedCatalog();

        $sizes = Faction::withCount('members')->pluck('members_count', 'faction_name');

        expect($sizes)->toHaveCount(count(require database_path('seeders/data/factions.php')))
            ->and($sizes->every(fn (int $n): bool => $n >= 2 && $n <= 5))->toBeTrue()
            ->and(Faction::where('faction_name', 'Team Xtreme')->sole()->members->pluck('name')->all())->toBe(['Matt Hardy', 'Jeff Hardy', 'Lita'])
            ->and(Faction::where('faction_name', 'APA')->sole()->faction_type)->toBe(FactionType::TagTeam)
            ->and(Faction::where('faction_name', 'Nation of Domination')->sole()->members->pluck('name')->all())->toBe(['The Rock', 'Mark Henry', 'Faarooq'])
            ->and(Wrestler::where('name', 'Faarooq')->sole()->roster_type->value)->toBe('legend')
            ->and(Faction::where('faction_name', 'The Hardy Boyz')->exists())->toBeFalse();
    });

    it('copies every seeded faction into a new save, active from week 1', function () {
        $save = GameBuilder::newSave();

        $factions = SaveFaction::with('members')->where('save_id', $save->id)->get();

        expect($factions)->toHaveCount(Faction::count())
            ->and($factions->every(fn (SaveFaction $f): bool => $f->origin === FactionOrigin::Seeded && $f->isActiveAt(1)))->toBeTrue()
            ->and(factionNamed($save, 'Evolution')->members->map->name()->all())->toBe(['Triple H', 'Ric Flair', 'Batista', 'Randy Orton']);
    });
});

describe('naming', function () {
    it('bills teaming members as the faction, picking the closest fit', function () {
        $save = GameBuilder::newSave();
        $namer = app(FactionNamer::class);

        expect($namer->name($save->id, factionIds($save, ['Matt Hardy', 'Jeff Hardy']), 1))->toBe('Team Xtreme')
            ->and($namer->name($save->id, factionIds($save, ['Edge', 'Christian']), 1))->toBe('The Brood')
            ->and($namer->name($save->id, factionIds($save, ['Shelton Benjamin', 'Charlie Haas']), 1))->toBe("The World's Greatest Tag Team")
            ->and($namer->name($save->id, factionIds($save, ['Batista', 'Kane', 'Randy Orton']), 1))->toBe('Evolution & Kane')
            ->and($namer->name($save->id, factionIds($save, ['John Cena', 'Kane']), 1))->toBe('John Cena & Kane')
            ->and($namer->name($save->id, factionIds($save, ['Edge']), 1))->toBe('Edge');
    });

    it('only names a faction from members on the same side', function () {
        $save = GameBuilder::newSave();
        [$matt, $jeff, $edge] = factionIds($save, ['Matt Hardy', 'Jeff Hardy', 'Edge']);

        expect(app(FactionNamer::class)->name($save->id, [$matt, $jeff], 1, [0 => [$matt], 1 => [$jeff], 2 => [$edge]]))->toBe('Matt Hardy & Jeff Hardy');
    });

    it('keeps the name for the weeks a faction was together', function () {
        $save = GameBuilder::newSave();
        $save->update(['current_week' => 10]);
        app(FactionService::class)->disband($save, factionNamed($save, 'Brothers of Destruction'));
        $ids = factionIds($save, ['Undertaker', 'Kane']);

        expect(app(FactionNamer::class)->name($save->id, $ids, 9))->toBe('Brothers of Destruction')
            ->and(app(FactionNamer::class)->name($save->id, $ids, 10))->toBe('Undertaker & Kane');
    });

    it('names the faction in the match news', function () {
        GameBuilder::passiveRival();
        $save = GameBuilder::inSeason(raw: ['Matt Hardy', 'Jeff Hardy', 'Carlito', 'Chris Masters'], smackdown: ['Batista']);
        winTogether($save, Brand::Raw, ['Matt Hardy', 'Jeff Hardy'], ['Carlito', 'Chris Masters'], 1);

        factionWeek($save);

        expect(NewsEvent::where('type', NewsEventType::MatchResult)->where('brand', 'raw')->sole()->rendered_text)
            ->toStartWith('Team Xtreme defeat Carlito & Chris Masters in decisive fashion.');
    });
});

describe('forming', function () {
    it('asks the player to name a team after its 3rd win together', function () {
        GameBuilder::passiveRival();
        $save = GameBuilder::inSeason(raw: ['John Cena', 'Kane', 'Carlito', 'Chris Masters'], smackdown: ['Batista']);
        winTogether($save, Brand::Raw, ['John Cena', 'Kane'], ['Carlito', 'Chris Masters'], 3);

        factionWeek($save);

        $prompt = FactionPrompt::where('save_id', $save->id)->sole();

        expect($prompt->status)->toBe(FactionPromptStatus::Pending)
            ->and($prompt->wins)->toBe(3)
            ->and($prompt->member_ids)->toEqualCanonicalizing(factionIds($save, ['John Cena', 'Kane']))
            ->and(SaveFaction::where('save_id', $save->id)->where('origin', FactionOrigin::Player)->exists())->toBeFalse();
    });

    it('waits for the 3rd win', function () {
        GameBuilder::passiveRival();
        $save = GameBuilder::inSeason(raw: ['John Cena', 'Kane', 'Carlito', 'Chris Masters'], smackdown: ['Batista']);
        winTogether($save, Brand::Raw, ['John Cena', 'Kane'], ['Carlito', 'Chris Masters'], 2);

        factionWeek($save);

        expect(FactionPrompt::where('save_id', $save->id)->exists())->toBeFalse();
    });

    it('forms the rival GM’s teams on the spot with a name from the pool', function () {
        GameBuilder::passiveRival();
        $save = GameBuilder::inSeason(raw: ['John Cena'], smackdown: ['Rey Mysterio', 'Booker T', 'Paul London', 'Brian Kendrick']);
        winTogether($save, Brand::Smackdown, ['Rey Mysterio', 'Booker T'], ['Paul London', 'Brian Kendrick'], 3);

        factionWeek($save);

        $faction = SaveFaction::with('members')->where('save_id', $save->id)->where('origin', FactionOrigin::Rival)->sole();

        expect(config('gm.factions.ai_names'))->toContain($faction->faction_name)
            ->and($faction->faction_type)->toBe(FactionType::TagTeam)
            ->and($faction->brand)->toBe(Brand::Smackdown)
            ->and($faction->formed_week)->toBe(1)
            ->and($faction->date_formed?->toDateString())->toBe(app(CalendarService::class)->showDate(1, Brand::Smackdown)->toDateString())
            ->and(app(FactionService::class)->record($faction))->toBe(['wins' => 3, 'losses' => 0])
            ->and(NewsEvent::where('type', NewsEventType::FactionFormed)->sole()->rendered_text)
            ->toBe("A new force is born: Rey Mysterio and Booker T are now {$faction->faction_name}!")
            ->and(FactionPrompt::where('save_id', $save->id)->exists())->toBeFalse();
    });

    it('skips Divas, and teams that are already a faction', function () {
        GameBuilder::passiveRival();
        $save = GameBuilder::inSeason(raw: ['Matt Hardy', 'Jeff Hardy', 'Carlito', 'Chris Masters', 'Trish Stratus', 'Mickie James', 'Victoria', 'Torrie Wilson'], smackdown: ['Batista']);
        winTogether($save, Brand::Raw, ['Matt Hardy', 'Jeff Hardy'], ['Carlito', 'Chris Masters'], 3);
        $show = Card::show($save, Brand::Raw);
        Card::book($save, $show, 4, Card::type('tag_team', 'Normal Tag'), [['Trish Stratus', 'Mickie James'], ['Victoria', 'Torrie Wilson']]);
        Card::book($save, $show, 5, Card::type('tag_team', 'Normal Tag'), [['Trish Stratus', 'Mickie James'], ['Victoria', 'Torrie Wilson']]);
        Card::book($save, $show, 6, Card::type('tag_team', 'Normal Tag'), [['Trish Stratus', 'Mickie James'], ['Victoria', 'Torrie Wilson']]);
        FixedOutcomes::queue('Trish Stratus')->queue('Trish Stratus')->queue('Trish Stratus');

        factionWeek($save);

        expect(FactionPrompt::where('save_id', $save->id)->exists())->toBeFalse();
    });

    it('asks again after the next win when the player says Not now', function () {
        GameBuilder::passiveRival();
        $save = GameBuilder::inSeason(raw: ['John Cena', 'Kane', 'Carlito', 'Chris Masters'], smackdown: ['Batista']);
        winTogether($save, Brand::Raw, ['John Cena', 'Kane'], ['Carlito', 'Chris Masters'], 3);
        factionWeek($save);
        $prompt = FactionPrompt::where('save_id', $save->id)->sole();

        $this->post(route('gm.factions.prompts.defer', [$save, $prompt]))->assertRedirect();
        expect($prompt->refresh()->status)->toBe(FactionPromptStatus::Deferred);

        // Re-running the step for the same week changes nothing.
        app(FactionFormation::class)->handle(new WeekContext($save->refresh(), 1, 1));
        expect($prompt->refresh()->status)->toBe(FactionPromptStatus::Deferred);

        winTogether($save, Brand::Raw, ['John Cena', 'Kane'], ['Carlito', 'Chris Masters'], 1);
        factionWeek($save);

        expect($prompt->refresh()->status)->toBe(FactionPromptStatus::Pending)
            ->and($prompt->wins)->toBe(4);
    });

    it('forms the player’s faction from the prompt, dated from the deciding show', function () {
        GameBuilder::passiveRival();
        $save = GameBuilder::inSeason(raw: ['John Cena', 'Kane', 'Carlito', 'Chris Masters'], smackdown: ['Batista']);
        winTogether($save, Brand::Raw, ['John Cena', 'Kane'], ['Carlito', 'Chris Masters'], 3);
        factionWeek($save);
        $prompt = FactionPrompt::where('save_id', $save->id)->sole();

        $this->get(route('gm.factions.index', $save))
            ->assertInertia(fn (Assert $page) => $page->where('game.faction_prompt.id', $prompt->id)->where('game.faction_prompt.default_type', 'tag_team'));

        $this->post(route('gm.factions.prompts.form', [$save, $prompt]), ['name' => 'Cenation', 'type' => 'tag_team'])->assertRedirect();

        $faction = factionNamed($save, 'Cenation');

        expect($faction->origin)->toBe(FactionOrigin::Player)
            ->and($faction->formed_week)->toBe(1)
            ->and($faction->date_formed?->toDateString())->toBe(app(CalendarService::class)->showDate(1, Brand::Raw)->toDateString())
            ->and($prompt->refresh()->status)->toBe(FactionPromptStatus::Formed)
            ->and(app(FactionService::class)->record($faction))->toBe(['wins' => 3, 'losses' => 0])
            ->and(NewsEvent::where('type', NewsEventType::FactionFormed)->sole()->week_number)->toBe(1)
            ->and(app(FactionNamer::class)->name($save->id, factionIds($save, ['John Cena', 'Kane']), 2))->toBe('Cenation');

        $this->get(route('gm.factions.index', $save))
            ->assertInertia(fn (Assert $page) => $page->where('game.faction_prompt', null));
    });

    it('refuses a name another faction already has', function () {
        GameBuilder::passiveRival();
        $save = GameBuilder::inSeason(raw: ['John Cena', 'Kane', 'Carlito', 'Chris Masters'], smackdown: ['Batista']);
        winTogether($save, Brand::Raw, ['John Cena', 'Kane'], ['Carlito', 'Chris Masters'], 3);
        factionWeek($save);
        $prompt = FactionPrompt::where('save_id', $save->id)->sole();

        $this->post(route('gm.factions.prompts.form', [$save, $prompt]), ['name' => 'evolution', 'type' => 'stable'])
            ->assertSessionHasErrors('game');

        expect($prompt->refresh()->status)->toBe(FactionPromptStatus::Pending);
    });
});

describe('disbanding', function () {
    it('disbands an active faction from the current week and makes the news', function () {
        $save = GameBuilder::inSeason(raw: ['Matt Hardy', 'Jeff Hardy'], smackdown: ['Batista']);
        $faction = factionNamed($save, 'Team Xtreme');

        $this->post(route('gm.factions.disband', [$save, $faction]))->assertRedirect(route('gm.factions.index', $save));

        expect($faction->refresh()->disbanded_week)->toBe(1)
            ->and($faction->date_disbanded?->toDateString())->toBe('2005-04-03')
            ->and(NewsEvent::where('type', NewsEventType::FactionDisbanded)->sole()->rendered_text)->toBe('Team Xtreme have gone their separate ways.')
            ->and(app(FactionNamer::class)->name($save->id, factionIds($save, ['Matt Hardy', 'Jeff Hardy']), 1))->toBe('Matt Hardy & Jeff Hardy');

        $this->post(route('gm.factions.disband', [$save, $faction]))->assertSessionHasErrors('game');
    });

    it('lists active and disbanded factions on the Factions page', function () {
        $save = GameBuilder::inSeason(raw: ['Matt Hardy', 'Jeff Hardy'], smackdown: ['Batista']);
        app(FactionService::class)->disband($save, factionNamed($save, 'The Brood'));

        $this->get(route('gm.factions.index', $save))
            ->assertInertia(fn (Assert $page) => $page
                ->component('gm/factions')
                ->has('factions', Faction::count())
                ->where('factions', fn ($rows) => collect($rows)->firstWhere('name', 'Team Xtreme')['formed'] === 'Jan 17, 2000'
                    && collect($rows)->firstWhere('name', 'Team Xtreme')['members'][0]['brand'] === 'raw'
                    && collect($rows)->firstWhere('name', 'The Brood')['active'] === false));
    });
});

describe('title news', function () {
    it('gives a faction that wins a tag title its own headline in place of the title change', function () {
        GameBuilder::passiveRival();
        $save = GameBuilder::inSeason(raw: ['Matt Hardy', 'Jeff Hardy', 'Carlito', 'Chris Masters'], smackdown: ['Batista']);
        GameBuilder::giveTitle($save, 'world_tag_team', ['Carlito', 'Chris Masters']);
        $title = Championship::where('key', 'world_tag_team')->sole();
        Card::book($save, Card::show($save), 1, Card::type('tag_team', 'Normal Tag'), [['Matt Hardy', 'Jeff Hardy'], ['Carlito', 'Chris Masters']], titleId: $title->id);
        FixedOutcomes::queue('Matt Hardy');

        factionWeek($save);

        $news = NewsEvent::where('type', NewsEventType::FactionTitleWon)->sole();

        expect($news->tier)->toBe(NewsTier::Headline)
            ->and($news->rendered_text)->toBe('NEW CHAMPIONS! Team Xtreme defeat Carlito & Chris Masters for the World Tag Team Championship via pinfall!')
            ->and(NewsEvent::where('type', NewsEventType::TitleChange)->exists())->toBeFalse();
    });

    it('tells the story of a faction losing its tag title', function () {
        GameBuilder::passiveRival();
        $save = GameBuilder::inSeason(raw: ['Matt Hardy', 'Jeff Hardy', 'Carlito', 'Chris Masters'], smackdown: ['Batista']);
        GameBuilder::giveTitle($save, 'world_tag_team', ['Matt Hardy', 'Jeff Hardy']);
        $title = Championship::where('key', 'world_tag_team')->sole();
        Card::book($save, Card::show($save), 1, Card::type('tag_team', 'Normal Tag'), [['Matt Hardy', 'Jeff Hardy'], ['Carlito', 'Chris Masters']], titleId: $title->id);
        FixedOutcomes::queue('Carlito');

        factionWeek($save);

        expect(NewsEvent::where('type', NewsEventType::FactionTitleLost)->sole()->rendered_text)->toBe('Team Xtreme lose the World Tag Team Championship to Carlito & Chris Masters.')
            ->and(NewsEvent::where('type', NewsEventType::TitleChange)->sole()->rendered_text)->toStartWith('NEW CHAMPION! Carlito & Chris Masters defeats Team Xtreme');
    });
});

it('puts a faction’s interbrand win on the WWE card', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(size: 16);
    $week = 52;
    $save->update(['current_week' => $week]);
    app(ShowScheduler::class)->ensureWeek($save, $week);
    foreach (['Matt Hardy', 'Jeff Hardy'] as $name) {
        app(ContractService::class)->signDrafted($save, GameBuilder::wrestler($save, $name), Brand::Raw, $week);
    }
    $show = Show::where('save_id', $save->id)->where('week_number', $week)->whereNull('brand')->firstOrFail();
    Card::book($save, $show, 1, Card::type('tag_team', 'Normal Tag'), [['Matt Hardy', 'Jeff Hardy'], ['Edge', 'Kane']]);
    FixedOutcomes::queue('Matt Hardy');

    app(ShowResolver::class)->airNext($show, new WeekContext($save->refresh(), $week, 1));

    $news = NewsEvent::where('type', NewsEventType::FactionInterbrandWin)->sole();

    expect($news->brand->value)->toBe('both')
        ->and($news->rendered_text)->toBe('Team Xtreme win one for Raw at WrestleMania!');
});

it('lists the seeded factions on the docs page', function () {
    GameBuilder::seedCatalog();

    $this->get(route('gm.docs'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('factions.seeded', Faction::count())
            ->where('factions.formation_wins', 3));
});
