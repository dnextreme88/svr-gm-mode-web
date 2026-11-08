<?php

use App\Enums\Brand;
use App\Enums\PromoTypeKey;
use App\Enums\ShowType;
use App\Models\PromoSlot;
use App\Models\PromoType;
use App\Models\Save;
use App\Models\Show;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\RepeatMatches;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekResolver;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| Repeat matches (house rule, 2026-11-08): a slot on a brand's weekly show in
| the same match category as that slot on its weekly show the week before.
*/

const REPEAT_RAW = ['Edge', 'Kane', 'Carlito', 'Chris Masters', 'Shawn Michaels', 'Triple H', 'Ric Flair', 'Batista'];

function repeatSave(): Save
{
    $save = GameBuilder::inSeason(raw: REPEAT_RAW, smackdown: ['Rey Mysterio', 'Booker T', 'JBL', 'Chris Benoit']);
    GameBuilder::passiveRival();

    return $save;
}

/**
 * @return list<int>
 */
function repeatedPositions(Show $show): array
{
    return array_column(app(RepeatMatches::class)->forShow($show), 'position');
}

it('repeats a slot booked in the same category as last week, any variant', function () {
    $save = repeatSave();
    $week6 = Card::show($save, week: 6);
    $week7 = Card::show($save, week: 7);
    Card::book($save, $week6, 3, Card::type('tlc', 'Tornado Tag'), [['Edge', 'Kane'], ['Carlito', 'Chris Masters']]);
    Card::book($save, $week7, 3, Card::type('tlc', 'Triple Threat'), [['Edge'], ['Kane'], ['Carlito']]);

    expect(app(RepeatMatches::class)->forShow($week7))->toBe([
        ['position' => 3, 'family' => 'tlc', 'label' => 'TLC'],
    ]);
});

it('counts Normal Matches and every other category too', function () {
    $save = repeatSave();
    $week6 = Card::show($save, week: 6);
    $week7 = Card::show($save, week: 7);
    Card::book($save, $week6, 1, Card::singles(), [['Edge'], ['Kane']]);
    Card::book($save, $week7, 1, Card::singles(), [['Carlito'], ['Chris Masters']]);
    Card::book($save, $week6, 2, Card::type('tag_team', 'Tornado Tag'), [['Edge', 'Kane'], ['Carlito', 'Chris Masters']]);
    Card::book($save, $week7, 2, Card::type('tag_team', 'Tornado Tag'), [['Edge', 'Kane'], ['Triple H', 'Ric Flair']]);

    expect(repeatedPositions($week7))->toBe([1, 2]);
});

it('ignores another slot, another category, earlier weeks and empty slots', function () {
    $save = repeatSave();
    $week5 = Card::show($save, week: 5);
    $week6 = Card::show($save, week: 6);
    $week7 = Card::show($save, week: 7);
    // Week 5's Match 1 doesn't count against week 7.
    Card::book($save, $week5, 1, Card::singles(), [['Edge'], ['Kane']]);
    // A singles in Match 2 last week, Match 4 this week.
    Card::book($save, $week6, 2, Card::singles(), [['Edge'], ['Kane']]);
    Card::book($save, $week7, 4, Card::singles(), [['Edge'], ['Kane']]);
    // Same slot, another category.
    Card::book($save, $week6, 5, Card::type('ladder', 'Single'), [['Carlito'], ['Chris Masters']]);
    Card::book($save, $week7, 5, Card::type('tlc', 'Single'), [['Carlito'], ['Chris Masters']]);
    Card::book($save, $week7, 1, Card::singles(), [['Triple H'], ['Ric Flair']]);

    expect(repeatedPositions($week7))->toBe([]);
});

it('never counts the other brand, PPVs or the first week', function () {
    $save = repeatSave();
    $raw1 = Card::show($save, week: 1);
    Card::book($save, $raw1, 1, Card::singles(), [['Edge'], ['Kane']]);
    $sd2 = Card::show($save, Brand::Smackdown, week: 2);
    Card::book($save, $sd2, 1, Card::singles(), [['Rey Mysterio'], ['Booker T']]);

    expect(repeatedPositions($raw1))->toBe([])
        // Raw's week 1 isn't Smackdown's last week.
        ->and(repeatedPositions($sd2))->toBe([]);

    $ppv = Show::where('save_id', $save->id)->where('show_type', ShowType::Ppv)->where('brand', Brand::Raw)->orderBy('week_number')->first();

    if ($ppv !== null) {
        expect(app(RepeatMatches::class)->forShow($ppv))->toBe([]);
    }
});

it('takes the repeats off the night’s draw score', function () {
    $save = repeatSave();
    Card::book($save, Card::show($save, week: 1), 2, Card::singles(), [['Edge'], ['Kane']]);
    Card::book($save, Card::show($save, week: 1), 3, Card::type('hardcore', 'Single'), [['Carlito'], ['Chris Masters']]);
    app(WeekResolver::class)->resolve($save->refresh());

    $save->refresh();
    Card::book($save, Card::show($save, week: 2), 2, Card::singles(), [['Triple H'], ['Ric Flair']]);
    Card::book($save, Card::show($save, week: 2), 3, Card::type('hardcore', 'Tornado Tag'), [['Carlito', 'Batista'], ['Chris Masters', 'Shawn Michaels']]);
    app(WeekResolver::class)->resolve($save->refresh());

    $week1 = Show::where('save_id', $save->id)->where('week_number', 1)->where('brand', Brand::Raw)->where('show_type', ShowType::Weekly)->sole();
    $week2 = Show::where('save_id', $save->id)->where('week_number', 2)->where('brand', Brand::Raw)->where('show_type', ShowType::Weekly)->sole();

    expect($week1->attendance_breakdown['factors']['repeats'])->toBe(0)
        ->and($week2->attendance_breakdown['factors']['repeats'])->toBe(-4);
});

it('scores each aired promo on its own (house rule, 2026-11-08)', function () {
    $save = repeatSave();
    $show = Card::show($save);
    $type = fn (PromoTypeKey $key): int => PromoType::where('key', $key)->value('id');
    $promo = fn (int $position, PromoTypeKey $key, bool $ok, bool $backfired = false, ?string $note = null) => PromoSlot::updateOrCreate(
        ['show_id' => $show->id, 'position' => $position],
        ['promo_type_id' => $type($key), 'succeeded' => $ok, 'backfired' => $backfired, 'outcome_note' => $note],
    );
    $facts = fn (): array => app(AttendanceService::class)->facts(collect([$show]), new WeekContext($save, 1, 1));

    $promo(1, PromoTypeKey::SlanderPromotion, false, backfired: true);
    $promo(2, PromoTypeKey::Advertising, false);
    expect($facts()['rival_promos'])->toBe(['succeeded' => 0, 'backfired' => 1])
        ->and($facts()['other_promos'])->toBe(['succeeded' => 0, 'failed' => 1]);

    // A promo that always succeeds doesn't count, nor does a plain failed Slander.
    $promo(1, PromoTypeKey::SlanderPromotion, false);
    $promo(2, PromoTypeKey::ContinueRivalry, true);
    expect($facts()['rival_promos'])->toBe(['succeeded' => 0, 'backfired' => 0])
        ->and($facts()['other_promos'])->toBe(['succeeded' => 0, 'failed' => 0]);

    $promo(1, PromoTypeKey::InterferencePromotion, true);
    $promo(2, PromoTypeKey::MerchandiseSponsorship, true);
    expect($facts()['rival_promos'])->toBe(['succeeded' => 1, 'backfired' => 0])
        ->and($facts()['other_promos'])->toBe(['succeeded' => 1, 'failed' => 0]);
});

it('lists the repeats on the card screen for the Air week warning', function () {
    $save = repeatSave();
    Card::book($save, Card::show($save, week: 1), 3, Card::type('hardcore', 'Single'), [['Carlito'], ['Chris Masters']]);
    app(WeekResolver::class)->resolve($save->refresh());
    $save->refresh();
    $show = Card::show($save);
    Card::book($save, $show, 3, Card::type('hardcore', 'Tornado Tag'), [['Carlito', 'Batista'], ['Chris Masters', 'Shawn Michaels']]);

    $this->get(route('gm.card.show', [$save, $show]))
        ->assertInertia(fn (Assert $page) => $page->component('gm/card')
            ->where('repeats.slots', [['position' => 3, 'family' => 'hardcore', 'label' => 'Hardcore']])
            ->where('repeats.per_slot', 2)
            ->where('repeats.max', 12));
});
