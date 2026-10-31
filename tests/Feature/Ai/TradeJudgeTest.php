<?php

use App\Enums\Brand;
use App\Enums\InjurySeverity;
use App\Enums\ReignEndReason;
use App\Enums\TitleHolderType;
use App\Enums\WonVia;
use App\Models\Championship;
use App\Models\Save;
use App\Models\TitleReign;
use App\Services\Ai\AiTradeJudge;
use App\Services\Injuries\InjuryService;
use Tests\Support\GameBuilder;

/*
| House rule (2026-10-31): how the rival GM values wrestlers in a trade.
*/

/**
 * Give a wrestler `$times` past reigns with a title (ended, so they don't
 * make anyone the current champion).
 */
function pastReigns(Save $save, string $name, string $key, int $times): void
{
    $wrestler = GameBuilder::wrestler($save, $name);
    $championship = Championship::where('key', $key)->firstOrFail();

    foreach (range(1, $times) as $i) {
        TitleReign::create([
            'save_id' => $save->id,
            'championship_id' => $championship->id,
            'brand' => $championship->brand,
            'reign_number' => $i,
            'holder_type' => TitleHolderType::Wrestler,
            'holder_snapshot' => ['names' => [$wrestler->name()], 'ids' => [$wrestler->id]],
            'season' => 1,
            'won_week' => 1,
            'won_via' => WonVia::Awarded,
            'lost_week' => 1,
            'end_reason' => ReignEndReason::LostMatch,
        ]);
    }
}

/** @return list<int> */
function idsOf(Save $save, string ...$names): array
{
    return array_map(fn (string $n): int => GameBuilder::wrestler($save, $n)->id, $names);
}

function judge(): AiTradeJudge
{
    return app(AiTradeJudge::class);
}

it('scores each wrestler from the rival’s point of view, by category', function () {
    // Raw is the rival, so Divas are its focus class.
    $save = GameBuilder::inSeason(
        raw: ['Trish Stratus', 'Kane'],
        smackdown: ['Bret Hart', 'Chris Benoit', 'Snitsky', 'Matt Hardy', 'Jeff Hardy'],
        player: Brand::Smackdown,
    );

    expect(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Snitsky')))->toBe(['base' => 1])
        // Bret Hart: a Legend with specialties.
        ->and(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Bret Hart')))->toBe(['base' => 1, 'legend' => 2, 'specialty' => 1])
        ->and(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Trish Stratus')))->toBe(['base' => 1, 'focus' => 1])
        // Team Xtreme are both on SmackDown, so neither has a partner on
        // the rival's (Raw's) roster.
        ->and(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Matt Hardy', 'Jeff Hardy')))->not->toHaveKey('tag_partner')
        // Kane's tag partner (Undertaker) isn't on Raw either.
        ->and(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Kane')))->not->toHaveKey('tag_partner');
});

it('scores form: win streaks per 5, a flat losing-streak penalty and minor injuries', function () {
    $save = GameBuilder::inSeason(raw: ['Kane'], smackdown: ['Snitsky', 'Batista', 'Orlando Jordan'], player: Brand::Smackdown);
    GameBuilder::wrestler($save, 'Snitsky')->update(['win_streak' => 9]);
    GameBuilder::wrestler($save, 'Batista')->update(['win_streak' => 11]);
    GameBuilder::wrestler($save, 'Orlando Jordan')->update(['loss_streak' => 12]);
    app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Kane'), InjurySeverity::Minor, 1, $save->rng('t'));

    expect(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Snitsky')))->toBe(['base' => 1, 'win_streak' => 1])
        ->and(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Batista')))->toBe(['base' => 1, 'win_streak' => 2])
        ->and(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Orlando Jordan')))->toBe(['base' => 1, 'loss_streak' => -1])
        ->and(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Kane')))->toMatchArray(['base' => 1, 'minor_injury' => -1]);
});

it('counts world title reigns on the wrestler’s own brand in bands, and repeat other titles', function () {
    $save = GameBuilder::inSeason(raw: ['Kane'], smackdown: ['Edge', 'Batista', 'Snitsky', 'Orlando Jordan'], player: Brand::Smackdown);
    // SmackDown's world title is the World Heavyweight. Edge's WWE reigns
    // (Raw's world title) don't count toward it, and aren't "other" titles.
    pastReigns($save, 'Edge', 'world_heavyweight', 3);
    pastReigns($save, 'Edge', 'wwe', 2);
    pastReigns($save, 'Batista', 'world_heavyweight', 5);
    pastReigns($save, 'Snitsky', 'world_heavyweight', 8);
    pastReigns($save, 'Orlando Jordan', 'world_heavyweight', 1);
    pastReigns($save, 'Orlando Jordan', 'united_states', 2);

    expect(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Edge')))->toMatchArray(['world_champion' => 1])
        ->and(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Edge')))->not->toHaveKey('repeat_champion')
        ->and(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Batista')))->toMatchArray(['world_champion' => 2])
        ->and(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Snitsky')))->toMatchArray(['world_champion' => 3])
        // 1 world reign is worth 1; the US title twice adds 1 more.
        ->and(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Orlando Jordan')))->toMatchArray(['world_champion' => 1, 'repeat_champion' => 1]);
});

it('accepts an even fair offer at 75%, more the further ahead the rival comes out, and anything else at 25%', function () {
    $save = GameBuilder::inSeason(raw: ['Kane', 'Snitsky'], smackdown: ['Orlando Jordan', 'Batista', 'Lance Cade'], player: Brand::Smackdown);

    $even = judge()->assess($save, Brand::Smackdown, idsOf($save, 'Orlando Jordan'), idsOf($save, 'Snitsky'));
    $oneAhead = judge()->assess($save, Brand::Smackdown, idsOf($save, 'Orlando Jordan', 'Batista'), idsOf($save, 'Snitsky'));
    $twoAhead = judge()->assess($save, Brand::Smackdown, idsOf($save, 'Orlando Jordan', 'Batista', 'Lance Cade'), idsOf($save, 'Snitsky'));
    $unfair = judge()->assess($save, Brand::Smackdown, idsOf($save, 'Orlando Jordan'), idsOf($save, 'Kane', 'Snitsky'));

    expect($even)->toMatchArray(['chance' => 0.75, 'fair' => true])
        ->and($even['gets'])->toBe(['total' => 1, 'lines' => ['base' => 1]])
        ->and($oneAhead['chance'])->toBe(0.9)
        ->and($twoAhead['chance'])->toBe(1.0)
        ->and($unfair)->toMatchArray(['chance' => 0.25, 'fair' => false])
        // Kane (1, plus a specialty) and Snitsky (1).
        ->and($unfair['gives']['total'])->toBe(3);
});

it('values a wrestler whose tag team partner is on the rival’s roster', function () {
    // The rival (Raw) has Matt Hardy; Jeff Hardy is the player's.
    $save = GameBuilder::inSeason(raw: ['Matt Hardy', 'Kane'], smackdown: ['Jeff Hardy', 'Snitsky'], player: Brand::Smackdown);

    expect(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Jeff Hardy')))->toMatchArray(['tag_partner' => 1])
        // Matt's partner isn't on Raw, so giving Matt away isn't breaking up a team there.
        ->and(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Matt Hardy')))->not->toHaveKey('tag_partner');

    $offer = judge()->assess($save, Brand::Smackdown, idsOf($save, 'Jeff Hardy'), idsOf($save, 'Matt Hardy'));
    expect($offer['gets']['lines'])->toHaveKey('tag_partner');
});

it('adds points for a whole tag team or stable traded together', function () {
    $save = GameBuilder::inSeason(
        raw: ['Kane'],
        smackdown: ['Faarooq', 'JBL', 'Triple H', 'Ric Flair', 'Batista', 'Randy Orton'],
        player: Brand::Smackdown,
    );

    // APA: Faarooq and JBL together.
    expect(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Faarooq', 'JBL')))->toMatchArray(['full_tag_team' => 1])
        // Evolution: all four.
        ->and(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Triple H', 'Ric Flair', 'Batista', 'Randy Orton')))->toMatchArray(['full_stable' => 2])
        // Only part of a faction doesn't count.
        ->and(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Faarooq')))->not->toHaveKey('full_tag_team')
        ->and(judge()->breakdown($save, Brand::Raw, idsOf($save, 'Triple H', 'Batista')))->not->toHaveKey('full_stable');
});
