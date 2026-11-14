<?php

use App\Services\Mail\EmailTexts;
use App\Support\Rng\SeededRng;

/*
| Email bodies (house rule): several variants per situation, drawn from the
| seeded RNG, with every placeholder filled.
*/

$vars = [
    'name' => 'Edge', 'brand' => 'Raw', 'rival' => 'Smackdown', 'weeks' => '3 weeks', 'n' => 5,
    'injury' => 'Torn Biceps', 'severity' => 'serious', 'amount' => '$400,000', 'season' => 2, 'to' => 'Smackdown',
    'families' => 'Ladder', 'names' => 'the Legend Hulk Hogan', 'are' => 'is', 'max' => 10, 'cap' => 30,
    'race' => 'Right now it is dead even.', 'our_crowd' => '120,000', 'your_crowd' => '110,000', 'slots' => '2 slots',
];

it('has at least 3 variants for every situation, and exactly 2 (polite, arrogant) for the rival GM before a shared PPV', function () {
    foreach (EmailTexts::keys() as $key) {
        str_starts_with($key, 'rival_ppv.')
            ? expect(EmailTexts::variants($key))->toHaveCount(2, $key)
            : expect(count(EmailTexts::variants($key)))->toBeGreaterThanOrEqual(3, $key);
    }
});

it('leaves no placeholder unfilled', function () use ($vars) {
    foreach (EmailTexts::keys() as $key) {
        foreach (EmailTexts::variants($key) as $text) {
            expect(EmailTexts::fill($text, $vars))->not->toMatch('/\{[a-z_]+\}/', "{$key}: {$text}");
        }
    }
});

it('draws the same variant for the same seed, and different ones across seeds', function () use ($vars) {
    expect(EmailTexts::pick('defection', new SeededRng('a'), $vars))->toBe(EmailTexts::pick('defection', new SeededRng('a'), $vars));

    $drawn = collect(range(1, 30))->map(fn (int $i) => EmailTexts::pick('defection', new SeededRng("seed-{$i}"), $vars))->unique();

    expect($drawn->count())->toBe(count(EmailTexts::variants('defection')));
});
