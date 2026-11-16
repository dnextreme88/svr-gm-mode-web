<?php

use App\Enums\Brand;
use App\Models\MatchResult;
use App\Models\MatchSlot;
use App\Models\Season;
use App\Models\Show;
use App\Services\Ppv\PpvCardService;
use App\Services\Titles\MitbService;
use App\Services\Week\ShowScheduler;
use App\Services\Week\WeekResolver;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| 2026-11-15: the Royal Rumble result names the winner's brand, and the
| roster page tags this season's Rumble winner, briefcase holders, and how
| long someone sits out.
*/

it('names the Royal Rumble winner’s brand', function () {
    $save = GameBuilder::inSeason(size: 16);
    $save->update(['current_week' => 44]);
    app(ShowScheduler::class)->ensureWeek($save, 44);
    $rumble = Show::with('ppvEvent')->where('save_id', $save->id)->where('week_number', 44)->whereNull('brand')->sole();
    app(PpvCardService::class)->randomEntrants($rumble, Brand::Raw, $save->rng('test.entrants'));
    $winner = GameBuilder::wrestler($save, MatchSlot::with('participants.saveWrestler.wrestler')->where('show_id', $rumble->id)->where('position', 7)->sole()->participants->first(fn ($p) => $p->saveWrestler->brand === Brand::Raw)->saveWrestler->name());
    FixedOutcomes::queue($winner->name());

    app(WeekResolver::class)->resolve($save->refresh());

    $slot = MatchSlot::where('show_id', $rumble->id)->where('position', 7)->sole();

    expect(MatchResult::where('match_slot_id', $slot->id)->sole()->stipulation_outcome)
        ->toBe("{$winner->name()} of Raw is going to the WrestleMania main event");
});

it('tags the Rumble winner, briefcase holders and the weeks someone sits out on the roster', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian'], smackdown: ['Batista']);
    Season::where('save_id', $save->id)->where('number', $save->current_season)->update(['royal_rumble_winner_id' => GameBuilder::wrestler($save, 'Edge')->id]);
    app(MitbService::class)->award($save, GameBuilder::wrestler($save, 'Kane'), 1);
    GameBuilder::wrestler($save, 'Christian')->update(['unbookable_until_week' => $save->current_week + 2]);

    $this->get(route('gm.rosters.index', $save))->assertInertia(function ($page) {
        $rows = collect($page->toArray()['props']['wrestlers'])->keyBy('name');

        expect($rows['Edge']['rumble_winner'])->toBeTrue()
            ->and($rows['Edge']['mitb_winner'])->toBeFalse()
            ->and($rows['Kane']['mitb_winner'])->toBeTrue()
            ->and($rows['Christian']['sitting_out'])->toBeTrue()
            ->and($rows['Christian']['sitting_out_weeks'])->toBe(2)
            ->and($rows['Kane']['sitting_out_weeks'])->toBeNull();
    });
});

it('names the Money in the Bank winner’s brand and says the shot is anytime', function () {
    $save = GameBuilder::inSeason(size: 16);
    GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
    GameBuilder::giveTitle($save, 'world_heavyweight', ['Batista']);
    Season::where('save_id', $save->id)->update(['royal_rumble_winner_id' => GameBuilder::wrestler($save, 'Edge')->id]);
    $save->update(['current_week' => 52]);
    app(ShowScheduler::class)->ensureWeek($save, 52);
    $show = Show::with('ppvEvent')->where('save_id', $save->id)->where('week_number', 52)->whereNull('brand')->sole();
    app(PpvCardService::class)->fillSeries($show, Brand::Raw, $save->rng('test.series'));
    $ladder = MatchSlot::with('participants.saveWrestler.wrestler')->where('show_id', $show->id)->where('position', 7)->sole();
    $winner = $ladder->participants->first(fn ($p) => $p->saveWrestler->brand === Brand::Smackdown)->saveWrestler;
    FixedOutcomes::queue($winner->name());

    app(WeekResolver::class)->resolve($save->refresh());

    expect(MatchResult::where('match_slot_id', $ladder->id)->sole()->stipulation_outcome)
        ->toBe("{$winner->name()} of Smackdown earns a World Heavyweight Championship shot anytime");
});
