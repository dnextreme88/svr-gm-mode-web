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

it('frontend code pluralizes counted words through pluralize()', function () {
    // "{n} weeks" reads "1 weeks" when n is 1: numbers before a counted word
    // go through pluralize() in resources/js/lib/gm.ts. These are names, not
    // counts, so they keep their plural.
    $allowed = [
        '{brandName(game.rival_brand)} picks',
        '{brandName(landed)} picks',
        '{MatchFamilyLabels[family]} matches',
        "'Smackdown'} titles",
        '{scope} emails',
    ];
    $words = 'weeks|matches|wins|losses|bars|picks|superstars|days|members|titles|defenses|reigns|promos|entrants|signings|points|emails|times|slots|seasons|champions|bookings';
    $offenders = [];
    $root = dirname(__DIR__, 2).'/resources/js';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (! preg_match('/\.(tsx?|jsx?)$/', $file->getFilename()) || preg_match('/\.test\.tsx?$/', $file->getFilename())) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        // An expression (not a {' '} spacer), maybe a spacer, then the word,
        // and not a prop name ("champions={...}").
        preg_match_all('/\{(?!\' \'\})[^{}\n]*\}\s*(?:\{\' \'\}\s*)?(?:'.$words.')\b(?!\s*=)/', $source, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$text, $offset]) {
            foreach ($allowed as $ok) {
                if (str_contains($text, $ok)) {
                    continue 2;
                }
            }
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            $offenders[] = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname()).":{$line} ".preg_replace('/\s+/', ' ', $text);
        }
    }

    expect($offenders)->toBe([]);
});

it('frontend code keeps font sizes at or above 12px (0.75rem)', function () {
    $offenders = [];
    $root = dirname(__DIR__, 2).'/resources';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (! preg_match('/\.(tsx?|jsx?|css)$/', $file->getFilename())) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        preg_match_all('/text-\[(\d*\.?\d+)(px|rem)\]|font-size:\s*(\d*\.?\d+)(px|rem)/', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $value = (float) ($match[1] !== '' ? $match[1] : $match[3]);
            $unit = $match[2] !== '' ? $match[2] : $match[4];

            if (($unit === 'rem' ? $value * 16 : $value) < 12) {
                $offenders[] = $file->getFilename().': '.$match[0];
            }
        }
    }

    expect($offenders)->toBe([]);
});
