# UI Guidelines Review, Rivalry Rules & Speed-up Injury Promo

Executed 2026-11-20 on `fix/20261120-ui-guidelines-review`.

## Context

The user asked for a UI review of the whole GM app against the Vercel Web Interface Guidelines. Three read-only reviewers covered every non-test page under `resources/js/pages/gm`, every component in `resources/js/components/gm`, `layouts/gm-layout.tsx` and `resources/css/gm.css`. Rules that conflict with the project's own conventions were left out: Title Case, copy voice, SSR/hydration, safe areas, font preloading and GIF/video.

Spot-checked and confirmed by hand: `results.tsx:963` (`fasfas`), `results.tsx:329-341` (window arrow-key listener), `news.tsx:25` (button label), `wrestler-picker.tsx:75` (`aria-label` replaces the content), `confirm-dialog.tsx:118` (confirm button autofocused).

## Fix plan (priority order)

### 1. Visible bugs

- `resources/js/pages/gm/results.tsx:963` - remove the stray `fasfas` after `<TitleMark>`.
- `resources/js/pages/gm/news.tsx:25` - label `Continue to ${next.name}` for every destination (today it reads "Continue to Emails" even when the link goes to the Schedule).
- `resources/js/pages/gm/rosters/show.tsx:832` - show `ContractStatusLabels[c.status]` instead of the raw enum; `:780` - fall back to `—` when `reign_number` is null.
- `resources/js/pages/gm/rivalries.tsx:55` - `<Head title="Rivalries">` to match the h1.
- `resources/js/components/gm/title-tools.tsx:117` - `text-md` is not a Tailwind class (use `text-base`).
- `resources/js/components/gm/match-heading.tsx:27` - avoid "Match 1 - " when there's no match type.

### 2. Keyboard hijack and risky focus

- `results.tsx:329-341` and `components/gm/news-grid.tsx:67-81` - ignore arrow keys when the event target is an input, textarea, select or contenteditable, when it's inside `[role=dialog]`, or when a modifier key is held.
- `components/gm/confirm-dialog.tsx:118` - when `danger`, focus Cancel by default (destructive confirms such as "Yes, clear it" and "Discard and leave" currently run on Enter).
- `pages/gm/financials.tsx:362-397` - render a disabled `<button>`/`<span>` instead of `href="#"` when there's no previous or next week.
- `components/gm/finance-trend.tsx:96-102` - after `onSelect`, move focus to the new bar so arrow navigation keeps going; keep one bar at `tabIndex=0` even when `selected` isn't in `weeks`.

### 3. Screen-reader names and announcements

- The picker buttons' `aria-label` hides the picked value: `wrestler-picker.tsx:75`, `card-builder.tsx:1035`, `title-tools.tsx:253`, `rivalry-picker.tsx:59`. Use `aria-labelledby` = label + value, or an sr-only label prefix, so the button reads "Competitor 1: Edge".
- Live regions (`aria-live="polite"`, sr-only):
    - match results decided on `results.tsx` (~662-700)
    - AI picks in `draft.tsx` (~676) and `redraft.tsx` (~542-559; narrow the noisy live region at `:410`)
    - trade odds at `trade.tsx:238-252`
    - the title cycler value at `title-tools.tsx:113-132`
- Unique names on repeated buttons: `draft.tsx:548-561` `Draft ${name}` (as free-agency does); `book-to-win-card.tsx` Book to Win per side.
- Row button `results.tsx:830-834`: its `aria-label` drops the visible result. Remove it, or include the winners and method.
- `aria-hidden` on decorative glyphs and icons: `news-grid.tsx:138,155,355`, `title-tools.tsx:260`, `schedule.tsx:201,210`, `saves/index.tsx:403,491,527`, `cash-in-button.tsx:51`, `faction.tsx:38`.
- Unread badge context: `gm-layout.tsx:316,466` and `badges.tsx:154-158` (sr-only "unread").

### 4. Layout-level accessibility

- `layouts/gm-layout.tsx:564-586` - add a "Skip to main content" link and `id="main"` on `<main>`.
- `gm-layout.tsx:471,483` - drop `role="menu"`/`menuitem` (plain links in the popover) or add arrow-key handling.
- `resources/css/gm.css:271` and `:840-853` - also apply `color-scheme: dark` and the reduced-motion rules to portaled Radix dialogs (add `.gm-root` to their portal container, or scope to `body` on GM pages).
- `gm.css:733` - `.gm-input:focus` → `:focus-visible`.
- `pages/gm/saves/index.tsx:374-386,448-460` - focus ring on the radio cards (`has-[:focus-visible]:ring`).

### 5. Custom dialogs and listboxes

- `components/gm/proposal-modal.tsx:101-109` - use the shared Radix dialog (focus trap, return focus, scroll lock) or add them by hand; same for the reels (`first-pick-reel.tsx:67`, `superstar-reel.tsx:104`), which at least need focus moved in.
- `components/gm/picker.tsx:57-129` (shared by every picker, card-builder lists and the rivalry picker) - roving `tabIndex`, Up/Down/Home/End, typeahead on the first letter, and `overscroll-contain` on the scroll lists.
- `pages/gm/docs.tsx:3363-3394` - ARIA tabs wiring (`id`/`aria-controls`/`aria-labelledby`, arrow keys) and the active tab in `?tab=`.

### 6. Copy and typography sweep

- Replace "..." with "…" in: `card.tsx:204`, `results.tsx:527`, `titles/assign.tsx:84`, `saves/index.tsx:302,303,311`, `air-week-button.tsx:110`, `proposal-modal.tsx:216-217`. CLAUDE.md quotes some of these strings with "...", so update those examples too.
- Curly apostrophes and quotes in visible copy (email, factions, card, results, saves, faction prompt).
- En dash in `saves/index.tsx:181` ("15–30").

### 7. Remaining items (in scope: the user asked for all of them)

- **Long lists.** Add a `.gm-cv` utility in `gm.css` (`content-visibility: auto; contain-intrinsic-size: auto <row height>`) and apply it to:
    - inbox rows (`email.tsx:308-337`)
    - each show panel on Reports (`reports.tsx:66-80`)
    - draft pool rows (`draft.tsx:481-564`) and free agency rows (`free-agency.tsx:159-292`)
    - the docs match-type family tables (`docs.tsx:760-837`)

    This is CSS only, so there are no backend or paging changes, and every row stays in the DOM for search and tests. On the draft page, also memoise the pool row component so an AI pick doesn't re-render all ~250 rows.

- **State in the URL.** Add a small hook `useUrlParam(key, default)` in `resources/js/lib/use-url-param.ts`. It reads `window.location.search` and writes with `history.replaceState`, so there's no server visit and Inertia's `preserveState` isn't needed. Use it for:
    - roster search, type and sort (`rosters/index.tsx:48-49`)
    - free agency filters and sort (`free-agency.tsx:41-42`)
    - the draft pool filter (`draft.tsx:100`)
    - factions origin, status and search (`factions.tsx:80-98`): the URL wins, and localStorage stays as the remembered default
    - the schedule month (`schedule.tsx:101-135`)
    - the news week (`news-grid.tsx:57`)
    - the results night toggle (`results.tsx:553-569`)
    - the docs tab (`docs.tsx:3302`, `?tab=`, as titles/history does)
    - the shared `pool-filters.tsx` state, through its parent

    Leave the server-driven params as they are (`?show`, `?match`, `?wrap`, `?brand`, `?week`).

- **Layout-property animations.**
    - `gm-sheen` (`gm.css:254-262, 709-723`): animate `transform: translateX`, not `left`.
    - `gm-current-glow` (`gm.css:311, 351-358`): put the shadow on a `::after` and animate its `opacity`.
    - Progress bars (`card-builder.tsx:211`, `schedule.tsx:446`): `scaleX` with `origin-left`.
    - Docs tab indicator (`docs.tsx:3388`): `translateX` + `scaleX`.
    - `gm-slam` blur and `gm-wipe` clip-path: keep them (they're one-shot entrances that already respect reduced motion) and note that in the PR.
- **Touch.**
    - `touch-action: manipulation` and an intentional `-webkit-tap-highlight-color: transparent` on `.gm-btn`, `.gm-input`, `.gm-row-link` and picker options.
    - `overscroll-behavior: contain` on `.gm-scroll`, picker lists, popovers and modal bodies.
- **Charts.**
    - `attendance-chart.tsx:155` and `reign-chart.tsx:366`: one roving Tab stop per chart, with arrow keys moving between points. Reuse the pattern fixed in finance-trend.
    - Drop `role="status"` from the hover tooltips (`attendance-chart.tsx:399`, `reign-chart.tsx:420`).
    - Format the "k" axis labels with `Intl.NumberFormat('en-US', {notation: 'compact'})`.
- **Images.** Add intrinsic `width`/`height` attributes to the `<img>`s in `badges.tsx:115-128` and `brand-bug.tsx:22,46`. The assets are 320 px or 384 px wide, so pass the source size and keep the CSS `h-* w-auto`. That keeps the aspect ratio and avoids layout shift.
- **Trade page.**
    - Show a `<ConfirmDialog>` before **Send offer** (`trade.tsx:271-282`) that lists both sides and the odds.
    - Show an inline error when the odds fetch fails (`trade.tsx:141-142`), not a silent blank.
    - Give the remove ✕ button a 44px hit area on coarse pointers (`trade.tsx:671-678`).
- **Other small items from the reviews:**
    - `email.tsx:379-384`: disable Delete while a request is busy.
    - `titles/assign.tsx:70-78`: `aria-busy` and a busy label during the Start the season delay.
    - `saves/index.tsx:503-516`: `name`, `autoComplete="off"`, `aria-invalid`, `aria-describedby` and `role="alert"` on the error. Only put server and network failures under the name field when they're about the name.
    - Search and name inputs (`factions.tsx:157`, `redraft.tsx:993`, `trade.tsx:710`, `pool-filters.tsx:78`, `faction-prompt-modal.tsx:93`): `name`, `autoComplete="off"`, `spellCheck={false}`, and placeholders ending in "…".
    - `faction-prompt-modal.tsx`: wrap it in a `<form>` so Enter submits, and tie the Type label to its control.
    - `promo-slots.tsx:512,547,654,686`: arrow-key radio groups like the first group at `:375`; `formatMoney(0)` at `:180`.
    - `book-to-win-card.tsx:173`: `min-w-0 break-words` on the side label.
    - `superstar-reel.tsx:122-138`: `break-words` for long names.
    - `fatigue-progress.tsx:203`: `gm-num`.
    - `champion-tag.tsx`: sr-only "champion" / "captain" after (C) / (Cpt), and show faction members on screen, not only in `title`.
    - `wrestler-name.tsx:42-45`: an sr-only "(Diva)" / "(Cruiserweight)" cue.
    - `results.tsx:501-505`: put the held Continue's reason on screen, not only in `title`.
    - `results.tsx:513-523` and `schedule.tsx:141-149`: use `<Link>` for navigation, not `router.visit` buttons.
    - `rosters/index.tsx:194-198`: keep the row click, and let Ctrl/middle-click fall through to the name link.
    - `history.tsx:149-151`: smooth scroll only when reduced motion is off.
    - `history.tsx:184-191`: disable the hall button when there's no id.
    - `rosters/show.tsx:648-664`: radiogroup roles for the contract lengths.
    - `booking-steps.tsx:62`: `role="status"`, not `alert`.
    - `ppv-entrants.tsx:172-176`: a line saying why the other boxes are disabled once the quota is full.
    - `schedule.tsx:555`: raise aired shows above `opacity-20`, so the Results target stays legible.
    - `draft.tsx:436-439`: drop the `aria-label` that overrides the visible "Sign for" label. `draft.tsx:477`: sr-only header text.
    - `gm-layout.tsx:222`: make the accessible name include "GM Mode".
    - `slot-actions.tsx:92-100` (Discard) and `title-vacancy-modal.tsx:55-57` (Escape counts as Forfeit): leave them as they are. Discard only reverts to the saved slot, and Forfeit already confirms. Mention both in the PR.

### 8. Gameplay changes (user request, 2026-11-20)

Do these after sections 1–7. Every number goes in `config/gm.php`, and every rule goes into CLAUDE.md's Game rules and the dated plan.

**8a. #1 Contender's Match resolves the title-match complaint.**

- In `app/Services/Matches/MatchOutcomeHandler.php:78-81`, set `$context->data['title_match'][$id] = true` for every competitor when the slot's `stipulation === Stipulation::NumberOneContender`, win or lose.
- `MoraleService::weekly` (`app/Services/Morale/MoraleService.php:59-71`) then resets `weeks_since_title_match` and resolves the complaint. They're Happy if they have no other complaint.
- Test: a superstar 5+ weeks without a title match loses a #1 Contender's Match, and is Happy after the week.

**8b. Interference and Diva Bikini Contest count as "used".**

- In `app/Services/Week/Steps/UpdateMorale.php:22-27`, merge into `$used` the `targetIds()` of this week's aired promo slots whose type is `Interference` or `DivaBikiniContest`. This applies whether the promo succeeded, failed or backfired. The promo types go in config: `gm.morale.promos_count_as_used`.
- Test: a superstar unused for 4+ weeks who appears only in an Interference has `weeks_unused` 0 and the Inactivity complaint resolved. Write the same test for the Bikini Contest.

**8c. Continue Rivalry can't injure.**

- In `app/Services/Promos/PromoService.php:368-385` (`tire()`), keep the fatigue update and skip `injuries->rollForPromo` (which also covers escalating a minor injury) for keys in `gm.promos.injury_exempt` (`['continue_rivalry']`).
- Fix the docblocks at `PromoService.php:364-366` and `InjuryService.php:44-47`.
- Test: a rival at 70% books Continue Rivalry, gains its fatigue and gets no injury. A rival who already has a minor injury doesn't escalate.

**8d. A rival who manages or referees still keeps the rivalry going.**

- In `RivalryService::sharedMatches()` (`app/Services/Rivalries/RivalryService.php:264-289`), count `Competitor`, `Manager` and `Referee` participants. The roles go in config: `gm.rivalries.meeting_roles`.
- So a match where one rival wrestles and the other manages (either side) or is the Special Referee is a shared match: +10 weekly, +20 PPV. That holds whatever the match, including when both are managers, or one is the referee and the other manages.
- _(Assumption: it counts as a full meeting, not just a hold.)_
- Captain Fall captains (including the Tornado and 6-man captain-fall tags) are already `Competitor`s, the first wrestler on each side. Add a test that two rivals captaining opposite sides of a Captain Fall count as a meeting.
- Starting a rivalry (`recentMeetings`, :296-305) stays competitors only.
- Test: the Preset shape "rival vs. an opponent managed by the other rival" gains heat and doesn't decay.

**8e. Heat bar colours on `/rivalries`.**

- `resources/js/pages/gm/rivalries.tsx:123-140`:
    - 10–40 (below 50): yellow, `bg-zone-minor`
    - 50–70 (below 80): orange, a new token `--color-zone-warn: #ff8a2b` in `gm.css`
    - 80–100: red, `bg-zone-serious`
- The thresholds come from the page props: `gm.rivalries.heat_colors` = `[50, 80]`.
- Update the "How rivalries work" text if it mentions colours. Add a Vitest test for the three colours.

**8f. Rivalry match history, expandable rows on `/rivalries`** (no separate page).

- **Server.** Add `RivalryService::matchSlotIds(Rivalry)`: every aired match from `started_week` to `ended_week` (or now) where both rivals took part in a meeting role (8d). It is shared with 8g's "4 matches together" count.
- **Props.** `RivalryController` adds a `matches` list to each rivalry, newest first. Each entry has `id`, `season`, `season_week`, `ppv`, `link` (that match on its week's results, built the way title history builds its links) and the outcome parts.
- **Rows.** Every rivalry with at least 1 match gets a chevron toggle on its row: a `<button aria-expanded aria-controls>`, using `components/gm/disclosure.tsx` for the animated height. Clicking it opens a nested table under the row with two columns:
    - **Week/PPV**, in Title History's format. Move `WonCell` (`titles/history.tsx:462-523`) to a shared `components/gm/week-cell.tsx` and use it in both places: season, week, and the PPV name when there is one.
    - **Match outcome**, e.g. "Edge def. Christian · Hardcore — Single", the winners, "def." and the losers, colour-coded through `<WrestlerName>`, then the match type. Managers and referees follow the results wording ("w/ Eugene", "Special Referee: Kane"), and a DQ or count-out shows its method. Reuse the result/outcome name formatting the defense rows use (`CardPresenter` / title history presenter) rather than writing a new one.
    - Each row links to its results.
- Rivalries with 0 matches have no toggle.
- **Tests:**
    - Feature: matches listed newest first; manager and referee matches included; matches before the start or after the end excluded.
    - Vitest: no toggle with 0 matches; it expands and collapses; Week/PPV shows the PPV name; the outcome text is right.

**8g. Rival GM keeps and ends its rivalries** (`AiRivalryPlanner`, `AiBooker`, `AiPromoPlanner`, plans in `save_brands.ai_plans`).

- **Maintain.** Every active rivalry of its brand is a pair to book each card: `gm.ai.rivalries.rivalry_match_chance` goes from 0.5 to 1.0, still within its careful/aggressive fatigue rules. Any rivalry it couldn't pair on its weekly show gets a Continue Rivalry promo. This comes ahead of the situational and money promos, after obligations such as low cash and PPV Hype, and is limited by the promo slots (hottest first).
- **End.** A rivalry is due a blow-off when it is at **60+ heat** and has **4+ matches together** (`gm.ai.rivalries.blowoff_min_heat` 60, `blowoff_min_matches` 4, counted with 8f's `matchSlotIds`).
    - At the first card of its next PPV where it can book (its brand-only PPV, or its own slots on an interbrand PPV), each due rivalry rolls once: **60%** (`blowoff_ppv_chance`). The roll is stored in `ai_plans.blowoffs[rivalry_id]`, using RNG stream `ai.blowoff.{brand}.{rivalry pair}`.
    - On a 60 it books them 1v1 on the PPV. On a 40 it books them 1v1 on its next weekly show after that PPV. That weekly booking comes first among its rivalry pairs, and it doesn't rebook them on the PPV.
    - When that match airs, the rivalry concludes. `RivalryService::conclude()` becomes public, and `settleWeek` gets a `blowoffs` input via `RivalryService::concludeAfter(slot)`. The existing `rivalry_concluded` headline is recorded, and the rivalry isn't decayed or held first.
    - If they can't be booked (injury, fatigue), it tries again on the next card. If the rivalry ends some other way first, the plan is dropped.
- Only the rival's rivalries do this. The player's are unchanged.
- Tests:
    - It books every rivalry, and a leftover gets Continue Rivalry.
    - A due rivalry on a 60 roll is booked at the PPV and concluded.
    - On a 40 roll it's booked on the weekly show after, and concluded.
    - A rivalry below 60 heat or with fewer than 4 matches isn't planned.

**8h. New promo: Speed-up Injury process** (key `speed_up_injury`, label "Speed-up Injury Process").

- **Settings:**
    - cost **$15,000**, paid either way
    - success **80%**
    - no fatigue
    - target: one superstar of the brand with an active minor or serious injury (`InjuryStatus`), who doesn't already have this promo on the card
- **Effects** (new `InjuryService::shorten(Injury, int $weeks, int $week)` and a public `heal(...)`; `ends_week -= n`, and at or below `week + 1` it sets `healed_week = week`):

    | Injury  | Success         | Failure         |
    | ------- | --------------- | --------------- |
    | Minor   | heals now       | 2 weeks shorter |
    | Serious | 5 weeks shorter | 2 weeks shorter |

    The amounts go in `gm.promos.speed_up_injury` = `{minor_success: heal, serious_success_weeks: 5, fail_weeks: 2}`.

- **Touchpoints:**
    - `PromoTypeKey` enum, then `php artisan gm:types`
    - `PromoTypeSeeder` (`wrestler` flag, requirement and effect text; fix the "14 promo types" docblock)
    - `gm.promos.success_rates` and `amounts`
    - `PromoEligibility::forType` + `context` (injured ids, reason "No injured superstars")
    - `PromoService::resolveOne` effect dispatch
    - `PromoStoryWriter` + `PromoStories`: situations `minor.success`, `minor.failed`, `serious.success`, `serious.failed`, each with 4+ variants and short headlines (≤6 words, ≤3 tokens); summaries follow the story-text rule and name the superstar, injury and new return week
    - `promo-slots.tsx`: a single "Superstar" picker, as Inactive Superstar has, showing the injury and weeks left beside it
    - the `/gm/docs` promo list and prose
    - count tests 15 → 16: `EnumValuesTest.php:72-75`, `CatalogSeedTest.php:530-531`, and CLAUDE.md's "There are 15 promos"
- **Rival GM:** a situational promo in `AiPromoPlanner::situational` with weight `gm.ai.promos.situational_weights.speed_up_injury`, targeting its injured champions first, then by `AiTradeJudge::value`.
- **Tests:**
    - all four outcomes on fixed seeds
    - eligibility (no injured superstar means greyed out)
    - a serious injury shortened to now returns the next week (the returns email still fires)
    - the rival picks its injured champion
    - StoryText and ShortHeadline rules pass

**8i. Financials and News week buttons.**

- During the end-of-week flow (`wrap_up` true), hide the previous/next week controls entirely:
    - `financials.tsx:358-407` (the whole week nav, including Latest)
    - `news-grid.tsx:132-156`
- `news.tsx` passes the controller's `wrap_up` prop (`NewsController.php:35-40`) through to `NewsGrid`, which also disables its arrow keys and swipe in that flow.
- Outside the flow, hide a button with nowhere to go rather than greying it out:
    - News: no Older on the oldest page, no Newer on the newest.
    - Financials: no Previous on the first ledger week, no Next on the latest. This also replaces the `href="#"` links from section 2.
- Vitest: with `wrap_up`, no nav. On the first and last pages, only the button that leads somewhere is shown.

**Docs page:** per CLAUDE.md, update `/gm/docs` (`DocsController` + `docs.tsx`) for 8a–8d, 8g (rival behaviour) and 8h (new promo row, morale and rivalry rules). The user's request implies this, and it gets confirmed with the plan's approval.

### 9. Re-audit

Once everything is done, run the same review again: the Web Interface Guidelines and three read-only reviewers over pages, components and layout/CSS, now including the new `week-cell.tsx` and the rivalry history rows.

- Confirm sections 1–7 are fixed and the new UI (8e, 8f, 8h, 8i) is clean.
- Fix any new findings.
- Also check it in the browser pane: the card screen with the keyboard, the results page, `/rivalries` with its expandable match rows, the new promo on the card, and Financials and News in and out of the wrap-up flow.
- Report the before/after.
- **Then the docs pass:** confirm `/gm/docs` (`DocsController` + `docs.tsx`) and CLAUDE.md match everything that changed, and fix any gaps.
    - New promo row, effects and count (16)
    - Morale (#1 Contender's Match and the two promos resolving complaints)
    - Rivalries (manager and referee meetings, Continue Rivalry injury-free, heat colours, match history rows)
    - Rival GM rivalry maintenance and blow-offs
    - The Financials and News nav rules
    - The UI conventions from sections 1–7: `useUrlParam`, `.gm-cv`, danger dialogs focusing Cancel, "…"
- Re-run `docs.test.tsx` and the `NewsCatalog` test.

## Branch and workflow

- Per CLAUDE.md: pull the latest `main` and branch `fix/20261120-ui-guidelines-review` off it. The current branch is `chore/20261119-gm-mode-ideas-todos`, and it's clean.
- Add the dated plan `docs/20261120-ui-guidelines-review.md` and its entry in `docs/README.md`.
- Add a short CLAUDE.md note under Rules:
    - typed state lives in the URL through `useUrlParam`
    - long lists use `.gm-cv`
    - danger dialogs focus Cancel
    - keep the "…" ellipsis

    Update the quoted "Please wait..." strings there too.

- Per memory: don't commit until the user asks.

## Verification

- `npm run test` for the touched components (picker, confirm-dialog, results, news-grid, draft and redraft tests exist), adding tests for: arrow keys ignored inside an input or dialog, danger dialogs focusing Cancel, picker buttons' accessible names including the value, and the news Continue label.
- `composer ci:check` (Pint, PHPStan, `tsc`, `vp check`, the plural arch test, and the full Pest and Vitest suites).
- Section 8:
    - Run `php artisan migrate:fresh --seed` for the new promo, then `php artisan gm:types`.
    - Run `vendor/bin/pest` on Morale, Rivalries, Promos, Ai, News/PromoStories, Text and Catalog.
    - Run `composer demo` and play a few weeks with Simulate to here. Check that the rival's rivalries are booked or continued, blown off at a PPV, and the `rivalry_concluded` headline appears.
- In the browser pane (`npm run dev` + `php artisan serve`): tab through the card screen and the results page from the skip link, use the pickers with the keyboard, check that Escape returns focus, and check the reduced-motion and dark-scheme dialogs.
- `/gm/docs` doesn't document these UI details, so no docs update is needed. Do update the CLAUDE.md strings that quote "Please wait..." if the ellipsis changes.

## Outcome (2026-11-20)

- Sections 1–9 done. Backend: 1,228 Pest tests pass; frontend: 436 Vitest tests pass; PHPStan, Pint, `tsc` and `vp check` are clean.
- Re-audit (three read-only reviewers): no fix was broken. Follow-ups applied: focus kept when News' Older/Newer hides, the entrants quota line is a mounted live region (and hidden when read-only), straight apostrophes and decorative arrows, factions' empty header and search label, one live region on the trade page, reduced-motion scroll in the re-draft tag reveal, Ctrl/middle-click on title-history rows, `aria-current` on the inline nav, no `div` inside the saved-career buttons.
- Then the four open items: the custom radio groups follow the ARIA radio pattern (`lib/radio-group.ts`), Free Agency rows are memoised (`FreeAgentRow`), the card and Simulate-to-here progress bars slide with `translateX` instead of animating width, and `WrestlerName` adds a hidden "(Diva)" / "(Cruiserweight)" cue.
- `aria-pressed` toggles keep one name (Skip animation's On / Off is visual only; the ticker button is always "Pause the … ticker"), so their state isn't announced twice.
