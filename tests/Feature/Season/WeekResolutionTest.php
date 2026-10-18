<?php

use App\Enums\Brand;
use App\Enums\ContractStatus;
use App\Enums\EmailTemplate;
use App\Exceptions\GameRuleException;
use App\Models\Contract;
use App\Models\Email;
use App\Models\SaveBrand;
use App\Models\Show;
use App\Models\WeekResolution;
use App\Services\Calendar\CalendarService;
use App\Services\Week\Steps\TickContracts;
use App\Services\Week\Steps\TrackDeficit;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekResolver;
use App\Services\Week\WeekStep;
use Tests\Support\GameBuilder;

it('advances the week and schedules the next cards', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    app(WeekResolver::class)->resolve($save);

    expect($save->refresh()->current_week)->toBe(2)
        ->and(Show::where('save_id', $save->id)->where('week_number', 1)->pluck('status')->map->value->unique()->all())->toBe(['aired'])
        ->and(Show::where('save_id', $save->id)->where('week_number', 2)->count())->toBe(2);
});

it('rolls back the whole week when a step fails', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    $failing = new class implements WeekStep
    {
        public function handle(WeekContext $context): void
        {
            throw new RuntimeException('boom');
        }
    };

    $resolver = new WeekResolver(app(CalendarService::class), [app(TickContracts::class), $failing]);

    expect(fn () => $resolver->resolveWeek($save, 1))->toThrow(RuntimeException::class);

    expect(WeekResolution::where('save_id', $save->id)->count())->toBe(0)
        ->and(Contract::where('save_id', $save->id)->where('status', ContractStatus::Active)->pluck('weeks_remaining')->unique()->all())->toBe([52])
        ->and($save->refresh()->current_week)->toBe(1);
});

it('refuses to resolve a week other than the current one', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    app(WeekResolver::class)->resolveWeek($save, 3);
})->throws(GameRuleException::class);

it('creates a PPV card alongside the weekly show in PPV weeks', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    // Backlash (Raw exclusive) is season week 4.
    foreach (range(1, 3) as $ignored) {
        app(WeekResolver::class)->resolve($save->refresh());
    }

    $shows = Show::where('save_id', $save->id)->where('week_number', 4)->get();

    expect($shows->where('brand', Brand::Raw)->count())->toBe(2)
        ->and($shows->where('brand', Brand::Smackdown)->count())->toBe(1)
        ->and($shows->firstWhere('show_type.value', 'ppv')?->name)->toBe('Backlash');
});

it('blocks the next week after too long in deficit until the GM chooses a plan', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    SaveBrand::where('save_id', $save->id)->update(['budget' => -1_000_000]);

    foreach (range(1, (int) config('gm.finances.deficit_weeks_limit')) as $ignored) {
        app(WeekResolver::class)->resolve($save->refresh());
    }

    expect(Email::where('template', EmailTemplate::Deficit)->where('brand', Brand::Raw)->count())->toBe(1)
        ->and(fn () => app(WeekResolver::class)->resolve($save->refresh()))->toThrow(GameRuleException::class);

    SaveBrand::where('save_id', $save->id)->where('brand', Brand::Raw)->update(['deficit_choice' => TrackDeficit::CHOICE_CHEAP_MATCHES]);

    app(WeekResolver::class)->resolve($save->refresh());
    expect($save->refresh()->current_week)->toBe(6);
});
