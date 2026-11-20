<?php

use App\Enums\Morale;
use App\Enums\MoraleComplaintType;
use App\Enums\Stipulation;
use App\Models\Championship;
use App\Models\MoraleComplaint;
use App\Models\PromoType;
use App\Models\SaveWrestler;
use App\Services\Card\Booking;
use App\Services\Card\CardService;
use App\Services\Promos\PromoService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\FixedOutcomes;
use Tests\Support\GameBuilder;

/*
| House rules (2026-11-20): a #1 Contender's Match answers a title match
| complaint, win or lose; an Interference or Diva Bikini Contest promo counts
| as being used for the week.
*/

beforeEach(function () {
    config(['gm.defection.chance.unsatisfied' => 0.0, 'gm.defection.chance.upset' => 0.0]);
    GameBuilder::passiveRival();
});

function openComplaint(SaveWrestler $w, MoraleComplaintType $type): ?MoraleComplaint
{
    return MoraleComplaint::where('save_wrestler_id', $w->id)->where('type', $type)->whereNull('resolved_week')->first();
}

it('answers a title match complaint with a #1 Contender’s Match, even a loss', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'John Cena'], smackdown: ['Batista']);
    GameBuilder::giveTitle($save, 'wwe', ['John Cena']);
    $edge = GameBuilder::wrestler($save, 'Edge');
    $edge->update(['weeks_since_title_match' => 4]);
    app(WeekResolver::class)->resolve($save->refresh());
    expect(openComplaint($edge, MoraleComplaintType::TitleMatch))->not->toBeNull();

    $show = Card::show($save->refresh());
    app(CardService::class)->book($show, 1, new Booking(
        Card::singles(),
        [[GameBuilder::wrestler($save, 'Edge')->id], [GameBuilder::wrestler($save, 'Kane')->id]],
        stipulation: Stipulation::NumberOneContender,
        stipulationChampionshipId: Championship::where('key', 'wwe')->value('id'),
    ));
    FixedOutcomes::queue('Kane');
    app(WeekResolver::class)->resolve($save->refresh());

    $edge->refresh();

    expect(openComplaint($edge, MoraleComplaintType::TitleMatch))->toBeNull()
        ->and($edge->weeks_since_title_match)->toBe(0)
        ->and($edge->morale)->toBe(Morale::Happy);
});

it('counts an Interference or a Diva Bikini Contest as being used', function (string $key, array $names) {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian', 'Trish Stratus', 'Lita'], smackdown: ['Batista']);
    $ids = array_map(fn (string $n): int => GameBuilder::wrestler($save, $n)->id, $names);
    foreach ($ids as $id) {
        SaveWrestler::find($id)->update(['weeks_unused' => 4]);
    }
    app(WeekResolver::class)->resolve($save->refresh());
    $first = SaveWrestler::find($ids[0]);
    expect(openComplaint($first, MoraleComplaintType::Inactivity))->not->toBeNull();

    $show = Card::show($save->refresh());
    Card::book($save, $show, 1, Card::singles(), [['Edge'], ['Kane']]);
    app(PromoService::class)->book($show, 1, PromoType::where('key', $key)->firstOrFail(), $ids[0], $ids[1] ?? null);
    app(WeekResolver::class)->resolve($save->refresh());

    foreach ($ids as $id) {
        $w = SaveWrestler::find($id);

        expect($w->weeks_unused)->toBe(0)
            ->and(openComplaint($w, MoraleComplaintType::Inactivity))->toBeNull();
    }
})->with([
    'Interference' => ['interference_promotion', ['Christian']],
    'Diva Bikini Contest' => ['diva_bikini_contest', ['Trish Stratus', 'Lita']],
]);
