<?php

namespace App\Http\Controllers\Gm;

use App\Enums\EmailSenderKind;
use App\Enums\EmailTemplate;
use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\Save;
use App\Models\SaveWrestler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The GM inbox (§3 Email): hints and system notices from JR / Tazz and the
 * others who write in. The list filters by category (the email's template)
 * and by unread; Mark all read and Delete all act on the filtered view.
 * Deleting only soft-deletes.
 */
class EmailController extends Controller
{
    private const VIEW_UNREAD = 'unread';

    public function index(Request $request, Save $save): Response
    {
        [$category, $unreadOnly] = $this->filters($request);
        $selectedId = $request->integer('email') ?: null;

        $emails = $this->inbox($save)
            ->when($category !== null, fn (Builder $q) => $q->where('template', $category))
            // An email that was just opened (so is now read) stays listed.
            ->when($unreadOnly, fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereNull('read_at')
                ->when($selectedId !== null, fn (Builder $q) => $q->orWhere('id', $selectedId))))
            ->orderByDesc('week_number')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $selected = $emails->firstWhere('id', $selectedId ?? $emails->first()?->id);

        // Only an email the player opened is read: on a phone the inbox
        // opens on the list, so the first email isn't on screen yet.
        if ($selectedId !== null && $selected !== null && $selected->read_at === null) {
            $selected->update(['read_at' => now()]);
        }

        $categories = $this->inbox($save)
            ->selectRaw('template, COUNT(*) as total, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread')
            ->groupBy('template')
            ->get()
            ->map(fn (Email $e): array => [
                'template' => $e->template->value,
                'total' => (int) $e->getAttribute('total'),
                'unread' => (int) $e->getAttribute('unread'),
            ])
            ->values();

        return Inertia::render('gm/email', [
            'emails' => $emails->map(fn (Email $e): array => [
                'id' => $e->id,
                'template' => $e->template->value,
                'sender' => $e->sender,
                'sender_kind' => EmailSenderKind::fromSender($e->sender)->value,
                'subject' => $e->subject,
                'season' => $e->season,
                'week_number' => $e->week_number,
                'read' => $e->read_at !== null,
            ])->values(),
            'selected' => $selected === null ? null : [
                'id' => $selected->id,
                'template' => $selected->template->value,
                'sender' => $selected->sender,
                'sender_kind' => ($kind = EmailSenderKind::fromSender($selected->sender))->value,
                'sender_wrestler_id' => $kind === EmailSenderKind::Superstar ? ($selected->payload['save_wrestler_id'] ?? null) : null,
                'subject' => $selected->subject,
                'body' => $selected->body,
                'payload' => $selected->payload,
                'season' => $selected->season,
                'week_number' => $selected->week_number,
            ],
            'categories' => $categories,
            'filters' => [
                'category' => $category?->value,
                'view' => $unreadOnly ? self::VIEW_UNREAD : 'all',
            ],
            // Every name in the save, so the inbox can colour mentions by brand.
            'wrestlers' => SaveWrestler::with('wrestler')
                ->where('save_id', $save->id)
                ->get()
                ->map(fn (SaveWrestler $w): array => [
                    'name' => $w->name(),
                    'brand' => $w->brand?->value,
                ])
                ->values(),
        ]);
    }

    /**
     * Mark all read: every unread email in the current view.
     */
    public function readAll(Request $request, Save $save): RedirectResponse
    {
        [$category] = $this->filters($request);

        $this->inbox($save)
            ->when($category !== null, fn (Builder $q) => $q->where('template', $category))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->back($save, $request);
    }

    /**
     * Delete all: every email in the current view.
     */
    public function destroyAll(Request $request, Save $save): RedirectResponse
    {
        [$category, $unreadOnly] = $this->filters($request);

        $this->inbox($save)
            ->when($category !== null, fn (Builder $q) => $q->where('template', $category))
            ->when($unreadOnly, fn (Builder $q) => $q->whereNull('read_at'))
            ->delete();

        return $this->back($save, $request);
    }

    /**
     * Mark one email read or unread.
     */
    public function update(Request $request, Save $save, Email $email): RedirectResponse
    {
        $this->assertInbox($save, $email);
        $read = $request->validate(['read' => ['required', 'boolean']])['read'];

        $email->update(['read_at' => $read ? ($email->read_at ?? now()) : null]);

        return $this->back($save, $request, $email->id);
    }

    /**
     * Delete one email; the inbox opens the next one in the view.
     */
    public function destroy(Request $request, Save $save, Email $email): RedirectResponse
    {
        $this->assertInbox($save, $email);
        $email->delete();

        return $this->back($save, $request, $request->integer('next') ?: null);
    }

    /**
     * The player brand's emails.
     *
     * @return Builder<Email>
     */
    private function inbox(Save $save): Builder
    {
        return Email::query()->where('save_id', $save->id)->where('brand', $save->player_brand);
    }

    private function assertInbox(Save $save, Email $email): void
    {
        abort_unless($email->brand === $save->player_brand, 404);
    }

    /**
     * @return array{0: EmailTemplate|null, 1: bool}
     */
    private function filters(Request $request): array
    {
        $request->validate([
            'category' => ['nullable', Rule::enum(EmailTemplate::class)],
            'view' => ['nullable', Rule::in(['all', self::VIEW_UNREAD])],
        ]);

        return [
            EmailTemplate::tryFrom((string) $request->input('category')),
            $request->input('view') === self::VIEW_UNREAD,
        ];
    }

    private function back(Save $save, Request $request, ?int $email = null): RedirectResponse
    {
        return redirect()->to(route('gm.email', [
            'save' => $save->id,
            ...array_filter([
                'category' => $request->input('category'),
                'view' => $request->input('view') === self::VIEW_UNREAD ? self::VIEW_UNREAD : null,
                'email' => $email,
            ]),
        ]));
    }
}
