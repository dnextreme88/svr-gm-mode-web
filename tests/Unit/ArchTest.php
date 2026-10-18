<?php

/*
| All randomness must go through the seeded RNG (CLAUDE.md, §17).
*/

arch('game code never calls PHP randomness functions directly')
    ->expect('App')
    ->not->toUse(['rand', 'mt_rand', 'mt_srand', 'srand', 'random_int', 'array_rand', 'shuffle', 'str_shuffle', 'lcg_value', 'uniqid'])
    ->ignoring('App\Support\Rng\RngFactory');

arch('enums are backed')
    ->expect('App\Enums')
    ->toBeStringBackedEnums()
    ->ignoring(['App\Enums\ContractLength', 'App\Enums\Concerns']);

arch('contract length is an int-backed enum')
    ->expect('App\Enums\ContractLength')
    ->toBeIntBackedEnum();

it('frontend code never calls Math.random', function () {
    $offenders = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/js', FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (preg_match('/\.(tsx?|jsx?)$/', $file->getFilename()) && str_contains((string) file_get_contents($file->getPathname()), 'Math.random')) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});
