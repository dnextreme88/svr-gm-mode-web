<?php

use App\Enums\Brand;
use App\Enums\NewsEventType;
use App\Enums\NewsTier;
use App\Enums\SaveStage;
use App\Enums\ShowType;
use App\Models\Season;
use App\Models\Show;
use App\Models\WeekResolution;
use App\Services\News\NewsRecorder;
use App\Services\Ppv\PpvCardService;
use App\Services\Ppv\ReDraftService;
use App\Services\Week\ShowScheduler;
use App\Services\Week\WeekResolver;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| House rule (2026-11-16): a PPV night the player booked opens the News
| before the weekly show, so the weekly show is booked with the PPV's news
| in hand. WrestleMania week has no weekly shows: its main event closes the
| season into the re-draft, then the News, then the Schedule welcomes the
| new season.
*/

const NIGHT_BACKLASH = 4;
const NIGHT_MANIA = 52;

it('opens the News after the player’s PPV night with only that night’s news, then Continue books the weekly show', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian', 'Chris Jericho'], smackdown: ['Batista', 'JBL']);
    $save->update(['current_week' => NIGHT_BACKLASH]);
    app(ShowScheduler::class)->ensureWeek($save, NIGHT_BACKLASH);
    $backlash = Card::show($save, Brand::Raw, ShowType::Ppv, NIGHT_BACKLASH);
    $raw = Card::show($save, Brand::Raw, ShowType::Weekly, NIGHT_BACKLASH);
    Card::book($save, $backlash, 1, Card::singles(), [['Edge'], ['Kane']]);
    FixedOutcomes::queue('Edge')->queue('Christian');

    // News from earlier in the week (a signing, say) waits for the week's end.
    $early = app(NewsRecorder::class)->record($save, NewsEventType::FreeAgentSigned, NewsTier::Story, Brand::Raw, ['wrestler' => 'Christian', 'brand' => 'Raw'], ['wrestler_ids' => []], priority: 10, week: NIGHT_BACKLASH);

    $this->post(route('gm.advance', $save));
    $this->post(route('gm.results.simulate-all', [$save, NIGHT_BACKLASH]));

    $this->get(route('gm.news', $save))->assertInertia(fn (Assert $page) => $page
        ->where('pages.0.week', NIGHT_BACKLASH)
        ->where('continue_to.name', 'Monday Night Raw')
        ->where('continue_to.url', route('gm.card.show', [$save, $raw]))
        ->where('pages.0', function ($week) use ($early) {
            $ids = collect([...array_values(array_filter((array) $week['headlines'])), ...$week['stories']['raw'], ...$week['stories']['smackdown'], ...$week['ticker']])->pluck('id');

            return $ids->isNotEmpty() && ! $ids->contains($early->id);
        }));

    Card::book($save, $raw, 1, Card::singles(), [['Christian'], ['Chris Jericho']]);
    $this->post(route('gm.advance', $save));
    $this->post(route('gm.results.simulate-all', [$save, NIGHT_BACKLASH]));

    // Once the week is in, its page has everything, and Continue opens the inbox.
    expect(WeekResolution::count())->toBe(1);
    $this->get(route('gm.news', $save))->assertInertia(fn (Assert $page) => $page
        ->where('pages.0.week', NIGHT_BACKLASH)
        ->where('continue_to.url', route('gm.email', $save)));
});

it('gives WrestleMania week no weekly shows', function () {
    $save = GameBuilder::inSeason(size: 16);
    $save->update(['current_week' => NIGHT_MANIA]);
    app(ShowScheduler::class)->ensureWeek($save, NIGHT_MANIA);

    expect(Show::where('save_id', $save->id)->where('week_number', NIGHT_MANIA)->pluck('show_type')->all())
        ->toBe([ShowType::Ppv]);
});

it('closes the season into the re-draft on WrestleMania’s main event, then the News, then welcomes the new season', function () {
    $save = GameBuilder::inSeason(size: 16, player: Brand::Smackdown);
    GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
    GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
    Season::where('save_id', $save->id)->update(['royal_rumble_winner_id' => GameBuilder::wrestler($save, 'Edge')->id]);
    $save->update(['current_week' => NIGHT_MANIA]);
    app(ShowScheduler::class)->ensureWeek($save, NIGHT_MANIA);
    $mania = Show::with('ppvEvent')->where('save_id', $save->id)->where('week_number', NIGHT_MANIA)->sole();
    app(PpvCardService::class)->fillSeries($mania, Brand::Smackdown, $save->rng('test.series'));

    $resolver = app(WeekResolver::class);
    $resolver->begin($save->refresh());
    $resolver->simulateAll($save->refresh());

    expect(WeekResolution::where('save_id', $save->id)->where('week_number', NIGHT_MANIA)->exists())->toBeTrue()
        ->and($save->refresh()->stage)->toBe(SaveStage::Redrafting);

    $this->get(route('gm.news', $save))->assertRedirect(route('gm.redraft.show', $save));

    // Every pick, then Continue (what the re-draft page does).
    app(ReDraftService::class)->run($save->refresh());
    expect($save->refresh()->stage)->toBe(SaveStage::InSeason);

    $welcome = route('gm.schedule', ['save' => $save, 'welcome' => 1]);
    $this->get(route('gm.news', $save))->assertInertia(fn (Assert $page) => $page
        ->where('pages.0.week', NIGHT_MANIA)
        ->where('continue_to.name', 'Season 2')
        ->where('continue_to.url', $welcome));

    $this->get($welcome)->assertInertia(fn (Assert $page) => $page->where('welcome_season', 2));
    $this->get(route('gm.schedule', $save))->assertInertia(fn (Assert $page) => $page->where('welcome_season', null));
});
