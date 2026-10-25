# Card Polish, Stipulation Rows & Aired-Only News

Executed **2026-10-26**.

## Why

A round of fixes from play-testing week 2. The Randomize die never visibly rolled, Re-roll blanked the modal, the proposal modal had a stray horizontal scrollbar, dead title/stipulation arrows still lit up on hover, competitor slots could be left vacant, every flash toast showed twice, the results list hid a match's stipulation, and the News page showed the week being booked before it aired.

## Randomize and Re-roll (`proposal-launcher.tsx`, `proposal-modal.tsx`)

- **The die rolls before the modal opens.** The die only tumbled while the proposal loaded, which takes a few milliseconds locally, and the modal opened over the button at the same moment. Each roll now waits for the proposal _and_ at least `MIN_ROLL_MS` (450ms), and the modal opens once the die lands. Reduced motion skips the wait (`prefersReducedMotion` is now exported from `dice.tsx`). The launcher button is disabled while it rolls.
- **Re-roll shows a spinner.** Instead of replacing the proposal with a pulsing "Rolling…", the modal shows the shared `Spinner` (`components/ui/spinner.tsx`) with **Rolling match** (Preset: **Rolling card**) in a bordered box, then the new proposal.
- **Same height as the card.** The box sits over the previous proposal, which stays in place but invisible (`invisible`, `aria-hidden`), so it matches the card exactly, including a title or special-referee line. After an error there's no earlier proposal, so a blank `PLACEHOLDER` card sizes it.
- **No horizontal scrollbar.** `.gm-panel::before` (the accent line along the top) overhangs the border by 1px, and in a panel with `overflow-y-auto` that adds 1px of scroll width. The proposal modal and the email list (the two scrolling panels) get `overflow-x-hidden`; `.gm-panel` itself isn't clipped, so popovers inside cards still show. A comment on the rule says so.

## Card builder

- **Dead arrows don't light up** (`title-tools.tsx`). The title and stipulation ◀ ▶ arrows are disabled when nothing is eligible (or the card is locked), but `hover:text-ink` still applied. They now use `enabled:hover:text-ink`.
- **No "Leave vacant" in match slots** (`wrestler-picker.tsx`, `card-builder.tsx`). `WrestlerPicker` takes `required`, which drops the clear row. Every competitor slot and the special referee use it: a match's slots are always filled, and the picker only swaps one wrestler for another (Discard, Clear and changing the match type still empty a slot). Manager pickers keep **No manager** (managers are optional); promo pickers are unchanged.

## Flash toasts show once (`gm-layout.tsx`, `use-flash-toast.ts`)

`<Toaster>` (`components/ui/sonner.tsx`) is mounted once in `app.tsx` and calls `useFlashToast()`. `GmLayout` called it too, so every flash on a GM page (signings, releases, rule errors) fired twice. The layout's call is gone, and the hook's comment says to call it only from `<Toaster>`.

## Results list shows the stipulation (`pages/gm/results.tsx`)

A match with a stipulation gets its own line below the match type: an outlined blue chip with its name (from the generated `StipulationLabels`), then the outcome once decided ("Randy Orton & Chavo Guerrero leaves Smackdown"). A pending match shows the chip alone. Matches without one get no line. The old inline "· outcome" on the match-type line is gone. The chip goes white on row hover, like the rest of the row.

## News lists aired weeks only (house rule)

`NewsComposer::weeks` used `max(current_week - 1, latest week with news)`. A signing records its news in the current week straight away, so the week being booked appeared before its shows aired. It now stops at `current_week - 1`. News recorded during the current week keeps its week and shows up once that week has fully aired, live results included. `QuietWeekNews` composes one given week, so the end-of-week filler is unaffected. Rule added to CLAUDE.md (News and email) and the `/gm/docs` News & email tab.

## Tests

- `proposal-launcher.test.tsx`: the button's die tumbles before the modal opens and stops once it's open; Re-roll shows the spinner and **Rolling match** over the hidden previous proposal, then the new one.
- `title-tools.test.tsx`: the stipulation arrows only use the enabled-only hover.
- `card-builder.test.tsx`: Divas-only lists have no "Leave vacant" row; on a Special Referee match neither competitor list nor the referee list offers it.
- `use-flash-toast.test.tsx` (new): with `GmLayout` and `<Toaster>` both mounted, one flash makes one toast. It fails with the layout's old listener.
- `results.test.tsx`: the stipulation line for a decided match (chip + outcome), none for a match without one, the chip alone for a pending match.
- `NewsComposerTest.php`: a signing in week 2 doesn't list week 2, nor does half-airing it; once it's aired, week 2 is listed with the signing. It fails on the old `weeks()`.
