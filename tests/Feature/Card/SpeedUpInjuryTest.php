<?php

use App\Enums\Brand;
use App\Enums\InjurySeverity;
use App\Enums\LedgerCategory;
use App\Enums\NewsEventType;
use App\Enums\PromoTypeKey;
use App\Models\Injury;
use App\Models\InjuryType;
use App\Models\LedgerEntry;
use App\Models\NewsEvent;
use App\Models\PromoSlot;
use App\Models\PromoType;
use App\Models\Save;
use App\Services\Ai\AiPromoPlanner;
use App\Services\Injuries\InjuryService;
use App\Services\Injuries\InjuryStatus;
use App\Services\Promos\PromoEligibility;
use App\Services\Promos\PromoService;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| Speed-up Injury Process (house rule, 2026-11-20): $15,000, 80% success.
| A success heals a minor injury outright or takes 5 weeks off a serious
| one; a failure takes 2 weeks off either.
*/

function treatSave(): Save
{
    GameBuilder::passiveRival();

    return GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Carlito', 'Chris Masters'], smackdown: ['Batista']);
}

/** An injury of a fixed length (weeks out from week 1). */
function injureFor(Save $save, string $name, InjurySeverity $severity, int $weeks): Injury
{
    $type = InjuryType::where('severity', $severity)->orderBy('id')->firstOrFail();
    $type->min_weeks = $weeks;
    $type->max_weeks = $weeks;

    return app(InjuryService::class)->injure(GameBuilder::wrestler($save, $name), $severity, 1, $save->rng("hurt.{$name}"), type: $type);
}

function treat(Save $save, string $name, bool $succeeds): PromoSlot
{
    config(['gm.promos.success_rates.speed_up_injury' => $succeeds ? 1.0 : 0.0]);
    $show = Card::show($save->refresh());
    Card::book($save, $show, 1, Card::singles(), [['Carlito'], ['Chris Masters']]);
    $slot = app(PromoService::class)->book($show, 1, PromoType::where('key', 'speed_up_injury')->firstOrFail(), GameBuilder::wrestler($save, $name)->id);
    app(WeekResolver::class)->resolve($save->refresh());

    return $slot->refresh();
}

it('needs a superstar with an injury', function () {
    $save = treatSave();
    $rules = fn () => app(PromoEligibility::class)->forShow(Card::show($save->refresh()), Brand::Raw)[PromoTypeKey::SpeedUpInjury->value];

    expect($rules()['available'])->toBeFalse()
        ->and($rules()['reason'])->toBe('Nobody on your roster is injured.');

    injureFor($save, 'Edge', InjurySeverity::Minor, 3);
    injureFor($save, 'Kane', InjurySeverity::Serious, 10);

    expect($rules()['available'])->toBeTrue()
        ->and($rules()['wrestler_ids'])->toEqualCanonicalizing([GameBuilder::wrestler($save, 'Edge')->id, GameBuilder::wrestler($save, 'Kane')->id]);
});

it('heals a minor injury outright on a success', function () {
    $save = treatSave();
    $injury = injureFor($save, 'Edge', InjurySeverity::Minor, 4);

    $slot = treat($save, 'Edge', true);

    expect($injury->refresh()->healed_week)->toBe(1)
        ->and(app(InjuryStatus::class)->active(GameBuilder::wrestler($save, 'Edge'), 2))->toBeNull()
        ->and($slot->succeeded)->toBeTrue()
        ->and(LedgerEntry::where('category', LedgerCategory::PromoCost)->where('amount', -15_000)->exists())->toBeTrue();
});

it('takes 2 weeks off a minor injury on a failure', function () {
    $save = treatSave();
    $injury = injureFor($save, 'Edge', InjurySeverity::Minor, 4);
    $ends = $injury->ends_week;

    treat($save, 'Edge', false);

    expect($injury->refresh()->ends_week)->toBe($ends - 2)
        ->and($injury->healed_week)->toBeNull();
});

it('takes 5 weeks off a serious injury on a success, 2 on a failure', function (bool $succeeds, int $cut) {
    $save = treatSave();
    $injury = injureFor($save, 'Kane', InjurySeverity::Serious, 12);
    $ends = $injury->ends_week;

    treat($save, 'Kane', $succeeds);

    expect($injury->refresh()->ends_week)->toBe($ends - $cut);
})->with([
    'success' => [true, 5],
    'failure' => [false, 2],
]);

it('never cuts past next week: a serious injury cut to nothing is back next week', function () {
    $save = treatSave();
    $injury = injureFor($save, 'Kane', InjurySeverity::Serious, 5);

    treat($save, 'Kane', true);

    $injury->refresh();
    expect($injury->ends_week)->toBe(2)
        ->and(app(InjuryStatus::class)->active(GameBuilder::wrestler($save, 'Kane'), 2))->toBeNull();
});

it('writes a story for each injury and outcome', function () {
    $save = treatSave();
    injureFor($save, 'Kane', InjurySeverity::Serious, 12);

    treat($save, 'Kane', true);

    $story = NewsEvent::where('type', NewsEventType::PromoResult)->get()
        ->first(fn (NewsEvent $e): bool => str_starts_with((string) ($e->payload['story'] ?? ''), 'speed_up_injury.'));

    expect($story)->not->toBeNull()
        ->and($story->payload['story'])->toBe('speed_up_injury.serious.success')
        ->and($story->payload['weeks_cut'])->toBe('5 weeks')
        ->and($story->payload['wrestler'])->toBe('Kane');
});

it('has the rival GM treat its injured champion first', function () {
    $save = GameBuilder::inSeason(raw: ['Batista'], smackdown: ['Edge', 'Kane', 'Carlito', 'Chris Masters'], player: Brand::Raw);
    config(['gm.ai.promos.situational_weights' => ['speed_up_injury' => 10]]);
    GameBuilder::giveTitle($save, 'world_heavyweight', ['Kane']);
    injureFor($save, 'Edge', InjurySeverity::Minor, 4);
    injureFor($save, 'Kane', InjurySeverity::Minor, 4);
    $show = Card::show($save->refresh(), Brand::Smackdown);

    app(AiPromoPlanner::class)->book($show, Brand::Smackdown);

    $treated = PromoSlot::with('promoType')->where('show_id', $show->id)->get()
        ->first(fn (PromoSlot $p): bool => $p->promoType?->key === PromoTypeKey::SpeedUpInjury);

    expect($treated?->target_wrestler_id)->toBe(GameBuilder::wrestler($save, 'Kane')->id);
});
