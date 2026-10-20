# Card booking UI overhaul, Special Referee, and promo rework

## Context

The `/gm/saves/{save}/shows/{show}` card page (`resources/js/pages/gm/card.tsx` + `components/gm/card-builder.tsx`) is hard to use: native `<select>`s for match type and every wrestler slot, the title cycler is buried under the slot, Clear looks like every other button, and there is no visual progress. While reviewing it the user also asked to:

- make **Special Referee** matches bookable on weekly shows, with a referee pick;
- rework **promos**: a picker grid instead of a dropdown, greyed-out unavailable promos, "Success Probability:" labels, a placement ("Airs before…") input, fatigue on two promos, four new promos, and a Promos tab on `/gm/docs`.

Decisions already made with the user:

- Progress bar: **A · smooth fill + sheen** (brand colour).
- Manager slot uses the same picker as wrestlers, with a `MANAGER` label styled like `CAPTAIN`.
- Every Clear button on the page (match and promo) is red.
- Special Referee: add a **referee pick** (stored as a participant, sim ignores it, no fatigue).
- Promo rules: the recommended availability rules plus the user's additions (below). "Rivalry (in place of a match)" is dropped.
- New promo values: Title Match Hype −$10,000 / 80%, Comeback Reminder −$2,000 / 90%, Interview Space −$2,000 / 90%, Diva Bikini Contest −$10,000 / 85% (config, flagged _(assumption)_).
- Promo placement: per promo slot, **Before Match 1 … Before Match N−1, Before Main Event**, defaulting to Before Main Event. Fatigue and injury for a promo are applied at that point in the show.

## Setup

- `git pull` on `main`, then `git checkout -b enhancements/20261020-card-booking-ui-and-promos`. The existing uncommitted `text-xs` removals in `card-builder.tsx` come along and are kept.
- Plan doc: `docs/20261020-card-booking-ui-special-referee-and-promos.md`, plus a `docs/README.md` entry with the bolded date.
- Don't commit until the user asks.

## Part 1: Shared UI pieces

- **`components/gm/popover.tsx`** (new): `usePopover()` returns `{ open, state, toggle, close, ref }`. It closes on outside `pointerdown` and on `Escape`, and stays mounted while `data-state="closed"` plays a fade-out. It unmounts on `animationend`, with a 200 ms timeout fallback for jsdom and reduced motion. `<PopoverPanel>` is an absolutely positioned `gm-panel` with `animate-gm-pop-in` / `animate-gm-pop-out`.
- **`gm.css`**: add the `gm-pop-in`/`gm-pop-out` keyframes (opacity plus a 4px rise, ~160 ms) to `@theme`. In `@layer components`, add:
    - `.gm-btn-danger`: `--color-gm-red-deep` background, white text. Hover uses an explicit `#e5233f` with `filter: none`, so it stays readable on both brands.
    - `.gm-sheen`: a `::after` light sweep, 2.8 s loop. The existing reduced-motion block already tames it.
- **`components/gm/wrestler-picker.tsx`** (new): a button showing `<WrestlerName>`, or `VACANT` (faint, italic, `gm-label`) when empty. Clicking it fades in a scrollable list (`gm-scroll`, max-h ~18rem) of option buttons. Each row shows `<WrestlerName>`, the blocked reason or minor injury, and is disabled when not bookable or already in the match. The first row is "Leave vacant", or `emptyLabel` (e.g. "No manager"). Props: `value`, `options`, `disabled(id)`, `note(id)`, `emptyLabel`, `aria-label`. The match card and the promo editor both use it.
- **`components/gm/dice-icon.tsx`** (new): an inline SVG die (white face, black outline and pips, `aria-hidden`). `ProposalLauncher` gets an optional `icon` prop rendered before the label. The accessible name stays "Randomize".

## Part 2: Card builder (items 1–8)

Files: `components/gm/card-builder.tsx`, `components/gm/title-tools.tsx`, `pages/gm/card.tsx`, `lib/card.ts`.

1. **Clear** → `gm-btn gm-btn-danger gm-btn-sm` (match and promo).
2. **Chyron** "Match N" / "Main event": keep the `text-xs` removal and add `py-1 pl-3 pr-7`. Utilities beat the layer's `padding-right`, so the global `.gm-chyron` used elsewhere stays untouched.
3. **`MatchTypePicker`** (in card-builder): the trigger button (`aria-label="Match type for match N"`, `aria-expanded`) opens a popover with two columns:
    - the family list (`MatchFamilyLabels`, in `groupByFamily` order);
    - on click, the selected family's variants fade in, each showing its variant name, cost and `<FatigueCost>`.

    Picking a variant closes the popover. Below the trigger, `data-testid="match-type-path-N"` reads `Table → Tornado 6-Man Tag` (family label → variant).

4. **Wrestler slots** use `<WrestlerPicker>`, with eligibility as today's `eligible()` plus the existing disable rules. Read-only slots still show the name. The **Manager** slot uses the picker too ("No manager"), with a `MANAGER` label using the captain's classes (`gm-label mb-1 text-gold`).
5. **`<CardProgress>`**: label "X of Y matches booked" above a 10px track. The `bg-brand` fill uses `transition-[width] duration-600` plus `gm-sheen`, with `role="progressbar"` and `aria-valuenow/min/max`.
6. Per-slot **Randomize** passes `icon={<DiceIcon />}`.
7. **Layout swap**: `TitleTools` splits into `<TitleCycler>` (arrows and chip kept, `min-w-56`) and `<StipulationPicker>`.
    - Header: chyron · match type picker (with the path below) · cost/fatigue · `TitleCycler` at `ml-auto`.
    - Footer (one row): **Save match N · Clear · Stipulation · Randomize**.
    - `CardBuilder`'s `renderSlotTools` becomes `renderTitle(draft, update)` and `renderActions(draft, update)`.
8. **Cost**: `text-zone-safe`; "Free" also gets `italic`. This applies in the type info and in the picker rows.

## Part 3: Special Referee (§10)

- Seeder `database/seeders/data/match_types.php`: drop `gm_bookable => false` from the five `special_referee` rows and update the header comment.
- Add `ParticipantRole::Referee`. `MatchType::hasSpecialReferee()` returns `family === SpecialReferee`, and `CardPresenter::matchType` exposes `special_referee`.
- Booking: add `referee_id` to the request validation, the booking DTO, `CardValidator` and `CardService`.
    - The referee is required if and only if the type is a special referee match.
    - The referee must be on your roster, bookable (not seriously injured), and not a competitor or manager in that match.
    - Stored as a participant with `role=referee`, `side=0`.
- `CardPresenter::slot` and the results presenter return `referee_id` / the referee's name. Check that `MatchResolver`, `ShowResolver` and the outcome handler only treat `competitor`s as competitors (the referee gets no fatigue, injury or record). The referee still counts as "used" via the existing participants loop.
- `CardRandomizer` and `AiGm` pick a random eligible referee for these types.
- Frontend: `SlotDraft.refereeId`, `CardSlot.referee_id`, `BookingPayload.referee_id`, `MatchTypeOption.special_referee`. A `SPECIAL REFEREE` label plus a `<WrestlerPicker>` under the sides. `isComplete` requires it.
- `php artisan gm:types` after the enum change.

## Part 4: Promos backend (§5)

- **Migration** (schema only):
    - `promo_slots`: `before_position` (unsigned tinyint, nullable) and `target_championship_id` (nullable FK to championships).
    - `promo_types`: `needs_championship` (bool), `needs_diva_pair` (bool), `fatigue_bars` (tinyint) and `requirement` (string: player-facing text for the grid and docs).
- **`PromoTypeKey`**: add `TitleMatchHype`, `ComebackReminder`, `InterviewSpace`, `DivaBikiniContest`.
- **`PromoTypeSeeder`**: add the four new rows. Interference gets `wrestler: true`, plus `fatigue_bars` 2 for Interference and Start a Rivalry. Every row gets a `requirement` string.
- **`config/gm.php` `promos`**:
    - new `success_rates` and `amounts` entries;
    - `fatigue_bars` (interference 2, start_rivalry 2);
    - `injury_return_window_weeks` 2 _(assumption)_;
    - `comeback_reminder_min_weeks_out` 3.
- **`app/Services/Promos/PromoEligibility.php`** (new) is the single source for the UI and validation. `forShow(Show, Brand)` returns, per type, `{available, reason, wrestler_ids, pairs?, championship_ids}`:

| Promo | Available when / targets |
| --- | --- |
| New Superstar | wrestlers whose `joined_brand_week` is within the window |
| Continue Rivalry | active rivalries with both wrestlers on the brand. Second pick limited to the first pick's rivals |
| Start a Rivalry | ≥2 wrestlers not in any active rivalry and not seriously injured |
| Inactive Superstar | seriously injured wrestlers |
| Injury Return | a serious injury healed within `injury_return_window_weeks` |
| Comeback Reminder | seriously injured and out more than 3 weeks (`week − started_week > 3`) |
| Interview Space | any wrestler not seriously injured |
| Diva Bikini Contest | ≥2 Divas not seriously injured |
| Interference Promotion | a non-Diva who isn't seriously injured |
| Title Match Hype | championships with a **saved** title match on this card |
| Advertising, Merch, Main Event Hype, PPV Hype, Slander | always |

- **`PromoService::book`**:
    - validates through `PromoEligibility`;
    - accepts `target_championship_id` and `before_position` (1…`match_slot_count`, default `match_slot_count` = Before Main Event);
    - `CardController` validation and the `promo_slots`/`promo_types` props carry the new fields plus an `eligibility` map.
    - `AiGm::bookPromos` is unaffected (default placement).
- **Placement in `ShowResolver::air`**: before airing match slot N, resolve the promos with `before_position = N` in slot order (`PromoService::resolveSlot`, same `promos.{streamKey}` RNG stream). Leftovers (e.g. no booked slot) resolve at the end.
- **Promo fatigue**: when a promo with `fatigue_bars > 0` airs, each target's fatigue goes up by `bars × bar_size`, success or fail. Then roll injury with that going-in fatigue: refactor `InjuryService::rollForMatch`'s core into `rollFor(wrestler, goingIn, fatigueCost, …)` and add `rollForPromo`.
- **Title Match Hype fizzles**: if the title match has been removed by air time, the cost is still paid and the note reads "No title match on the card" _(assumption)_.
- **New promo effects**: recorded outcome only (popularity and rating are deferred; no formulas).

## Part 5: Promos frontend

`components/gm/promo-slots.tsx`, `pages/gm/card.tsx`, `types/gm.ts`:

- Each `Promo N` panel:
    - A 2-column grid of promo cards, sorted alphabetically by name, filling left to right. Each card shows the name, the cost/profit, `Success Probability: 75%`, and `<FatigueCost>` when it has fatigue.
    - Unavailable cards are greyed (`opacity-40`, `aria-disabled`, reason as `title` and sr text).
    - The selected card gets a brand ring.
- Picking a card fades in a details element with:
    - the effect text;
    - the required inputs: `<WrestlerPicker>`s filtered to the eligible ids (Continue Rivalry's second picker limited to rivals), or a championship chip list for Title Match Hype;
    - an **Airs** picker (Before Match 1 … Before Main Event, defaulting to Before Main Event).
- Save and red Clear on one row.
- `projectedFatigue` in `lib/card.ts` takes the **saved** fatiguing promos with their placement, so match fatigue bars reflect them.

## Part 6: Docs and spec

- `/gm/docs`:
    - `DocsController` sends `promo_types` (name, amount, rate, fatigue_bars, requirement, effect).
    - `docs.tsx` gets a **Promos** tab: placement rules, then one row per promo with Cost/Profit, Success probability, Fatigue (`<FatigueCost>`), Requires and Effect.
    - A "Special referee" description goes on the Match rules card, and Special Referee types now show as bookable.
- `docs/20261017-spec.md`:
    - §5: new rows, Interference/Start a Rivalry targets and fatigue, the availability table, placement, and the fizzle rule;
    - §10: Special Referee is bookable with a referee pick, flagged _(assumption)_ where noted.

## Follow-ups (same day)

- Title cycler: 300px minimum width so long title names don't shift the row.
- Match costs repriced per family and per variant (spec §10 price list). Family defaults stay in `config('gm.match_type_defaults')`; variants override `cost` in `database/seeders/data/match_types.php`. Table Tornado 6-Man Tag uses $8,000, the same as Table Triple Threat Tornado Tag _(assumption)_.
- A title can only be picked once per card: `titleCandidates()` skips titles other drafts already hold (the backend already rejected it on save).
- Unsaved slots get an orange tint, an outline and an "Unsaved changes" label. **Discard** restores the last saved booking. **Clear** asks for confirmation (`ConfirmDialog` with `danger`). Save shows a blue floppy icon.
- Header alignment fixed (match type picker, cost/fatigue and title share a centre line).
- The action row reads Save · Discard · Clear · Randomize · Stipulation. Stipulation is now an arrows-and-chip cycler like the title (250px minimum).

## Tests

**Backend (Pest)**:

- `PromoEligibility`: one test per row of the table.
- `PromoService::book`: rejects ineligible bookings (Inactive on a healthy wrestler, Interference with a Diva, Bikini with a non-Diva, Start a Rivalry with someone in a rivalry, Title Match Hype without a title match); default and invalid `before_position`.
- `ShowResolver`:
    - a Start a Rivalry promo before match 2 raises going-in fatigue for match 2;
    - promo fatigue at 100% gives a serious injury;
    - Title Match Hype fizzles.
- Special Referee:
    - the referee is required;
    - the referee can't compete in the match;
    - a seriously injured wrestler can't referee;
    - the referee takes no fatigue or record;
    - the randomizer fills the referee;
    - the seeder marks the types bookable.

**Frontend (Vitest/RTL)**:

- Rewrite `card-builder.test.tsx` to use clicks:
    - family → variant;
    - the path text;
    - VACANT;
    - Escape and outside click close the picker;
    - disabled Ric Flair;
    - Divas-only lists;
    - the Manager label and picker;
    - the referee slot;
    - progressbar `aria-valuenow`;
    - cost `text-zone-safe` and Free `italic`;
    - Clear has `gm-btn-danger`.
- `proposal-launcher.test.tsx`: the dice icon renders and the name stays "Randomize".
- New `promo-slots.test.tsx`: alphabetical order, greyed cards, "Success Probability:", per-type inputs, Airs default.
- `docs.test.tsx`: the Promos tab.

## Verification

1. `php artisan migrate:fresh --seed`, `php artisan gm:types`, `vendor/bin/pint`.
2. `vendor/bin/pest` and `npm run test`, then `composer ci:check`.
3. `preview_start` the dev server, start a career and open a weekly show card. Check:
    - pickers fade in and out;
    - the path text;
    - VACANT;
    - red Clear and its hover;
    - the progress bar sheen on Raw and SmackDown;
    - the dice;
    - the header/footer swap;
    - green and italic Free;
    - booking a Special Referee match;
    - the promo grid's greying, inputs and placement.
4. Air the week and check the promo outcomes and fatigue on Results and Rosters. Screenshot `/gm/docs` Promos.
5. PR description lists the new _(assumption)_ values.
