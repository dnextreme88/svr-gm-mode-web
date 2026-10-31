<?php

use App\Enums\Brand;
use App\Enums\ContractLength;
use App\Enums\ContractStatus;
use App\Enums\EmailTemplate;
use App\Enums\InjurySeverity;
use App\Enums\PromoTypeKey;
use App\Enums\SaveStage;
use App\Models\Contract;
use App\Models\Email;
use App\Models\InjuryType;
use App\Models\PromoType;
use App\Models\Save;
use App\Models\SaveBrand;
use App\Models\SaveWrestler;
use App\Services\Contracts\ContractService;
use App\Services\Injuries\InjuryService;
use App\Services\Promos\PromoService;
use App\Services\Roster\TradeService;
use App\Services\Titles\ChampionshipAssignment;
use App\Services\Week\Steps\InjuryReturns;
use App\Services\Week\Steps\SendReminders;
use App\Services\Week\Steps\TrackDeficit;
use App\Services\Week\WeekContext;
use App\Services\Week\WeekResolver;
use Tests\Support\Card;
use Tests\Support\GameBuilder;

/*
| GM emails from JR/Tazz, the rival GM, Vince McMahon, the Trainer and the
| superstars themselves.
*/

function mailWeek(Save $save): void
{
    app(WeekResolver::class)->resolve($save->refresh());
}

function runStep(string $step, Save $save, int $week): void
{
    app($step)->handle(new WeekContext($save->refresh(), $week, 1));
}

function inbox(Save $save, EmailTemplate $template, Brand $brand = Brand::Raw)
{
    return Email::where('save_id', $save->id)->where('brand', $brand)->where('template', $template)->orderBy('id')->get();
}

it('welcomes the player when the first season starts', function () {
    $save = GameBuilder::newSave(Brand::Smackdown);
    $save->update(['stage' => SaveStage::AssigningTitles]);

    app(ChampionshipAssignment::class)->complete($save->refresh());

    $welcome = inbox($save, EmailTemplate::Welcome, Brand::Smackdown)->sole();

    expect($welcome->sender)->toBe('Tazz')
        ->and($welcome->subject)->toBe('Welcome to Smackdown')
        ->and($welcome->body)->toContain('If you ever need help, check your email')
        ->and(inbox($save, EmailTemplate::Welcome))->toHaveCount(0);
});

it('sends the specialties tip at season weeks 1 and 27 only', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    foreach ([1, 2, 26, 27, 28] as $week) {
        runStep(SendReminders::class, $save, $week);
    }

    expect(inbox($save, EmailTemplate::SpecialtyTip)->pluck('week_number')->all())->toBe([1, 27])
        ->and(inbox($save, EmailTemplate::SpecialtyTip, Brand::Smackdown))->toHaveCount(2);
});

it('can name one of your specialists in the specialties tip', function (float $chance, bool $mentioned) {
    config(['gm.emails.specialty_mention_chance' => $chance]);
    $save = GameBuilder::inSeason(raw: ['Rob Van Dam'], smackdown: ['Batista']);

    runStep(SendReminders::class, $save, 1);

    $body = inbox($save, EmailTemplate::SpecialtyTip)->sole()->body;

    expect($body)->toContain('two specialists in the same match')
        ->and(str_contains($body, 'For example, Rob Van Dam is a'))->toBe($mentioned);
})->with([
    'always' => [1.0, true],
    'never' => [0.0, false],
]);

/**
 * The weeks a reminder went out, for one kind of promo reminder or template.
 *
 * @return list<int>
 */
function reminderWeeks(Save $save, EmailTemplate $template, ?string $promo = null): array
{
    return inbox($save, $template)
        ->filter(fn (Email $e) => $promo === null || ($e->payload['promo'] ?? null) === $promo)
        ->pluck('week_number')->values()->all();
}

it('reminds about Interference every 12 unused weeks and restarts the clock after use', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

    foreach ([11, 12, 13, 24] as $week) {
        runStep(SendReminders::class, $save, $week);
    }

    expect(reminderWeeks($save, EmailTemplate::PromoReminder, 'interference_promotion'))->toBe([12, 24]);

    // Air an Interference promo in week 1: the next reminders are due at weeks 13 and 25.
    Email::query()->delete();
    app(PromoService::class)->book(Card::show($save), 1, PromoType::where('key', PromoTypeKey::InterferencePromotion)->firstOrFail(), GameBuilder::wrestler($save, 'Kane')->id);
    mailWeek($save);

    foreach ([12, 13, 25] as $week) {
        runStep(SendReminders::class, $save, $week);
    }

    expect(reminderWeeks($save, EmailTemplate::PromoReminder, 'interference_promotion'))->toBe([13, 25]);
});

it('reminds the player about Slander every 12 unused weeks and restarts the clock after use', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);

    foreach ([12, 24] as $week) {
        runStep(SendReminders::class, $save, $week);
    }

    $emails = inbox($save, EmailTemplate::PromoReminder)->filter(fn (Email $e) => $e->payload['promo'] === 'slander_promotion')->values();

    expect($emails->pluck('week_number')->all())->toBe([12, 24])
        ->and($emails[0]->sender)->toBe('JR')
        ->and($emails[0]->body)->toContain('12 weeks')
        ->and(inbox($save, EmailTemplate::PromoReminder, Brand::Smackdown)->filter(fn (Email $e) => $e->payload['promo'] === 'slander_promotion'))->toHaveCount(0);

    Email::query()->delete();
    app(PromoService::class)->book(Card::show($save), 1, PromoType::where('key', PromoTypeKey::SlanderPromotion)->firstOrFail());
    mailWeek($save);

    foreach ([12, 13] as $week) {
        runStep(SendReminders::class, $save, $week);
    }

    expect(reminderWeeks($save, EmailTemplate::PromoReminder, 'slander_promotion'))->toBe([13]);
});

it('reminds the player to propose a trade every 12 weeks without one', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian'], smackdown: ['Batista', 'JBL']);

    foreach ([11, 12, 24] as $week) {
        runStep(SendReminders::class, $save, $week);
    }

    expect(reminderWeeks($save, EmailTemplate::TradeReminder))->toBe([12, 24])
        ->and(inbox($save, EmailTemplate::TradeReminder)[0]->body)->toContain('Smackdown');

    // An offer in week 5, accepted or not, restarts the clock.
    Email::query()->delete();
    $save->update(['current_week' => 5]);
    app(TradeService::class)->propose($save->refresh(), Brand::Raw, [GameBuilder::wrestler($save, 'Kane')->id], [GameBuilder::wrestler($save, 'Batista')->id]);

    foreach ([12, 17] as $week) {
        runStep(SendReminders::class, $save, $week);
    }

    expect(reminderWeeks($save, EmailTemplate::TradeReminder))->toBe([17]);
});

it('reminds the player to sign specialists and Legends every 16 weeks without a signing', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    foreach ([15, 16, 32] as $week) {
        runStep(SendReminders::class, $save, $week);
    }

    $emails = inbox($save, EmailTemplate::FreeAgentReminder);

    expect($emails->pluck('week_number')->all())->toBe([16, 32])
        ->and($emails[0]->sender)->toBe('JR')
        ->and($emails[0]->body)->toContain('Legends');

    // A signing in week 3 restarts the clock.
    Email::query()->delete();
    $free = SaveWrestler::where('save_id', $save->id)->whereNull('brand')->orderBy('id')->firstOrFail();
    app(ContractService::class)->signFreeAgent($save, $free, Brand::Raw, ContractLength::cases()[0], 3);

    foreach ([16, 19] as $week) {
        runStep(SendReminders::class, $save, $week);
    }

    expect(reminderWeeks($save, EmailTemplate::FreeAgentReminder))->toBe([19]);
});

it('has the rival GM answer a trade, listing one superstar per line', function (float $accept, string $subject, string $opening) {
    config(['gm.ai.trades.fair_accept' => $accept, 'gm.ai.trades.fair_ahead_accept' => [], 'gm.ai.trades.unfair_accept' => $accept]);
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane', 'Christian'], smackdown: ['Batista', 'JBL']);

    app(TradeService::class)->propose($save, Brand::Raw, [GameBuilder::wrestler($save, 'Kane')->id, GameBuilder::wrestler($save, 'Christian')->id], [GameBuilder::wrestler($save, 'Batista')->id]);

    $email = inbox($save, EmailTemplate::Trade)->sole();

    expect($email->sender)->toBe('Smackdown GM')
        ->and($email->subject)->toBe($subject)
        ->and($email->body)->toContain($opening)
        ->and($email->body)->toEndWith("\n\nKane\nChristian\n\nfor\n\nBatista")
        ->and(inbox($save, EmailTemplate::Trade, Brand::Smackdown))->toHaveCount(0);
})->with([
    'accepted' => [1.0, 'Trade accepted', 'accept your trade'],
    'declined' => [0.0, 'Trade declined', 'decline your trade'],
]);

it('has Vince remind both brands 2 weeks before each shared PPV', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    // Backlash (week 4) is Raw-only; SummerSlam 21, Survivor Series 35, Royal Rumble 44, WrestleMania 52.
    foreach ([2, 19, 33, 42, 50] as $week) {
        runStep(SendReminders::class, $save, $week);
    }

    $raw = inbox($save, EmailTemplate::PpvReminder);

    expect($raw->pluck('week_number')->all())->toBe([19, 33, 42, 50])
        ->and($raw->pluck('sender')->unique()->all())->toBe(['Vince McMahon'])
        ->and($raw[0]->body)->toContain('most fans')
        ->and($raw[1]->body)->toContain('Traditional 3 vs 3')
        ->and($raw[2]->body)->toContain('automatic world title shot')
        ->and($raw[3]->body)->toContain('immunity tag')
        ->and(inbox($save, EmailTemplate::PpvReminder, Brand::Smackdown))->toHaveCount(4);
});

it('has a superstar email the GM 2 weeks before their contract runs out', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    Contract::where('save_wrestler_id', GameBuilder::wrestler($save, 'Edge')->id)->where('status', ContractStatus::Active)->update(['weeks_remaining' => 3]);

    mailWeek($save);

    $email = inbox($save, EmailTemplate::ContractReminder)->sole();

    expect($email->sender)->toBe('Edge')
        ->and($email->subject)->toBe('My contract is up in 2 weeks');
});

it('warns about low cash at $500,000 or less, once a month', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    SaveBrand::where('save_id', $save->id)->where('brand', Brand::Raw)->update(['budget' => 500_000]);

    foreach ([1, 2, 4, 5] as $week) {
        runStep(TrackDeficit::class, $save, $week);
    }

    $emails = inbox($save, EmailTemplate::LowCash);

    expect($emails->pluck('week_number')->all())->toBe([1, 5])
        ->and($emails[0]->sender)->toBe('JR')
        ->and($emails[0]->subject)->toContain('$500,000')
        ->and(inbox($save, EmailTemplate::LowCash, Brand::Smackdown))->toHaveCount(0);
});

it('has the Trainer report an injury with the superstar, injury and time out', function (InjurySeverity $severity, string $outlook) {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $type = InjuryType::where('severity', $severity)->orderBy('id')->firstOrFail();

    $injury = app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Edge'), $severity, 1, $save->rng('test'), type: $type);

    $email = inbox($save, EmailTemplate::Injury)->sole();
    $weeks = $injury->durationWeeks() - 1;

    expect($email->sender)->toBe('Trainer')
        ->and($email->body)->toContain('Edge')
        ->and($email->body)->toContain("suffered a {$type->label}")
        ->and($email->body)->toContain($outlook)
        ->and($email->body)->toContain($weeks === 1 ? '1 week' : "{$weeks} weeks");
})->with([
    'minor' => [InjurySeverity::Minor, 'They can still compete'],
    'serious' => [InjurySeverity::Serious, "They can't compete"],
]);

it('has a seriously injured superstar say they are 2 weeks from returning', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $injury = app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Edge'), InjurySeverity::Serious, 1, $save->rng('test'));
    $noticeWeek = $injury->ends_week - 3;

    runStep(SendReminders::class, $save, $noticeWeek - 1);
    runStep(SendReminders::class, $save, $noticeWeek);
    runStep(SendReminders::class, $save, $noticeWeek + 1);

    $email = inbox($save, EmailTemplate::InjuryReturning)->sole();

    expect($email->week_number)->toBe($noticeWeek)
        ->and($email->sender)->toBe('Edge')
        ->and($email->subject)->toBe("I'll be back in 2 weeks");
});

it('has the rival GM react to a Slander promo', function (float $rate, string $subject, string $phrase) {
    config(['gm.promos.success_rates.slander_promotion' => $rate]);
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    app(PromoService::class)->book(Card::show($save), 1, PromoType::where('key', PromoTypeKey::SlanderPromotion)->firstOrFail());

    mailWeek($save);

    $email = inbox($save, EmailTemplate::SlanderResult)->sole();

    expect($email->sender)->toBe('Smackdown GM')
        ->and($email->subject)->toBe($subject)
        ->and($email->body)->toContain($phrase);
})->with([
    'success' => [1.0, 'You just made an enemy', 'I will get you back'],
    'failure' => [0.0, 'Nice try', 'good laugh'],
]);

it('has the rival GM react to an Interference promo', function (float $rate, string $subject, string $phrase) {
    config(['gm.promos.success_rates.interference_promotion' => $rate]);
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    app(PromoService::class)->book(Card::show($save), 1, PromoType::where('key', PromoTypeKey::InterferencePromotion)->firstOrFail(), GameBuilder::wrestler($save, 'Edge')->id);

    mailWeek($save);

    $email = inbox($save, EmailTemplate::InterferenceResult)->sole();

    expect($email->sender)->toBe('Smackdown GM')
        ->and($email->subject)->toBe($subject)
        ->and($email->body)->toContain($phrase);
})->with([
    'success' => [1.0, 'You owe me, and I always collect', 'pay for it with interest'],
    'failure' => [0.0, "Is that the best you've got?", "hasn't stopped laughing"],
]);

it('has the Trainer clear a seriously injured superstar to return, but not a minor one', function () {
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    $serious = app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Edge'), InjurySeverity::Serious, 1, $save->rng('test'));
    $minor = app(InjuryService::class)->injure(GameBuilder::wrestler($save, 'Kane'), InjurySeverity::Minor, 1, $save->rng('test'));

    runStep(InjuryReturns::class, $save, $minor->ends_week - 1);
    runStep(InjuryReturns::class, $save, $serious->ends_week - 2);
    runStep(InjuryReturns::class, $save, $serious->ends_week - 1);

    $email = inbox($save, EmailTemplate::InjuryCleared)->sole();

    expect($email->week_number)->toBe($serious->ends_week - 1)
        ->and($email->sender)->toBe('Trainer')
        ->and($email->subject)->toBe('Edge is cleared to return')
        ->and($email->body)->toContain("full recovery from the {$serious->type->label}");
});
