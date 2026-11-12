# UI/UX audit fixes

## Context

An audit of all 20 GM pages at 1440, 768 and 390px wide found that pages don't overflow sideways and don't shift as they load, but four things were wrong:

- most buttons gave no sign that a request was in flight;
- keyboard focus was invisible on the main buttons;
- on phones, actions were hidden and a lot of space went to chrome;
- some information was only in hover tooltips.

This change fixes all of the audit's findings except the broadcast look itself (gradients, panel shadows, large uppercase titles). That look is a deliberate style choice, not a defect.

## Loading and errors

- **Busy buttons.** `useBusy()` (`lib/use-busy.ts`), `<ActionButton>` and `<ConfirmDialog busy busyText>`. Every button that sends a request shows a spinner and a verb, sets `aria-busy`, and can't be sent twice. It is wired into:
    - Sign and Re-sign;
    - Send offer;
    - Save tags and Run the re-draft;
    - Random pick and Lock in entrants;
    - the deficit choice;
    - Continue / Unlock card and Cash in;
    - every email action;
    - Disband, delete / discard career and Release;
    - title assignment (Change, Leave vacant, Award title);
    - the title-vacancy and notice pop-ups.
- **Native confirms.** Release (superstar page, expiring contracts email) and delete career no longer use the browser's `confirm()`. They use `<ReleaseButton>` and `DeleteSaveButton`, both built on `<ConfirmDialog danger>`.
- **Error page.** `bootstrap/app.php` renders `gm/error` for 403 and 404, and for 500 and 503 outside debug mode. It has the GM look, Back to GM Mode and Go back. JSON callers keep JSON.
- **Title shot reminder.** "Book it now" stays open while it books, and says why if it fails. It used to close and fail silently.
- **Progress bar.** The Inertia progress bar is the arena red instead of grey on near-black.

## Keyboard and screen readers

- `.gm-btn`'s focus ring is drawn on its slanted shape, so it's visible.
- **Square-box flash.** Slanted buttons briefly showed their uncut square box, a few seconds after the mouse left them (seen on Air week). The slant used to be cut out of a rectangle with `clip-path`, and Chrome could paint one frame without the cut when it moved the button between GPU layers. The slant is now a skewed `::before` behind the label. Hover is an inset tint, not a `filter`, and buttons no longer animate. That includes the Disband button's fade-in.
- The promo tiles move with the arrow keys.
- Taken "airs before" spots say which promo holds them.
- The pool filter chips report their pressed state.

## Phones

- 16px page margins.
- The header's three stats share one row in small type.
- Free Agency keeps Sign stuck on screen (`gm-table-action`), and Rosters and Free Agency no longer nest a second scroll box.
- Reports and faction match tables become two-line rows below `md`.
- The attendance chart keeps its full size and scrolls sideways.
- The Docs tabs scroll in one row and keep the chosen tab in view.
- `gm-btn-sm` is 44px tall on touch screens, and so is the delete-career icon.

## No hover-only information

- Why a promo is unavailable is written on its tile; on a phone the tile collapses to one line plus the reason.
- The results attendance chip opens its draw-score breakdown on click or tap.
- Air week says "Choose a deficit plan on Financials first" next to the button.
- The header shows the attendance's week.
- The expiring contracts email names the titles.

## Text and layout

- **Outcome card.** After a challenger's DQ or count-out win in a title match, "And still…" adds who kept the title.
- **News headlines** are never truncated.
- **Title History** title names wrap inside their card at tablet width.
- **Plurals.** "N new email(s)", "signing(s)" and "week(s)" are now proper plurals.
- **Short panels** keep their own height instead of stretching beside a taller one: Saved careers, the news cards, the superstar page and Financials.
- **Trade.** The rival GM's scoring rules are folded into a disclosure, so the picker starts on the first screen.
- **Deferred systems.** The empty Stars, Fan support, Rating and Popularity placeholders are hidden until those systems exist.
- **Tables.** Rivalry durations and the Reports position column are right-aligned.
- **Browser tab titles** use `APP_NAME="SvR 2006 GM Mode"` instead of "Laravel".
- **Loading overlays.** The season-start and draft-end overlays pause 2.5 seconds the first time a player sees each one, then 0.8 seconds.

## Verification

- Vitest:
    - `outcome-card.test.tsx`, `title-shot-reminder.test.tsx` and `loading-overlay.test.ts` (new);
    - attendance chip, promo slots, booking steps and title assignment (updated).
- Pest: `ErrorPageTest`.
- `composer ci:check`.
