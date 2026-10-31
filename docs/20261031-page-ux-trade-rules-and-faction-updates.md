# Page UX fixes, trade rules and faction updates

Executed 2026-10-31 on `enhancements/20261031-rosters-and-title-history-ux`, after the Rosters and Title History audit ([20261031-rosters-and-title-history-ux.md](20261031-rosters-and-title-history-ux.md)).

## Context

A second UI/UX audit covered Factions, Propose Trade, Free Agency, Financials and the Results screen (save 2, at 1440px and 375px). The player also asked to drop the Cruisers / Light Heavy pool filter, to show the trade rules on the Trade page, and to correct several real-life factions.

## Changes

### Pool filters

- The Cruisers / Light Heavy toggle is gone from every pool filter (Draft, Free Agency, Rosters), with the `cruisers` filter field and the unused `isCruiser` helper.

### Results

- No sideways scroll on phones: the grid column gets `min-w-0`, method labels wrap below `sm`, and the outcome card's slide-in is clipped (`overflow-x-clip`).
- Phones: picking a match scrolls to its card; while a week airs, a **Decide match N** button jumps there.
- A title match for a vacant title reads "◆ {title} (vacant)" in the list, Book to Win and the outcome card.
- Promos are numbered in the order they air, and a Title Match Hype row shows the title it hyped (gold badge, `promos[].title` from `target_championship_id`).
- Continue says "Decide every match to continue."; the pending fatigue note is rewritten.

### Propose Trade

- **How trades work** sits under the heading: the limits (`gm.trades.max_per_side`, roster cap), who can't be traded, no trading while a week airs, what an accepted trade does, and how the rival judges an offer, from `gm.ai.trades` (sent as the `judge` prop, so the copy follows config).
- An offer bar stays at the bottom of the screen: who goes each way (with remove buttons), why Send offer is disabled, and the button.
- Rows show fatigue, W–L, contract weeks left, specialties and the roster type; untradeable rows say why; each list has a search.

- The offer bar shows **Chance the rival GM accepts**: `AiTradeJudge::chance` (split out of `accepts`, which now rolls against it) through `POST trade/odds`, refreshed as the offer changes; green at 90% or more, orange below. Hidden while the offer is empty or over a roster cap.
- The offer bar is pinned flush to the bottom of the window (no gap for the lists to show through) and is opaque.
- Note: `TradeService::execute` moves the wrestlers as soon as the rival accepts, while the Contracts rules say roster moves take effect the following week. The page describes what the code does; which one is right is still open.

### Rivalries

- **How rivalries work** sits under the heading: how one starts (2 meetings in 4 weeks, Start a Rivalry, a crashed Interview Space), heat (start, per meeting, per Continue Rivalry, weekly cooling, heating up, the 10-week headline, the PPV blow-off) and Rival Interference. Numbers come from `gm.rivalries`, `gm.news.rivalry_milestone_weeks` and the two promos' fatigue and cost (the `rules` prop).
- `/gm/docs` (Promos) explains heat beside the rivalry promos (`promos.rivalry_heat`); CLAUDE.md gets a Rivalries section.

### Free Agency

- A full roster gives a notice linking to the roster, and each row says **Roster full** instead of a disabled Sign.
- Sticky header; sortable by name, type, class, up-front and weekly cost (for the length picked on the row). The sort header is shared with Rosters (`components/gm/sort-header.tsx`).

### Financials

- The starting budget is the opening balance, not the week's income: `FinancialReport` keeps it out of income, expenses and net (it still counts in the balance) and returns it as `starting_budget`. Week 1 now reads its real net.
- Line items are grouped by category with subtotals; groups over 3 lines start collapsed.
- New **Upkeep per week** figure (`FinancialReport::weeklyUpkeep`, active contracts' weekly cost).
- The week-by-week bars have a legend and screen-reader labels.

### Factions

- Opens on **Created this career**, or **All factions** while none has been created; the choice is remembered (`readPreference`).
- Filters are radio groups ("Any status" instead of a second "All").
- **In game** (seeded factions from week 1) and **Real life** dates are separate columns.
- Disband shows on row hover or focus where there's a mouse (always on touch screens).

### Faction seed data (`database/seeders/data/factions.php`)

- New: **The Super Heroes** (tag team): Rosey, The Hurricane, Stacy Keibler. Dates are approximate (2003-06 to 2005-08) _(assumption)_.
- **Blue World Order** adds Simon Dean.
- **Full Blooded Italians**, **Team Xtreme**, **The Mexicools** and **The Legion of Doom** become tag teams.
- **The Dudley Boyz** become a stable and add Stacy Keibler.
- **Ministry of Darkness** removed.
- Existing saves keep the factions they were created with; run `php artisan migrate:fresh --seed` (wipes saves) to apply the catalog change.

### Earlier uncommitted card-screen polish (included in this branch)

- Active Effects is gold only while something is in play, grey otherwise.
- An empty match slot shows only its hint and Randomize; an empty promo slot shows no buttons.

## Verification

- `composer ci:check` (Pint, PHPStan, `vp check`, `tsc`, Pest, Vitest).
- Also: `rivalries.test.tsx`, the odds endpoint and rules props in `DefectionTradeTest.php` and `RivalryOrderTest.php`.
- New tests: `trade.test.tsx`, `free-agency.test.tsx`, `financials.test.tsx`, `tests/Feature/Finance/FinancialReportTest.php`, plus additions to `results.test.tsx`, `factions.test.tsx` and `DefectionTradeTest.php`.
- Browser pane on save 2 at desktop and mobile widths: no horizontal page scroll on any of the five pages; Financials loads on MySQL (the first version used `starting` as a column alias, a MySQL reserved word).
