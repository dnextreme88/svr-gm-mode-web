<?php

use App\Enums\Brand;
use App\Enums\EmailTemplate;
use App\Models\Email;
use App\Models\Save;
use App\Models\SaveBrand;
use App\Services\Mail\EmailService;
use App\Services\Week\Steps\TrackDeficit;
use App\Services\Week\WeekContext;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GameBuilder;

/*
| The GM inbox: category and unread filters, Mark all read and Delete all
| (scoped to the view), and per-email unread / delete. Deletes are soft.
*/

/**
 * A Raw save with a clean inbox and these emails, oldest first.
 *
 * @param  list<array{0: EmailTemplate, 1?: bool, 2?: string}>  $emails  template, read, sender
 * @return array{0: Save, 1: list<Email>}
 */
function inboxWith(array $emails): array
{
    $save = GameBuilder::inSeason(raw: ['Edge', 'Kane'], smackdown: ['Batista']);
    Email::withTrashed()->where('save_id', $save->id)->forceDelete();

    $made = [];

    foreach ($emails as $i => $email) {
        $made[] = app(EmailService::class)->send($save, Brand::Raw, $email[0], "Email {$i}", [], null, 1, $email[2] ?? null);

        if ($email[1] ?? false) {
            end($made)->update(['read_at' => now()]);
        }
    }

    return [$save, $made];
}

it('sends each email with who sent it and the inbox categories with counts', function () {
    [$save] = inboxWith([
        [EmailTemplate::MoraleComplaint, false, 'Kane'],
        [EmailTemplate::MoraleComplaint, true, 'Edge'],
        [EmailTemplate::Injury, true, 'Trainer'],
        [EmailTemplate::PpvReminder, true, 'Vince McMahon'],
        [EmailTemplate::Trade, true, 'Smackdown GM'],
        [EmailTemplate::LowCash, true],
    ]);
    // The rival's inbox doesn't count.
    app(EmailService::class)->send($save, Brand::Smackdown, EmailTemplate::Injury, 'Not ours', [], null, 1);

    $this->get(route('gm.email', $save))
        ->assertInertia(fn (Assert $page) => $page
            ->has('emails', 6)
            ->where('emails.0.sender', 'JR')
            ->where('emails.0.sender_kind', 'commentator')
            ->where('emails.1.sender_kind', 'rival_gm')
            ->where('emails.2.sender_kind', 'chairman')
            ->where('emails.3.sender_kind', 'trainer')
            ->where('emails.5.sender_kind', 'superstar')
            ->where('categories', fn ($categories) => collect($categories)->firstWhere('template', 'morale_complaint') === ['template' => 'morale_complaint', 'total' => 2, 'unread' => 1])
            ->has('categories', 5)
            ->where('filters', ['category' => null, 'view' => 'all']));
});

it('filters by category and by unread, keeping the email just opened', function () {
    [$save, $emails] = inboxWith([
        [EmailTemplate::MoraleComplaint],
        [EmailTemplate::MoraleComplaint, true],
        [EmailTemplate::Injury],
    ]);

    $this->get(route('gm.email', ['save' => $save, 'category' => 'morale_complaint']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('emails', 2)
            ->where('filters.category', 'morale_complaint')
            ->where('selected.id', $emails[1]->id));

    // Shown first but not opened (a phone opens on the list): still unread.
    $this->get(route('gm.email', $save))
        ->assertInertia(fn (Assert $page) => $page->where('selected.id', $emails[2]->id));

    expect($emails[2]->refresh()->read_at)->toBeNull();

    $this->get(route('gm.email', ['save' => $save, 'view' => 'unread', 'email' => $emails[0]->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('emails', 2)
            ->where('selected.id', $emails[0]->id));

    expect($emails[0]->refresh()->read_at)->not->toBeNull();

    $this->get(route('gm.email', ['save' => $save, 'category' => 'not_a_template']))->assertSessionHasErrors('category');
});

it('marks every unread email in the current category read', function () {
    [$save, $emails] = inboxWith([
        [EmailTemplate::MoraleComplaint],
        [EmailTemplate::MoraleComplaint],
        [EmailTemplate::Injury],
    ]);

    $this->post(route('gm.email.read-all', ['save' => $save, 'category' => 'morale_complaint']))
        ->assertRedirect(route('gm.email', ['save' => $save, 'category' => 'morale_complaint']));

    expect($emails[0]->refresh()->read_at)->not->toBeNull()
        ->and($emails[1]->refresh()->read_at)->not->toBeNull()
        ->and($emails[2]->refresh()->read_at)->toBeNull();

    $this->post(route('gm.email.read-all', $save));

    expect(Email::where('save_id', $save->id)->whereNull('read_at')->count())->toBe(0);
});

it('soft-deletes every email in the current view, and they leave the unread count', function () {
    [$save, $emails] = inboxWith([
        [EmailTemplate::MoraleComplaint],
        [EmailTemplate::MoraleComplaint, true],
        [EmailTemplate::Injury],
    ]);

    $this->delete(route('gm.email.destroy-all', ['save' => $save, 'category' => 'morale_complaint', 'view' => 'unread']));

    expect(Email::where('save_id', $save->id)->pluck('id')->all())->toBe([$emails[1]->id, $emails[2]->id])
        ->and(Email::withTrashed()->find($emails[0]->id)?->trashed())->toBeTrue();

    $this->delete(route('gm.email.destroy-all', $save));

    expect(Email::where('save_id', $save->id)->count())->toBe(0)
        ->and(Email::withTrashed()->where('save_id', $save->id)->count())->toBe(3);

    $this->get(route('gm.email', $save))
        ->assertInertia(fn (Assert $page) => $page
            ->where('game.unread_emails', 0)
            ->has('emails', 0)
            ->has('categories', 0));
});

it('marks one email unread and deletes one, opening the next', function () {
    [$save, $emails] = inboxWith([
        [EmailTemplate::Injury, true],
        [EmailTemplate::Injury, true],
    ]);

    $this->patch(route('gm.email.update', ['save' => $save, 'email' => $emails[1]]), ['read' => false])
        ->assertRedirect(route('gm.email', ['save' => $save, 'email' => $emails[1]->id]));

    expect($emails[1]->refresh()->read_at)->toBeNull();

    $this->delete(route('gm.email.destroy', ['save' => $save, 'email' => $emails[1], 'next' => $emails[0]->id]))
        ->assertRedirect(route('gm.email', ['save' => $save, 'email' => $emails[0]->id]));

    expect($emails[1]->refresh()->trashed())->toBeTrue();
});

it("won't touch another save's or the rival's emails", function () {
    [$save] = inboxWith([[EmailTemplate::Injury]]);
    $rivals = app(EmailService::class)->send($save, Brand::Smackdown, EmailTemplate::Injury, 'Not ours', [], null, 1);
    [$other, $theirs] = inboxWith([[EmailTemplate::Injury]]);

    $this->delete(route('gm.email.destroy', ['save' => $save, 'email' => $rivals]))->assertNotFound();
    $this->delete(route('gm.email.destroy', ['save' => $save, 'email' => $theirs[0]]))->assertNotFound();

    expect($rivals->refresh()->trashed())->toBeFalse()
        ->and($theirs[0]->refresh()->trashed())->toBeFalse();
});

it('still waits a month between Low on Cash emails after the last one was deleted', function () {
    [$save] = inboxWith([]);
    SaveBrand::where('save_id', $save->id)->where('brand', Brand::Raw)->update(['budget' => 500_000]);
    $step = fn (int $week) => app(TrackDeficit::class)->handle(new WeekContext($save->refresh(), $week, 1));

    $step(1);
    $this->delete(route('gm.email.destroy-all', $save));
    $step(2);
    $step(5);

    expect(Email::withTrashed()->where('save_id', $save->id)->where('template', EmailTemplate::LowCash)->pluck('week_number')->all())->toBe([1, 5]);
});
