<?php

use App\Enums\FinishEvent;
use App\Enums\MatchFamily;
use App\Enums\MatchMethod;
use App\Models\MatchSlot;
use App\Models\MatchType;
use App\Models\Wrestler;
use App\Services\Matches\MatchResolver;
use App\Services\Rivalries\RivalryService;
use App\Support\Rng\SeededRng;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| Match rules house rules (2026-10-19): rope breaks, ring outs, no-title
| match types, Captain Fall, and the regrouped match categories.
*/

function rulesSlot(MatchSlot $slot): MatchSlot
{
    return $slot->fresh(['matchType', 'participants.saveWrestler.wrestler.specialties', 'show']);
}

describe('catalog', function () {
    beforeEach(fn () => GameBuilder::seedCatalog());

    it('turns rope breaks and count-outs off in the no-rope-break families', function () {
        $families = ['hardcore', 'tlc', 'table', 'ladder', 'battle_royal', 'royal_rumble', 'hell_in_a_cell'];

        expect(MatchType::whereIn('family', $families)->where(fn ($q) => $q->where('rope_breaks', true)->orWhere('countout_enabled', true))->count())->toBe(0)
            ->and(MatchType::where('family', 'single')->where('rope_breaks', false)->count())->toBe(0)
            ->and(Card::type('tag_team', 'Table Tornado Tag Elimination')->rope_breaks)->toBeFalse();
    });

    it('allows ring outs everywhere except Steel Cage, Battle Royal, Royal Rumble and Elimination Chamber', function () {
        $noRingOut = MatchType::where('ring_out', false)->pluck('family')->map->value->unique()->sort()->values()->all();

        expect($noRingOut)->toBe(['battle_royal', 'elimination_chamber', 'royal_rumble', 'steel_cage']);
    });

    it('bars titles from 3 vs 3, handicap and Gauntlet matches', function () {
        $threeOnThree = MatchType::all()->filter(fn (MatchType $t): bool => $t->sides === [3, 3]);

        expect($threeOnThree)->not->toBeEmpty()
            ->and($threeOnThree->every(fn (MatchType $t): bool => ! $t->titles_allowed))->toBeTrue()
            ->and(MatchType::where('family', 'handicap')->where('titles_allowed', true)->count())->toBe(0)
            ->and(MatchType::where('family', 'handicap')->count())->toBe(5)
            ->and(Card::type('gauntlet', '6-Man Gauntlet')->titles_allowed)->toBeFalse()
            ->and(Card::type('tag_team', 'Tag vs Tag w/Manager')->titles_allowed)->toBeTrue();
    });

    it('turns DQs and count-outs off in Normal Triple Threat and Normal Fatal 4-Way', function () {
        expect(Card::type('single', 'Normal Triple Threat')->dq_enabled)->toBeFalse()
            ->and(Card::type('single', 'Normal Triple Threat')->countout_enabled)->toBeFalse()
            ->and(Card::type('single', 'Normal Fatal 4-Way')->dq_enabled)->toBeFalse()
            ->and(Card::type('single', 'Normal Fatal 4-Way')->countout_enabled)->toBeFalse()
            ->and(Card::type('single', 'No Manager')->dq_enabled)->toBeTrue();
    });

    it('folds Elimination Tag, Triple Threat and Fatal 4-Way into other categories', function () {
        expect(MatchType::whereIn('family', ['elimination_tag', 'triple_threat', 'fatal_four_way'])->count())->toBe(0)
            ->and(MatchFamily::Single->label())->toBe('Normal Matches')
            ->and(MatchType::where('variant', 'like', '%Tag Elimination')->count())->toBe(7)
            ->and(Card::type('six_man_tag', 'Captain Fall Tornado 6-Man Tag Elimination')->captain_fall)->toBeTrue()
            ->and(Card::type('elimination_chamber', 'Triple Threat Tornado Tag Chamber')->sides)->toBe([2, 2, 2])
            ->and(Card::type('hell_in_a_cell', 'Armageddon Hell in a Cell')->competitorCount())->toBe(6);
    });

    it('costs the house-rule fatigue bars per variant, never more than 5', function (string $family, string $variant, int $bars) {
        expect(Card::type($family, $variant)->fatigue_cost_bars)->toBe($bars);
    })->with([
        ['tag_team', 'Table Tornado Tag Elimination', 3],
        ['six_man_tag', 'Normal', 2],
        ['six_man_tag', 'Tornado 6-Man Tag Elimination', 2],
        ['six_man_tag', 'Captain Fall Tornado 6-Man Tag Elimination', 3],
        ['six_man_tag', 'Table Tornado 6-Man Tag Elimination', 3],
        ['handicap', 'One on Two (Tornado)', 1],
        ['handicap', 'One on Three (Tornado)', 2],
        ['handicap', 'Two on Trio', 2],
        ['royal_rumble', '30-Man', 5],
        ['hell_in_a_cell', 'Armageddon Hell in a Cell', 5],
        ['elimination_chamber', 'Normal 6-Man Chamber', 5],
        ['iron_man', 'Single', 5],
        ['three_stages_of_hell', 'Single', 5],
        ['backstage_brawl', 'Bar Brawl', 4],
        ['battle_royal', '6-Man Over the Top Rope', 4],
        ['last_man_standing', 'Single', 4],
        ['buried_alive', 'Single', 5],
        ['first_blood', 'Single', 3],
        ['hardcore', 'Fatal 4-Way', 3],
        ['hardcore', 'Tornado 6-Man Tag', 4],
        ['ladder', 'Triple Threat Tornado Tag', 4],
        ['ladder', 'Money in the Bank', 4],
        ['tlc', 'Single', 3],
        ['table', 'Tornado 6-Man Tag', 4],
        ['gauntlet', '6-Man Gauntlet', 3],
        ['special_referee', 'Iron Man', 5],
        ['special_referee', 'Single', 2],
        ['steel_cage', 'Fatal 4-Way Pin & Give Up', 4],
        ['survivor_series_elimination', 'Traditional 3 vs 3', 3],
        ['single', 'No Manager', 1],
    ]);

    it('makes every Royal Rumble variant an elimination match', function () {
        expect(MatchType::where('family', 'royal_rumble')->count())->toBe(5)
            ->and(MatchType::where('family', 'royal_rumble')->where('is_elimination', false)->count())->toBe(0);
    });

    it('caps fatigue cost at 5 bars', function () {
        expect(MatchType::max('fatigue_cost_bars'))->toBe(5);
    });

    it('marks Iron Man and Ultimate Submission as timed', function () {
        expect(MatchType::where('timed', true)->get()->map(fn (MatchType $t): string => $t->name)->sort()->values()->all())->toBe([
            'Iron Man — Single',
            'Iron Man — Single No Manager vs. w/Manager',
            'Iron Man — Single w/Manager vs. w/Manager',
            'Special Referee — Iron Man',
            'Submission — Ultimate Submission',
        ]);
    });

    it('gives The Great Khali the Steel Cage specialty', function () {
        expect(Wrestler::where('name', 'The Great Khali')->firstOrFail()->specialtyFamilies())->toBe([MatchFamily::SteelCage]);
    });
});

describe('sim', function () {
    it('never ends a no-ring-out match by count-out or a run-in', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito'], smackdown: ['Batista']);
        config(['gm.outcomes.clean_finish_chance' => 0.0]);
        $slot = rulesSlot(Card::book($save, Card::show($save), 1, Card::type('steel_cage', 'Single Pin & Give Up'), [['Edge'], ['Kane']]));
        // Force count-outs on, to prove ring outs gate them.
        $slot->matchType->countout_enabled = true;
        $rivalries = collect([
            app(RivalryService::class)->start($save, GameBuilder::wrestler($save, 'Carlito')->id, GameBuilder::wrestler($save, 'Edge')->id, 1),
            app(RivalryService::class)->start($save, GameBuilder::wrestler($save, 'Carlito')->id, GameBuilder::wrestler($save, 'Kane')->id, 1),
        ]);

        $events = collect(range(1, 300))
            ->map(fn (int $i) => app(MatchResolver::class)->resolve($slot, new SeededRng("cage-{$i}"), $rivalries)->finishEvent)
            ->filter();

        expect($events->contains(FinishEvent::Countout))->toBeFalse()
            ->and($events->contains(FinishEvent::RivalInterference))->toBeFalse()
            ->and($events)->not->toBeEmpty();
    });

    it('makes clean submissions rarer when rope breaks are on', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
        config(['gm.outcomes.clean_finish_chance' => 1.0, 'gm.outcomes.submission_chance' => 0.5, 'gm.outcomes.rope_break_submission_factor' => 0.2]);
        $slot = rulesSlot(Card::book($save, Card::show($save), 1, Card::singles(), [['Edge'], ['Kane']]));

        $submissions = function (bool $ropeBreaks) use ($slot): int {
            $slot->matchType->rope_breaks = $ropeBreaks;

            return collect(range(1, 400))
                ->map(fn (int $i) => app(MatchResolver::class)->resolve($slot, new SeededRng("rb-{$i}"), collect())->method)
                ->filter(fn (MatchMethod $m): bool => $m === MatchMethod::Submission)
                ->count();
        };

        expect($submissions(true))->toBeLessThan((int) ($submissions(false) * 0.5));
    });

    it('has the losing captain take the fall in a Captain Fall match', function () {
        $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito', 'Chris Masters', 'Shelton Benjamin', 'Viscera'], smackdown: ['Batista']);
        config(['gm.outcomes.clean_finish_chance' => 1.0]);
        $slot = rulesSlot(Card::book($save, Card::show($save), 1, Card::type('six_man_tag', 'Captain Fall Tornado 6-Man Tag Elimination'), [
            ['Edge', 'Kane', 'Carlito'],
            ['Chris Masters', 'Shelton Benjamin', 'Viscera'],
        ]));

        $result = app(MatchResolver::class)->resolve($slot, new SeededRng('captain'), collect());
        $captain = $result->loserSide === 0 ? 'Edge' : 'Chris Masters';

        expect($result->summary)->toContain("{$captain}, the captain,");
    });
});
