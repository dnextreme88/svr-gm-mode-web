<?php

use App\Enums\Brand;
use App\Enums\ShowType;
use App\Models\Contract;
use App\Models\Email;
use App\Models\MatchResult;
use App\Models\NewsEvent;
use App\Models\Save;
use App\Models\Show;
use App\Models\WeekResolution;
use App\Services\Week\ShowScheduler;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| Weeks start on Sunday: a PPV week books the PPV card first, then the
| weekly show, and the weekly show's Air button airs both.
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

it('keeps the weekly show closed until the PPV card is confirmed', function () {
    $save = backlashWeek();
    $raw = rawCard($save, ShowType::Weekly);
    $backlash = rawCard($save, ShowType::Ppv);

    bookVia($this, $save, $raw, 'Edge', 'Kane')
        ->assertStatus(422)
        ->assertJsonFragment(['message' => "Book Backlash first. This week's cards are booked in date order."]);

    bookVia($this, $save, $backlash, 'Edge', 'Kane')->assertNoContent();

    $this->post(route('gm.card.confirm', [$save, $backlash]))
        ->assertRedirect(route('gm.card.show', [$save, $raw]));

    bookVia($this, $save, $raw, 'Christian', 'Chris Jericho')->assertNoContent();

    // The confirmed PPV card is read-only until it's unlocked.
    bookVia($this, $save, $backlash, 'Christian', 'Chris Jericho')->assertStatus(422);
    $this->post(route('gm.card.unlock', [$save, $backlash]))->assertRedirect();
    expect($backlash->refresh()->confirmed_at)->toBeNull();
});

it('won’t air the week until every card before the weekly show is confirmed', function () {
    $save = backlashWeek();
    $backlash = rawCard($save, ShowType::Ppv);

    $this->post(route('gm.advance', $save))->assertSessionHasErrors('game');
    expect(WeekResolution::count())->toBe(0);

    $this->post(route('gm.card.confirm', [$save, $backlash]));
    $this->post(route('gm.advance', $save))->assertRedirect(route('gm.results', [$save, BACKLASH]));

    expect(WeekResolution::count())->toBe(1);
});

it('doesn’t confirm the week’s last card: that one airs the week', function () {
    $save = backlashWeek();
    $backlash = rawCard($save, ShowType::Ppv);
    $this->post(route('gm.card.confirm', [$save, $backlash]));

    $this->post(route('gm.card.confirm', [$save, rawCard($save, ShowType::Weekly)]))->assertSessionHasErrors('game');
});

it('airs the Sunday PPV before the weekly show and lists its results first', function () {
    $save = backlashWeek();
    $backlash = rawCard($save, ShowType::Ppv);
    $raw = rawCard($save, ShowType::Weekly);
    Card::book($save, $backlash, 1, Card::singles(), [['Edge'], ['Kane']]);
    Card::book($save, $raw, 1, Card::singles(), [['Christian'], ['Chris Jericho']]);
    FixedOutcomes::queue('Edge')->queue('Christian');
    $this->post(route('gm.card.confirm', [$save, $backlash]));

    $this->post(route('gm.advance', $save));

    $ppvResult = MatchResult::whereHas('matchSlot', fn ($q) => $q->where('show_id', $backlash->id))->sole();
    $rawResult = MatchResult::whereHas('matchSlot', fn ($q) => $q->where('show_id', $raw->id))->sole();

    expect($ppvResult->id)->toBeLessThan($rawResult->id);

    $this->get(route('gm.results', [$save, BACKLASH]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('shows.0.name', 'Backlash')
            ->where('shows.1.name', 'Monday Night Raw'));
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
            ->where('shows.0.name', 'Friday Night SmackDown!')
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
