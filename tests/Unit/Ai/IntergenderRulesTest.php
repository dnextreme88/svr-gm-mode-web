<?php

use App\Enums\MatchFamily;
use App\Models\MatchType;
use App\Services\Ai\IntergenderRules;
use App\Support\Rng\SeededRng;

/**
 * @param  list<int>  $sides
 * @param  list<int>  $managers
 */
function igType(array $sides, array $managers = [], string $variant = 'Test', bool $divasOnly = false, bool $divasAllowed = true): MatchType
{
    return new MatchType([
        'family' => MatchFamily::Single,
        'variant' => $variant,
        'sides' => $sides,
        'manager_sides' => $managers,
        'divas_only' => $divasOnly,
        'divas_allowed' => $divasAllowed,
    ]);
}

// Men are 1–9, Divas 101–109.
const IG_DIVAS = [101, 102, 103, 104, 105, 106];

dataset('shapes', [
    'w/Manager vs w/Manager' => [[1, 1], [0, 1], 1],
    '1 vs 1 w/Manager' => [[1, 1], [1], 2],
    '2v2' => [[2, 2], [], 3],
    '3v3' => [[3, 3], [], 4],
    '2 w/Manager vs 2 w/Manager' => [[2, 2], [0, 1], 5],
    '2 vs 2 w/Manager' => [[2, 2], [1], 6],
    '2v2v2' => [[2, 2, 2], [], 7],
]);

it('recognises each intergender shape', function (array $sides, array $managers, int $shape) {
    expect(app(IntergenderRules::class)->shape(igType($sides, $managers)))->toBe($shape);
})->with('shapes');

it('builds bookings that fit their own shape', function (array $sides, array $managers, int $shape) {
    $rules = app(IntergenderRules::class);
    $type = igType($sides, $managers);

    foreach (range(1, 20) as $seed) {
        $built = $rules->build($type, range(1, 9), IG_DIVAS, IG_DIVAS, new SeededRng("ig-{$seed}"));

        expect($built)->not->toBeNull()
            ->and($rules->allows($type, $built['sides'], $built['managers'], IG_DIVAS))->toBeTrue()
            ->and(array_map('count', $built['sides']))->toBe($sides);

        $everyone = [...array_merge(...$built['sides']), ...array_values($built['managers'])];
        expect(array_intersect($everyone, IG_DIVAS))->not->toBeEmpty();
    }
})->with('shapes');

it('never goes intergender for singles, triple threats, handicaps or the Table Tornado 6-man', function () {
    $rules = app(IntergenderRules::class);

    expect($rules->shape(igType([1, 1])))->toBeNull()
        ->and($rules->shape(igType([1, 1, 1])))->toBeNull()
        ->and($rules->shape(igType([1, 1, 1, 1])))->toBeNull()
        ->and($rules->shape(igType([1, 2])))->toBeNull()
        ->and($rules->shape(igType([3, 3], [], IntergenderRules::EXCLUDED_SIX_MAN)))->toBeNull()
        ->and($rules->shape(igType([2, 2], [], 'Test', divasOnly: true)))->toBeNull()
        ->and($rules->shape(igType([2, 2], [], 'Test', divasAllowed: false)))->toBeNull()
        ->and($rules->allows(igType([1, 1]), [[1], [101]], [], IG_DIVAS))->toBeFalse();
});

it('refuses a 2-man team against a mixed team', function () {
    $rules = app(IntergenderRules::class);

    expect($rules->allows(igType([2, 2]), [[1, 2], [3, 101]], [], IG_DIVAS))->toBeFalse()
        ->and($rules->allows(igType([2, 2]), [[1, 101], [3, 102]], [], IG_DIVAS))->toBeTrue()
        ->and($rules->allows(igType([3, 3]), [[1, 2, 101], [3, 102, 103]], [], IG_DIVAS))->toBeFalse()
        ->and($rules->allows(igType([3, 3]), [[1, 101, 102], [3, 103, 104]], [], IG_DIVAS))->toBeTrue()
        ->and($rules->allows(igType([3, 3]), [[101, 102, 103], [1, 2, 3]], [], IG_DIVAS))->toBeFalse()
        ->and($rules->allows(igType([1, 1], [0, 1]), [[1], [2]], [0 => 101, 1 => 3], IG_DIVAS))->toBeFalse()
        ->and($rules->allows(igType([1, 1], [1]), [[1], [101]], [1 => 102], IG_DIVAS))->toBeFalse();
});

it('allows single-gender bookings, managers included', function () {
    $rules = app(IntergenderRules::class);

    expect($rules->allows(igType([1, 1], [1]), [[1], [2]], [1 => 3], IG_DIVAS))->toBeTrue()
        ->and($rules->allows(igType([1, 1], [1]), [[101], [102]], [1 => 103], IG_DIVAS))->toBeTrue();
});

it('only fits shapes 2 and 6 with a single Diva', function () {
    $rules = app(IntergenderRules::class);
    $rng = new SeededRng('ig-one');

    $fits = collect([[[1, 1], [0, 1]], [[1, 1], [1]], [[2, 2], []], [[3, 3], []], [[2, 2], [0, 1]], [[2, 2], [1]], [[2, 2, 2], []]])
        ->filter(fn (array $t): bool => $rules->build(igType($t[0], $t[1]), range(1, 9), [101], [101], $rng) !== null)
        ->map(fn (array $t): int => (int) $rules->shape(igType($t[0], $t[1])))
        ->values()
        ->all();

    expect($fits)->toBe([2, 6]);
});
