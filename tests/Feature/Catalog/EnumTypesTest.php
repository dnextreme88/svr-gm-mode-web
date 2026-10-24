<?php

use App\Support\EnumTypeScriptGenerator;

it('keeps resources/js/types/enums.ts in sync with the PHP enums', function () {
    $this->artisan('gm:types', ['--check' => true])->assertSuccessful();
});

it('renders values and labels for each enum', function () {
    $ts = app(EnumTypeScriptGenerator::class)->generate();

    expect($ts)->toContain("Smackdown: 'smackdown'")
        ->toContain("'smackdown': 'Smackdown'")
        ->toContain('OneYear: 52')
        ->toContain('export type WeightClass = (typeof WeightClass)[keyof typeof WeightClass];')
        ->toContain("'womens': 'Women\\'s Championship'");
});
