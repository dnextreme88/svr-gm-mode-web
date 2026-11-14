<?php

use App\Enums\Brand;
use App\Enums\EmailTemplate;
use App\Models\Email;
use App\Models\Save;
use App\Services\Attendance\SummerSlamMainEvent;
use App\Services\Week\Steps\SendReminders;
use App\Services\Week\WeekContext;
use App\Support\Text\StoryText;
use Illuminate\Support\Collection;
use Tests\Support\GameBuilder;

/*
| House rule (2026-11-11): JR / Tazz's booking tips (varied matches every 8
| weeks; Match 1 and the Main Event every 12, from week 6), and the rival
| GM's email a week before each shared PPV, polite or arrogant at even odds.
*/

function sendRemindersAt(Save $save, int $week): void
{
    app(SendReminders::class)->handle(new WeekContext($save->refresh(), $week, 1));
}

/**
 * @return Collection<int, Email>
 */
function mailOf(Save $save, EmailTemplate $template, Brand $brand = Brand::Raw)
{
    return Email::where('save_id', $save->id)->where('brand', $brand)->where('template', $template)->orderBy('id')->get();
}

it('sends the varied-matches tip every 8 weeks and the Match 1 / Main Event tip every 12, to the player only', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    foreach (range(1, 24) as $week) {
        sendRemindersAt($save, $week);
    }

    $tips = mailOf($save, EmailTemplate::BookingTip);
    $variety = $tips->filter(fn (Email $e) => $e->payload['topic'] === 'variety');
    $slots = $tips->filter(fn (Email $e) => $e->payload['topic'] === 'slots');

    expect($variety->pluck('week_number')->values()->all())->toBe([8, 16, 24])
        ->and($slots->pluck('week_number')->values()->all())->toBe([6, 18])
        ->and($tips->pluck('sender')->unique()->all())->toBe(['JR'])
        ->and($tips->every(fn (Email $e) => StoryText::fits($e->body)))->toBeTrue()
        ->and(mailOf($save, EmailTemplate::BookingTip, Brand::Smackdown))->toHaveCount(0);
});

it('has the rival GM write a week before each shared PPV, and never before a brand PPV', function () {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);

    // Backlash (week 4) is Raw-only; SummerSlam 21, Survivor Series 35, Royal Rumble 44, WrestleMania 52.
    foreach ([3, 20, 34, 43, 51] as $week) {
        sendRemindersAt($save, $week);
    }

    $emails = mailOf($save, EmailTemplate::RivalPpvTaunt);

    expect($emails->pluck('week_number')->all())->toBe([20, 34, 43, 51])
        ->and($emails->pluck('sender')->unique()->all())->toBe([config('gm.email_senders.rival_gm.smackdown')])
        ->and($emails->every(fn (Email $e) => in_array($e->payload['tone'], ['polite', 'arrogant'], true)))->toBeTrue()
        ->and($emails->every(fn (Email $e) => StoryText::fits($e->body)))->toBeTrue()
        ->and($emails[3]->body)->toContain('franchise tag')
        ->and(mailOf($save, EmailTemplate::RivalPpvTaunt, Brand::Smackdown))->toHaveCount(0);
});

it('writes polite and arrogant emails at even odds', function () {
    $tones = collect(range(1, 24))->map(function (int $i) {
        $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista'], seed: "tone-{$i}");
        sendRemindersAt($save, 43);

        return mailOf($save, EmailTemplate::RivalPpvTaunt)->sole()->payload['tone'];
    });

    expect($tones->unique()->sort()->values()->all())->toBe(['arrogant', 'polite']);
});

it('talks about the SummerSlam crowd race from the rival’s side', function (int $rawCrowd, int $smackdownCrowd, string $key) {
    $save = GameBuilder::inSeason(raw: ['Edge'], smackdown: ['Batista']);
    $this->mock(SummerSlamMainEvent::class, fn ($mock) => $mock->shouldReceive('totals')->andReturn([
        'raw' => ['crowd' => $rawCrowd, 'score' => 0],
        'smackdown' => ['crowd' => $smackdownCrowd, 'score' => 0],
    ]));

    sendRemindersAt($save, 20);

    $email = mailOf($save, EmailTemplate::RivalPpvTaunt)->sole();

    expect($email->payload['key'])->toBe($key)
        ->and($email->body)->toContain(number_format($smackdownCrowd));
})->with([
    'the rival is ahead' => [100_000, 120_000, 'summerslam.ahead'],
    'the rival is behind' => [130_000, 120_000, 'summerslam.behind'],
    'dead even' => [120_000, 120_000, 'summerslam.even'],
]);
