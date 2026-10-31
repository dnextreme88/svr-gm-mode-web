# UI/UX audit: Title History (`/gm/saves/1/titles`), plus the earlier Rosters audit

## Context
Audit of `resources/js/pages/gm/titles/history.tsx` (`TitleHistoryController`), checked live at 1440px and 375px on save 1 (SmackDown, week 1), against the ui-ux-pro-max guidelines. The Rosters audit from earlier in this session is kept at the end (decisions: global contrast token, P1–P3), since both share two fixes.

## Title History findings (most important first)

### What works
Current Champions overview per brand with gold title names, title switcher grouped by brand with `aria-pressed`, `?title=` deep link on load, lineage with reigns and their defenses nested underneath, records side by side, gold highlight on the current holder, `aria-labelledby` on the champions section.

### P1: broken or inaccessible
1. **Horizontal page scroll on phones.** At 375px the document is **510px** wide. The content grid (`grid gap-6 lg:grid-cols-[240px_1fr]`) has an implicit `auto` column below `lg`; the lineage table's min-content width (grid items default to `min-width: auto`) stretches that column, so the title nav and every panel overflow the viewport. The table's own `overflow-x-auto` never kicks in.
2. **"INTERCONTINENTAL" overflows its champion card** on phones (`text-xl tracking-[0.14em]` in a half-width cell), touching/crossing the card edge.
3. **Defense rows are fake links** (`<tr tabIndex=0 onClick aria-label>`): no middle-click/new tab, aria-label hides the row's content, no visible focus (`focus-visible:outline-none`, 6% white tint). Same issue as Rosters.
4. **Wrestler names ignore the colour-coding rule** (CLAUDE.md: use `<WrestlerName>` anywhere a wrestler name is listed). Daivari (Cruiserweight) is blue on Rosters but plain here; Mickie James (Diva) isn't red. Applies to champion cards, lineage holders, defense opponents and records. The backend sends names as plain strings.
5. **Low-contrast grey** (`ink-faint`, ~3:1): column headers, vacant rows (italic + faint, the hardest to read), "—" placeholders. Fixed by the global token change from the Rosters plan.

### P2: usability
6. **Selected title isn't in the URL.** `?title=` is read on load but clicking a title doesn't update it, so Back, refresh and sharing lose the selection.
7. **Phone order buries the content.** Below `lg` the 8-item title nav (~400px) sits between the champions and the lineage; the lineage starts ~1,540px down. Champion cards don't link to their lineage either, so the overview can't be used to navigate.
8. **Money in the Bank expiry uses the absolute week** (`until W{expires_week}`, e.g. W53+ in season 2), while everything else on the page uses "S1 W1" season weeks (`calendar->seasonWeek`). It's also a single line of plain text, easy to miss.
9. **Records panel values glue holder and number** ("John Cena · 0 wk", "Daivari · 2"): the number has no unit for reigns/defenses, and holders aren't linked or colour-coded.

### P3: polish
10. Champion cards abbreviate "0 wk · 0 def." (unclear "def."); fine for regulars, better as "0 weeks · 0 defenses" with `aria-label`.
11. Defense rows repeat "—" in Reign and Weeks columns; could stay empty for a calmer table.
12. Title nav buttons are 36px tall (below 44px touch target on phones).
13. Number columns (#, Reign, Weeks, Defenses) aren't right-aligned.

Not a bug: the heading briefly reads oddly during the `animate-gm-wipe` intro (screenshot caught mid-animation); reduced motion is already handled in `gm.css`.

## Title History fix plan (`resources/js/pages/gm/titles/history.tsx` unless noted)

1. **Overflow:** add `min-w-0` to the content column (`<div className="space-y-6 min-w-0">`) and `grid-cols-1` before `lg:` so the table scrolls inside its panel. Champion cards: `grid-cols-1 sm:grid-cols-2`, title name `text-lg sm:text-xl`, `break-words`.
2. **Real links:** in each defense row, make the "vs. {opponents}" cell an Inertia `<Link href={d.link}>` with a visible `focus-visible` outline; row `onClick` stays for mouse (skip when the event came from the link); drop `tabIndex`, `aria-label`, `onKeyDown` from the `<tr>`. Same treatment and shared styles as Rosters.
3. **Colour coding:** in `TitleHistoryController` (and `TitleRecords` for records), send holders as a list of `{name, roster_type, weight_class}` alongside the existing display string (the string stays for faction/team names via `FactionNamer`). Frontend: a small `HolderName` helper rendering the faction/team name as is, or each member through `<WrestlerName>` joined with " & ". Opponents in defense rows: same shape. Update the TS prop types.
4. **URL state:** on title click, `router.get(url, {title: key}, {preserveState: true, preserveScroll: true, replace: true})` (or `history.replaceState`), so the selection survives refresh/Back and is shareable.
5. **Phone order:** below `lg`, replace the stacked nav with a horizontal scrollable chip row (or a `<PopoverPanel>` picker, per the card-screen convention) above the lineage; keep the vertical nav at `lg+`. Make each champion card a button/link that selects that title and scrolls to the lineage.
6. **MITB:** send `expires_season` / `expires_season_week` from the controller (use the existing `calendar->seasonWeek`), render "until S1 W52"; show briefcases as small cards in the champions section (holder via `WrestlerName`, brand bug, expiry) instead of a text line.
7. **Records:** value as holder (colour-coded) + number with unit ("2 reigns", "3 defenses", "12 weeks").
8. **Polish:** full words in champion card stats; blank instead of "—" in defense rows' Reign/Weeks; title nav buttons `min-h-11 lg:min-h-0`; right-align numeric columns.

### Tests
- Update/extend `resources/js/pages/gm/titles/history.test.tsx` (existing tests use `reign-*`, `defense-*`, `won-*`, `brand-divider` test ids; keep them): defense opponent is a link with the right `href`; selecting a title updates the URL; Diva/Cruiserweight holders get `data-tone`.
- Pest feature test for the controller: holders' `roster_type`/`weight_class` present; briefcase expiry in season weeks.

---

## Earlier audit: Rosters (`/gm/saves/1/rosters`), decided: global token, P1–P3

Findings: `ink-faint` contrast ~3:1; rows are fake links (no new tab, aria-label hides content, no visible focus); brand switch lacks `aria-current`; no sorting; no filters beyond search; on phones only 3 of 9 columns visible and names wrap; header not sticky; title belts stack and wrap; Popularity always "—"; contract urgency is colour-only; 30px search input; long "Record (W-L)" header, numbers not right-aligned.

Fixes (`resources/js/pages/gm/rosters/index.tsx`, `resources/css/gm.css`, `resources/js/lib/gm.ts`):
1. `--color-ink-faint` → about `#8a93a6` (≈5.5:1) globally (shared with Title History #5).
2. Name cell becomes an Inertia `<Link>`; row click kept for mouse; visible focus outline.
3. `aria-current="page"` on the active brand.
4. Client-side sortable headers (`aria-sort`, lucide arrows): Wrestler, Fatigue, Morale, Record, Contract; comparators in `lib/gm.ts`.
5. Reuse `PoolFilters` (type, Cruisers / Light Heavy, Specialists, search).
6. Sticky header and sticky first column; nowrap names and belts; hide Popularity; right-align numbers, "W–L"; warning icon + `sr-only` "expiring" at ≤4 weeks; `min-h-11` controls on phones.
Tests: `resources/js/pages/gm/rosters/index.test.tsx` for sort, filters, link `href`, `aria-current`.

## Verification (both pages)
- `npm run test`, `vendor/bin/pest` for the controller tests, `composer ci:check`.
- Browser pane at 1440px, 800px and mobile presets: `document.documentElement.scrollWidth === innerWidth` on both pages; Tab through rows shows a focus ring; Ctrl-click a name/defense opens a new tab; Title History selection survives refresh; Daivari blue and Mickie James red on both pages; re-measure `ink-faint` contrast ≥4.5:1; before/after screenshots.
- No rules change, so no CLAUDE.md or `/gm/docs` update. Leave everything uncommitted until asked.
