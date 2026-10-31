# Email Inbox Redesign, Richer Emails & New Reminders

Executed **2026-10-31**. Branch `enhancements/20261031-email-inbox`.

## Why

The inbox (`/gm/saves/{#}/email`) was a bare list and reader:

- the sender only showed as a small kicker in the open email, and not at all in the list;
- there was no way to filter by category (Defection Attempt, Morale Complaint…);
- no Mark all read, no deleting, and no per-email actions.

## What landed

- **Layout:** two panes from `lg` (a 380px list and the open email), each scrolling on its own and sized to the viewport. Below `lg` it's one pane at a time: the list, or the open email (when the URL has `?email=`) with **← Inbox** back to the list, filters kept.
- **Sender:** every row shows an initials avatar, the sender (bold while unread), the category and the subject; an unread dot replaces the "Unread" pill. The open email has a sender block: avatar, name (a superstar's links to their roster page), a role line ("Raw commentator", "Chairman", "Trainer", "Rival GM", "Superstar") and the season and week. The role comes from the new `EmailSenderKind` enum, worked out from the stored sender name against `gm.email_senders` (`EmailSenderKind::fromSender`).
- **Filters:** All / Unread (`FilterGroup`, moved out of the Factions page into `components/gm/filter-group.tsx`) and a category picker (`PickerButton` / `PickerList`) listing only the categories in the inbox, with total and unread counts. Filters live in the query string (`category`, `view=unread`) and are applied on the server. The email just opened stays in the Unread view.
- **Bulk actions:** **Mark all read** (`POST email/read-all`) and **Delete all** (`DELETE email`) act on the current view. Delete all confirms with `<ConfirmDialog danger>`, naming what goes: "Delete 3 Morale Complaint emails?".
- **Per email:** **Mark as unread** (`PATCH email/{email}`) and **Delete** (`DELETE email/{email}`, with a confirmation; the inbox opens the next email in the view).
- **Soft delete:** a migration adds `emails.deleted_at` and `Email` uses `SoftDeletes`, so the inbox, the menu's unread count and the results screen's new-email count skip deleted emails. Low on Cash's once-every-4-weeks check (`TrackDeficit::warnLowCash`) reads `withTrashed()`, so clearing the inbox doesn't bring it back early. Deleted rows go when the save is deleted.
- **Interference reaction (house rule):** after the player's Interference promo airs, the rival GM emails (`EmailTemplate::InterferenceResult`, `EmailService::interferenceResult`, sent from `PromoService` beside the Slander reaction). Foiled: they gloat that their security stopped it and the player paid for nothing. Worked: they're furious about the repair bills and refunds and vow revenge.
- **Cleared to return (house rule):** the week a serious injury heals, the Trainer emails that the superstar has fully recovered and can be booked from next week (`EmailTemplate::InjuryCleared`, sent from the `InjuryReturns` week step). Minor injuries don't get one.
- **Richer email bodies (house rule):** the one-paragraph emails (morale complaints, defections, injury reports, contract and return notices, Slander and Interference reactions, trade answers, low cash, deficit, contract expired, welcome, Interference reminder) are now 2–3 short paragraphs with at least 3 variants per situation. The texts live in `EmailTexts` (like `FallbackStories`), and `EmailService::text` draws one with the save's seeded RNG (stream `email.{key}.{brand}.{who}`, by week). Subjects are unchanged.
- **Reminders every N weeks (house rule):** JR / Tazz remind the GM every N weeks since they last did the thing, and doing it restarts the clock (`SendReminders`, `gm.emails.*_reminder_weeks`):
    - Interference: now every 12 weeks (was once, after 13).
    - Slander (new): every 12 weeks without one, warning that two in 4 weeks backfires.
    - Trade (new, `EmailTemplate::TradeReminder`): every 12 weeks without a trade offer, accepted or not.
    - Free agents (new, `EmailTemplate::FreeAgentReminder`): every 16 weeks without a Free Agency signing, suggesting specialists and Legends and naming one of each who's free.

    The Slander, trade and free agent reminders go to the player only.

- All the new emails are on `/gm/docs` (News & email tab).

## Tests

- `tests/Feature/Mail/InboxTest.php`: sender kinds and category counts, category and unread filters, read-all and delete-all scoped to the view, mark unread and delete one, other saves and the rival's emails refused (404), the Low on Cash cadence after its email is deleted.
- `tests/Feature/Mail/EmailsTest.php`: the Interference reaction (worked and foiled), the Trainer's clearance (serious only), and the four reminders with their clocks restarting.
- `tests/Unit/Mail/EmailTextsTest.php`: 3+ variants per situation, no unfilled placeholders, seeded draws, multi-paragraph bodies.
- `resources/js/pages/gm/email.test.tsx`: sender block and role, list rows, the category picker, the Delete all confirmation, the empty-filter state, and the small-screen single pane.
