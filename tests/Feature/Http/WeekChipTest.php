<?php

use App\Enums\Brand;
use App\Enums\ShowStatus;
use App\Enums\ShowType;
use App\Models\Show;
use App\Services\Calendar\CalendarService;
use App\Services\Week\BookingOrder;
use App\Services\Week\ShowScheduler;
use App\Services\Week\WeekResolver;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| The header's season/week chip links to the week's card to book, in booking
| order, or to the live results while the week airs.
*/

function chipUrl($test, $save): ?string
{
    $url = null;
    $test->get(route('gm.rosters.index', $save))
        ->assertInertia(function (Assert $page) use (&$url) {
            $url = $page->toArray()['props']['game']['current_card_url'];
        });

    return $url;
}

it('links to the weekly show in a week without a PPV', function () {
    $save = GameBuilder::inSeason();

    expect(chipUrl($this, $save))->toBe(route('gm.card.show', [$save, Card::show($save)]));
});

it('links to the PPV until it has aired, then the weekly show', function () {
    $save = GameBuilder::inSeason();
    $week = app(CalendarService::class)->nextPpv(1, Brand::Raw)['week'];
    $save->update(['current_week' => $week]);
    app(ShowScheduler::class)->ensureWeek($save, $week);
    $ppv = Show::where('save_id', $save->id)->where('week_number', $week)->where('brand', Brand::Raw)->where('show_type', ShowType::Ppv)->sole();
    $weekly = Card::show($save->refresh(), Brand::Raw, ShowType::Weekly, $week);

    expect(chipUrl($this, $save))->toBe(route('gm.card.show', [$save, $ppv]));

    // Confirmed, it's still the PPV night's last card, where the PPV airs from.
    $ppv->update(['confirmed_at' => now()]);
    expect(chipUrl($this, $save))->toBe(route('gm.card.show', [$save, $ppv]));

    // House rule (2026-11-01): the weekly show is booked once the PPV aired.
    $ppv->update(['status' => ShowStatus::Aired]);
    expect(chipUrl($this, $save))->toBe(route('gm.card.show', [$save, $weekly]));

    app(BookingOrder::class)->confirmAll($save, $week);
    expect(chipUrl($this, $save))->toBe(route('gm.card.show', [$save, $weekly]));
});

it('links to the live results while the week airs', function () {
    GameBuilder::passiveRival();
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);

    app(WeekResolver::class)->begin($save->refresh());

    expect(chipUrl($this, $save))->toBe(route('gm.results', ['save' => $save, 'week' => 1]));
});

it('has no link outside the season', function () {
    $save = GameBuilder::newSave();

    expect(chipUrl($this, $save))->toBeNull();
});
