<?php

use App\Support\Rng\RngFactory;
use App\Support\Rng\SeededRng;

it('produces the same sequence for the same seed', function () {
    $a = new SeededRng('fixed-seed');
    $b = new SeededRng('fixed-seed');

    $seqA = array_map(fn () => $a->int(1, 1000), range(1, 50));
    $seqB = array_map(fn () => $b->int(1, 1000), range(1, 50));

    expect($seqA)->toBe($seqB);
});

it('produces different sequences for different seeds', function () {
    $a = new SeededRng('seed-a');
    $b = new SeededRng('seed-b');

    $seqA = array_map(fn () => $a->int(1, 1_000_000), range(1, 10));
    $seqB = array_map(fn () => $b->int(1, 1_000_000), range(1, 10));

    expect($seqA)->not->toBe($seqB);
});

it('gives each week and stream an independent, reproducible generator', function () {
    $factory = new RngFactory;

    $first = $factory->make('save-seed', 3, 'matches')->int(1, 1_000_000);
    $again = $factory->make('save-seed', 3, 'matches')->int(1, 1_000_000);
    $otherStream = $factory->make('save-seed', 3, 'injuries')->int(1, 1_000_000);
    $otherWeek = $factory->make('save-seed', 4, 'matches')->int(1, 1_000_000);

    expect($again)->toBe($first)
        ->and($otherStream)->not->toBe($first)
        ->and($otherWeek)->not->toBe($first);
});

it('keeps ints inside the inclusive range', function () {
    $rng = new SeededRng('range');

    foreach (range(1, 500) as $ignored) {
        expect($rng->int(3, 5))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(5);
    }
});

it('handles chance extremes without drawing', function () {
    $rng = new SeededRng('chance');

    expect($rng->chance(0.0))->toBeFalse()
        ->and($rng->chance(1.0))->toBeTrue()
        ->and($rng->steps())->toBe(0);
});

it('never picks a zero-weight key', function () {
    $rng = new SeededRng('weights');

    foreach (range(1, 300) as $ignored) {
        expect($rng->weightedPick(['a' => 0, 'b' => 1, 'c' => 2]))->not->toBe('a');
    }
});

it('shuffles without losing or duplicating items', function () {
    $rng = new SeededRng('shuffle');
    $items = range(1, 30);

    $shuffled = $rng->shuffle($items);
    sort($shuffled);

    expect($shuffled)->toBe($items);
});

it('samples distinct items up to the requested count', function () {
    $rng = new SeededRng('sample');

    $sample = $rng->sample(range(1, 10), 4);

    expect($sample)->toHaveCount(4)
        ->and(array_unique($sample))->toHaveCount(4)
        ->and($rng->sample([1, 2], 5))->toHaveCount(2);
});

it('counts every draw', function () {
    $rng = new SeededRng('steps');
    $rng->int(1, 10);
    $rng->float();
    $rng->pick(['x', 'y']);

    expect($rng->steps())->toBe(3);
});

it('rejects picking from an empty list', function () {
    (new SeededRng('empty'))->pick([]);
})->throws(InvalidArgumentException::class);
