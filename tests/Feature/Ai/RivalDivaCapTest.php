<?php

use App\Enums\Brand;
use App\Enums\RosterType;
use App\Models\PendingRosterMove;
use App\Models\Save;
use App\Models\SaveWrestler;
use App\Services\Ai\AiRosterManager;
use Tests\Support\GameBuilder;

/*
| The rival GM's Diva cap (house rule, 2026-11-17): it never holds more than
| 9 Divas running Raw, or 6 running Smackdown, so its roster keeps enough
| men (the Royal Rumble needs 15).
*/

const CAP_DIVAS = ['Trish Stratus', 'Lita', 'Mickie James', 'Victoria', 'Torrie Wilson', 'Ashley Massaro', 'Maria', 'Candice Michelle', 'Christy Hemme'];

function signedDivas(Save $save, Brand $brand): int
{
    $pending = PendingRosterMove::where('save_id', $save->id)->where('brand', $brand)->whereNull('applied_week')->pluck('save_wrestler_id');

    return SaveWrestler::with('wrestler')->where('save_id', $save->id)
        ->where(fn ($q) => $q->where('brand', $brand)->orWhereIn('id', $pending))
        ->get()
        ->filter(fn (SaveWrestler $w): bool => $w->wrestler->roster_type === RosterType::Diva)
        ->count();
}

it('stops signing Divas for Raw at 9', function () {
    GameBuilder::passiveRival();
    config(['gm.ai.roster.target_size' => 30]);
    $save = GameBuilder::inSeason(raw: [...CAP_DIVAS, 'Edge', 'Kane'], smackdown: ['Batista'], player: Brand::Smackdown);

    expect(app(AiRosterManager::class)->divaCount($save, Brand::Raw))->toBe(9);

    app(AiRosterManager::class)->sign($save->refresh(), Brand::Raw, 1);

    expect(signedDivas($save, Brand::Raw))->toBe(9);
});

it('stops signing Divas for Smackdown at 6', function () {
    GameBuilder::passiveRival();
    config(['gm.ai.roster.target_size' => 30]);
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: [...array_slice(CAP_DIVAS, 0, 6), 'Batista', 'JBL'], player: Brand::Raw);

    app(AiRosterManager::class)->sign($save->refresh(), Brand::Smackdown, 1);

    expect(signedDivas($save, Brand::Smackdown))->toBe(6);
});

it('still signs Divas below the cap', function () {
    GameBuilder::passiveRival();
    config(['gm.ai.roster.target_size' => 30, 'gm.ai.roster.legend_chance' => 0.0]);
    $save = GameBuilder::inSeason(raw: ['Trish Stratus', 'Edge', 'Kane'], smackdown: ['Batista'], player: Brand::Smackdown);

    app(AiRosterManager::class)->sign($save->refresh(), Brand::Raw, 1);

    // Raw chases Divas (its focus class) first, up to its cap of 9.
    expect(signedDivas($save, Brand::Raw))->toBe(9);
});
