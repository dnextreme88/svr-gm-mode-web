<?php

use App\Enums\Brand;
use App\Enums\ShowStatus;
use App\Enums\ShowType;
use App\Models\Championship;
use App\Models\Contract;
use App\Models\Email;
use App\Models\MatchResult;
use App\Models\MatchSlot;
use App\Models\NewsEvent;
use App\Models\Save;
use App\Models\Show;
use App\Models\WeekResolution;
use App\Services\Titles\TitleShotService;
use App\Services\Week\ShowScheduler;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| Weeks start on Sunday: a PPV week books and airs the PPV night first, then
| (house rule, 2026-11-01) the weekly show is booked with the PPV's results
| in hand, and its Air button airs the rest of the week.
*/

const BACKLASH = 4;

function backlashWeek(): Save
{
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian', 'Chris Jericho'], smackdown: ['Batista', 'JBL']);
    $save->update(['current_week' => BACKLASH]);
    app(ShowScheduler::class)->ensureWeek($save, BACKLASH);

    return $save->refresh();
}

function rawCard(Save $save, ShowType $type): Show
{
    return Card::show($save, Brand::Raw, $type, BACKLASH);
}

function bookVia($test, Save $save, Show $show, string $a, string $b)
{
    return $test->putJson(route('gm.card.book', [$save, $show, 1]), [
        'match_type_id' => Card::singles()->id,
        'sides' => [[GameBuilder::wrestler($save, $a)->id], [GameBuilder::wrestler($save, $b)->id]],
    ]);
}

it('keeps the weekly show closed until the PPV has aired', function () {
    $save = backlashWeek();
    $raw = rawCard($save, ShowType::Weekly);
    $backlash = rawCard($save, ShowType::Ppv);

    bookVia($this, $save, $raw, 'Edge', 'Kane')
        ->assertStatus(422)
        ->assertJsonFragment(['message' => "Air Backlash first. The weekly show is booked once the PPV's results are in."]);

    bookVia($this, $save, $backlash, 'Edge', 'Kane')->assertNoContent();
    $this->post(route('gm.advance', $save))->assertRedirect(route('gm.results', [$save, BACKLASH]));
    $this->post(route('gm.results.simulate-all', [$save, BACKLASH]));

    bookVia($this, $save, $raw, 'Christian', 'Chris Jericho')->assertNoContent();

    // The aired PPV card can't change, or be unlocked.
    bookVia($this, $save, $backlash, 'Christian', 'Chris Jericho')->assertStatus(422);
    $this->post(route('gm.card.unlock', [$save, $backlash]))->assertSessionHasErrors('game');
});

it('doesn’t confirm a night’s last card: that one airs the night', function () {
    $save = backlashWeek();

    $this->post(route('gm.card.confirm', [$save, rawCard($save, ShowType::Ppv)]))->assertSessionHasErrors('game');
});

it('airs the PPV night, waits for the weekly show, then airs the rest of the week', function () {
    $save = backlashWeek();
    $backlash = rawCard($save, ShowType::Ppv);
    $raw = rawCard($save, ShowType::Weekly);
    $smackdown = Card::show($save, Brand::Smackdown, ShowType::Weekly, BACKLASH);
    Card::book($save, $backlash, 1, Card::singles(), [['Edge'], ['Kane']]);
    FixedOutcomes::queue('Edge')->queue('Christian');

    $this->post(route('gm.advance', $save));
    $this->post(route('gm.results.simulate-all', [$save, BACKLASH]));

    // The PPV is in; the week waits for the weekly show.
    expect(WeekResolution::count())->toBe(0)
        ->and($backlash->refresh()->status)->toBe(ShowStatus::Aired)
        ->and($raw->refresh()->status)->toBe(ShowStatus::Booking)
        // The rival books its weekly show after the PPV, too.
        ->and(MatchSlot::where('show_id', $smackdown->id)->whereNotNull('match_type_id')->count())->toBe(0);

    $this->get(route('gm.results', [$save, BACKLASH]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('airing', false)
            ->where('continue_to.name', 'Monday Night Raw')
            ->where('continue_to.url', route('gm.card.show', [$save, $raw]))
            ->where('shows', fn ($shows) => collect($shows)->pluck('name')->all() === ['Backlash']));

    Card::book($save, $raw, 1, Card::singles(), [['Christian'], ['Chris Jericho']]);
    $this->post(route('gm.advance', $save))->assertRedirect(route('gm.results', [$save, BACKLASH]));
    $this->post(route('gm.results.simulate-all', [$save, BACKLASH]));

    $ppvResult = MatchResult::whereHas('matchSlot', fn ($q) => $q->where('show_id', $backlash->id))->sole();
    $rawResult = MatchResult::whereHas('matchSlot', fn ($q) => $q->where('show_id', $raw->id))->sole();

    expect(WeekResolution::count())->toBe(1)
        ->and($ppvResult->id)->toBeLessThan($rawResult->id)
        ->and(MatchSlot::where('show_id', $smackdown->id)->whereNotNull('match_type_id')->count())->toBeGreaterThan(0);

    $this->get(route('gm.results', [$save, BACKLASH]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('continue_to', null)
            ->where('shows.0.name', 'Backlash')
            ->where('shows.1.name', 'Monday Night Raw'));
});

it('finishes a week waiting for its weekly show when simulating past it', function () {
    $save = backlashWeek();
    Card::book($save, rawCard($save, ShowType::Ppv), 1, Card::singles(), [['Edge'], ['Kane']]);
    $this->post(route('gm.advance', $save));
    $this->post(route('gm.results.simulate-all', [$save, BACKLASH]));

    $this->post(route('gm.simulate', $save), ['week' => BACKLASH + 1]);

    expect(WeekResolution::where('week_number', BACKLASH)->exists())->toBeTrue()
        ->and(MatchResult::whereHas('matchSlot', fn ($q) => $q->where('show_id', rawCard($save, ShowType::Weekly)->id))->count())->toBeGreaterThan(0);
});

it('makes a title shot won at a PPV due on that week’s weekly show', function () {
    $save = backlashWeek();
    $slot = Card::book($save, rawCard($save, ShowType::Ppv), 1, Card::singles(), [['Edge'], ['Kane']]);
    $title = Championship::where('key', 'wwe')->firstOrFail();
    $shots = app(TitleShotService::class);

    expect($shots->create($save, $title, [GameBuilder::wrestler($save, 'Edge')->id], [GameBuilder::wrestler($save, 'Kane')->id], $slot, BACKLASH)->due_week)->toBe(BACKLASH);

    $weekly = Card::book($save, Card::show($save, Brand::Raw, ShowType::Weekly, 1), 1, Card::singles(), [['Christian'], ['Chris Jericho']]);

    expect($shots->create($save, $title, [GameBuilder::wrestler($save, 'Christian')->id], [GameBuilder::wrestler($save, 'Kane')->id], $weekly, 1)->due_week)->toBe(2);
});

it('shows the rival’s results from the calendar', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista', 'JBL']);
    $smackdown = Card::show($save, Brand::Smackdown);
    Card::book($save, $smackdown, 1, Card::singles(), [['Batista'], ['JBL']]);
    FixedOutcomes::queue('Batista');
    $this->post(route('gm.advance', $save));

    $this->get(route('gm.results', ['save' => $save, 'week' => 1, 'brand' => 'smackdown', 'show' => $smackdown->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('brand', 'smackdown')
            ->where('focus_show_id', $smackdown->id)
            ->where('from_schedule', true)
            ->where('shows.0.name', 'Friday Night Smackdown')
            ->where('shows.0.outcomes.0.winners', ['Batista']));
});

describe('the calendar', function () {
    it('opens on the current month and says what each show night does', function () {
        $save = backlashWeek();
        $backlash = rawCard($save, ShowType::Ppv);

        $this->get(route('gm.schedule', $save))
            ->assertInertia(fn (Assert $page) => $page
                ->component('gm/schedule')
                ->where('initial_month', '2005-04')
                ->where('months', function ($months) use ($backlash) {
                    $airs = collect($months)->flatMap(fn ($m) => collect($m['rows'])->flatten(1)->where('in_month', true))->keyBy('date')->map(fn ($d) => collect($d['airs']));

                    return $airs['2005-04-24']->first()['state'] === 'open'
                        && $airs['2005-04-24']->first()['card_url'] === route('gm.card.show', [$backlash->save_id, $backlash])
                        && $airs['2005-04-25']->first()['state'] === 'waiting'
                        && $airs['2005-04-29']->first()['state'] === 'rival'
                        && $airs['2005-04-29']->first()['mine'] === false
                        && $airs['2005-04-26']->isEmpty()
                        && $airs['2005-05-02']->first()['can_simulate'] === true;
                }));
    });
});

describe('simulation targets on the calendar', function () {
    /**
     * Each future show night's `can_simulate` flag, keyed by date and brand.
     */
    function simulateFlags(Save $save, int $season = 1): array
    {
        $flags = [];

        test()->get(route('gm.schedule', $save))->assertInertia(function (Assert $page) use (&$flags) {
            foreach ($page->toArray()['props']['months'] as $month) {
                foreach ($month['rows'] as $row) {
                    foreach ($row as $day) {
                        foreach ($day['airs'] as $air) {
                            $flags[$day['date'].' '.($air['ppv']['name'] ?? $air['brand'])] = $air['can_simulate'];
                        }
                    }
                }
            }
        });

        return $flags;
    }

    it('offers only the player’s own show nights, never the rival’s', function (Brand $player, array $yes, array $no) {
        $save = GameBuilder::inSeason(player: $player);
        $flags = simulateFlags($save);

        foreach ($yes as $key) {
            expect($flags[$key])->toBeTrue("{$key} should be a simulation target");
        }

        foreach ($no as $key) {
            expect($flags[$key])->toBeFalse("{$key} shouldn't be a simulation target");
        }
    })->with([
        'Raw GM' => [
            Brand::Raw,
            // Own weekly show, own PPV (Backlash), shared PPV (SummerSlam).
            ['2005-04-11 raw', '2005-04-24 Backlash', '2005-08-21 SummerSlam'],
            // Rival weekly show and SmackDown-exclusive PPVs.
            ['2005-04-15 smackdown', '2005-05-22 Judgment Day', '2005-07-24 The Great American Bash'],
        ],
        'SmackDown GM' => [
            Brand::Smackdown,
            ['2005-04-15 smackdown', '2005-05-22 Judgment Day', '2006-03-26 WrestleMania'],
            ['2005-04-11 raw', '2005-04-24 Backlash', '2005-06-26 Vengeance'],
        ],
    ]);

    it('doesn’t offer this week or earlier weeks', function () {
        $save = GameBuilder::inSeason();
        $flags = simulateFlags($save);

        expect($flags['2005-04-04 raw'])->toBeFalse();
    });
});

describe('simulate to a date', function () {
    it('books and airs every week before the target, as if the GM had', function () {
        $save = GameBuilder::inSeason();
        $contract = Contract::where('save_id', $save->id)->where('brand', Brand::Raw)->orderBy('id')->firstOrFail();
        $weeksBefore = $contract->weeks_remaining;

        $this->post(route('gm.simulate', $save), ['week' => BACKLASH])->assertRedirect(route('gm.schedule', $save));

        $save->refresh();
        $rawShows = Show::where('save_id', $save->id)->where('brand', Brand::Raw)->where('week_number', '<', BACKLASH)->pluck('id');

        expect($save->current_week)->toBe(BACKLASH)
            ->and(WeekResolution::where('save_id', $save->id)->pluck('week_number')->all())->toBe([1, 2, 3])
            // Every one of the player's weekly shows had a full card booked for them.
            ->and($rawShows->every(fn ($id) => MatchResult::whereHas('matchSlot', fn ($q) => $q->where('show_id', $id))->count() > 0))->toBeTrue()
            ->and($contract->refresh()->weeks_remaining)->toBe($weeksBefore - 3)
            ->and(NewsEvent::where('save_id', $save->id)->where('type', 'match_result')->count())->toBeGreaterThan(0)
            ->and(Email::where('save_id', $save->id)->where('brand', Brand::Raw)->count())->toBeGreaterThan(0)
            ->and(session('inertia.flash_data.toast.type') ?? 'success')->toBe('success');
    });

    it('leaves the target week for the player to book', function () {
        $save = GameBuilder::inSeason();

        $this->post(route('gm.simulate', $save), ['week' => 2]);

        expect(Show::where('save_id', $save->id)->where('week_number', 2)->whereNotNull('confirmed_at')->count())->toBe(0)
            ->and(WeekResolution::where('save_id', $save->id)->where('week_number', 2)->exists())->toBeFalse();
    });

    it('steps one week at a time so the schedule can show progress, then reports like a full run', function () {
        $save = GameBuilder::inSeason();
        $steps = [];

        do {
            $step = $this->postJson(route('gm.simulate.step', $save), ['week' => BACKLASH])->assertOk()->json();
            $steps[] = $step;
        } while (! $step['done']);

        expect(array_column($steps, 'week'))->toBe([1, 2, 3])
            ->and(array_column($steps, 'done'))->toBe([false, false, true])
            ->and(array_column($steps, 'stopped'))->toBe([null, null, null])
            ->and($save->refresh()->current_week)->toBe(BACKLASH)
            ->and(WeekResolution::where('save_id', $save->id)->pluck('week_number')->all())->toBe([1, 2, 3]);

        // Once there, a further step does nothing.
        $this->postJson(route('gm.simulate.step', $save), ['week' => BACKLASH])
            ->assertJson(['week' => null, 'done' => true]);

        $this->post(route('gm.simulate.finish', $save), ['week' => BACKLASH, 'weeks' => [1, 2, 3]])
            ->assertRedirect(route('gm.schedule', $save));
        expect(session('inertia.flash_data.toast.message') ?? '')->toContain('Simulated weeks 1–3.');
    });

    it('refuses a step backwards or past the season, as JSON', function () {
        $save = GameBuilder::inSeason();
        $save->update(['current_week' => 3]);

        $this->postJson(route('gm.simulate.step', $save), ['week' => 53])->assertStatus(422);
        expect(WeekResolution::count())->toBe(0);
    });

    it('only simulates forward, within the season', function (int $target) {
        $save = GameBuilder::inSeason();
        $save->update(['current_week' => 3]);

        $this->post(route('gm.simulate', $save), ['week' => $target])->assertSessionHasErrors('game');
        expect(WeekResolution::count())->toBe(0);
    })->with([
        'this week' => [3],
        'the past' => [2],
        'next season' => [53],
    ]);
});
