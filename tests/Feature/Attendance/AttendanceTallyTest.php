<?php

use App\Enums\Brand;
use App\Enums\LedgerCategory;
use App\Enums\NewsEventType;
use App\Models\LedgerEntry;
use App\Models\NewsEvent;
use App\Models\Save;
use App\Models\Show;
use App\Services\News\NewsComposer;
use App\Services\Rivalries\RivalryService;
use App\Services\Week\WeekResolver;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| Fan attendance (house rule, 2026-11-01): every show gets a crowd once it
| airs, the week's biggest makes the WWE news, and the ticker carries a
| Fan attendance line.
*/

const CROWD_RAW = ['Edge', 'Kane', 'Carlito', 'Chris Masters', 'Shawn Michaels', 'Hulk Hogan'];

function crowdSave(string $seed = GameBuilder::SEED): Save
{
    $save = GameBuilder::inSeason(raw: CROWD_RAW, smackdown: ['Batista', 'Rey Mysterio', 'Booker T', 'JBL'], seed: $seed);
    GameBuilder::passiveRival();

    return $save;
}

function airCrowdWeek(Save $save): Show
{
    app(WeekResolver::class)->resolve($save->refresh());

    return Show::where('save_id', $save->id)->where('week_number', 1)->where('brand', Brand::Raw)->sole();
}

it('gives each weekly show a crowd within its range once it airs', function () {
    $save = crowdSave();
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);

    $raw = airCrowdWeek($save);

    expect($raw->attendance)->toBeGreaterThanOrEqual(5_000)->toBeLessThanOrEqual(20_000)
        ->and($raw->attendance_breakdown['kind'])->toBe('weekly')
        ->and($raw->attendance_breakdown['factors']['full_card'])->toBe(3)
        ->and($raw->attendance_breakdown['gate'])->toBe($raw->attendance * 30)
        ->and(Show::where('save_id', $save->id)->where('week_number', 1)->whereNull('attendance')->exists())->toBeFalse();
});

it('draws the same crowd from the same seed and bookings', function () {
    $crowds = collect([1, 2])->map(function () {
        $save = crowdSave();
        Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);

        return airCrowdWeek($save)->attendance;
    });

    expect($crowds[0])->toBe($crowds[1]);
});

it('counts Legends, hot rivalries met on the card and the main event', function () {
    $save = crowdSave();
    $rivalry = app(RivalryService::class)->start($save, GameBuilder::wrestler($save, 'Edge')->id, GameBuilder::wrestler($save, 'Kane')->id, 1);
    $rivalry->update(['heat' => 65]);
    $show = Card::show($save);
    Card::book($save, $show, 1, Card::singles(), [['Carlito'], ['Hulk Hogan']]);
    Card::book($save, $show, 6, Card::type('ladder', 'Single'), [['Edge'], ['Kane']]);

    $factors = airCrowdWeek($save)->attendance_breakdown['factors'];

    expect($factors['legends'])->toBe(8)
        ->and($factors['hot_rivalries'])->toBe(10)
        // A gimmick main event between rivals.
        ->and($factors['main_event'])->toBe(10)
        ->and($factors['full_card'])->toBe(6);
});

it('puts the week’s biggest crowd on the WWE card and the crowds in the ticker', function () {
    $save = crowdSave();
    Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]);
    airCrowdWeek($save);

    $event = NewsEvent::where('save_id', $save->id)->where('type', NewsEventType::Attendance)->sole();
    $page = app(NewsComposer::class)->compose($save->refresh(), 1);

    expect($page['headlines']['neutral']['id'])->toBe($event->id)
        ->and(collect($page['ticker'])->pluck('type'))->not->toContain('attendance')
        ->and($page['attendance'])->toHaveCount(2)
        ->and(collect($page['attendance'])->pluck('crowd')->max())->toBe((int) str_replace(',', '', $event->payload['crowd']));
});

it('pays the flat show income while attendance doesn’t drive it', function () {
    $save = crowdSave();
    airCrowdWeek($save);

    expect(LedgerEntry::where('save_id', $save->id)->where('category', LedgerCategory::ShowIncome)->pluck('amount')->all())
        ->toBe([250_000, 250_000]);
});

it('pays each show’s gate once attendance drives the income', function () {
    config(['gm.attendance.drives_income' => true]);
    $save = crowdSave();
    $raw = airCrowdWeek($save);

    $entry = LedgerEntry::where('save_id', $save->id)->where('category', LedgerCategory::ShowIncome)->where('brand', Brand::Raw)->sole();

    expect($entry->amount)->toBe($raw->attendance_breakdown['gate'])
        ->and($entry->memo)->toBe("Gate: {$raw->name}");
});

it('charts every aired night’s crowd, both brands, on the Reports page', function () {
    $save = crowdSave();
    $raw = airCrowdWeek($save);
    $smackdown = Show::where('save_id', $save->id)->where('week_number', 1)->where('brand', Brand::Smackdown)->sole();

    $this->get(route('gm.reports', $save))
        ->assertInertia(fn (Assert $page) => $page
            ->has('attendance', 2)
            ->where('attendance.0', [
                'season' => 1,
                'week' => 1,
                'name' => 'Monday Night Raw',
                'ppv' => false,
                'brands' => ['raw'],
                'crowd' => $raw->attendance,
                'gate' => $raw->attendance_breakdown['gate'],
            ])
            ->where('attendance.1.brands', ['smackdown'])
            ->where('attendance.1.crowd', $smackdown->attendance));
});
