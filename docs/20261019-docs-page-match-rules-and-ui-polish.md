# Docs Page, Match Rules & UI Polish

## Context

After the first full GM Mode build, the app needed to be easier to read and to learn. Text was small and hard to read, budgets were rounded (`$15.0M`), and several screens were missing basic tools: draft filters, past results, roster search. There was also no in-game reference for match types, injuries or house rules. This plan:

- adds a documentation page as the player-facing source of truth for game rules;
- formalizes the match-type house rules (rope breaks, ring outs, count-outs, DQs, titles, timed matches, fatigue costs) in data, docs and the match sim;
- works through UI fixes on every GM screen.

Decisions from the user:

- Fatigue's **Safe** zone is orange instead of green (bars and the "NN% safe" text). Minor risk stays yellow and Serious risk red.
- The first-pick slot machine plays **on `/gm` after Start**, then navigates to the draft.
- Email names are coloured by **each wrestler's own brand** (Raw red, SmackDown blue). Free agents keep the default ink.
- The docs page exists both **inside a career** (`/gm/saves/{save}/docs`, with the GM header and nav) and **from the save list** (`/gm/docs`).
- Rope breaks and ring outs are **data flags, shown on the docs page, and used by the sim**.
- Count-outs only change where the rules require it (types outside the rope-break exclusions keep their current setting), plus Normal Triple Threat and Fatal 4-Way, which lose both DQs and count-outs.
- Captain Fall is **documented and modelled**: the first wrestler on each side is the captain.
- Catalog changes ship as **seeder changes applied with `php artisan migrate:fresh --seed`**. There are no data-only migrations.

## Git

- Branch `enhancements/20261019-ui-todos`, cut from a freshly pulled `main`.
- One commit, then a draft PR to `main` assigned to dnextreme88.

## Documentation page (spec §6, §10, §21, §22)

- **Routes:**
    - `gm.docs` (`/gm/docs`) is linked from the `/gm` new-game panel. Outside a career it shows only a GM Mode home link.
    - `gm.saves.docs` (`/gm/saves/{save}/docs`) is a "Docs" item in the GM nav.
    - Both are served by `App\Http\Controllers\Gm\DocsController`. It takes an optional `Save`, so `ShareGameState` shares the GM header inside a career.
- **Page:** `resources/js/pages/gm/docs.tsx`, with four tabs:
    - **Match types**
    - **Fatigue & injuries:** zone meters, odds from `config('gm.injuries')`, and the minor and serious injury tables with escalation targets.
    - **Specialties:** wrestlers with match specialties.
    - **Stipulations:** rule summaries from §22.
- **Tabs:** a 2px brand-coloured active indicator slides to the selected tab, and re-measures on resize and wrapping. Panels slide in from the side you moved towards (`animate-gm-slide-from-left/right`). Reduced motion turns the slide off.
- **Match types tab:**
    - Two cards side by side, each a single-column list with entries sorted alphabetically:
        - **Match rules:** DQ, Elimination, Ring out, Rope breaks, Timed, Titles. Each says what the rule does.
        - **Win conditions:** Pinfall, Submission, Count-out, Captain fall, Table, Ladder retrieval, Briefcase retrieval, Cage escape, Last one in the ring, Over the top rope, Buried alive, First blood, Bra & Panties, Most falls, 10-count, Most opponents beaten.
    - Then one panel per category, with a 20px heading and the variant count.
    - Each variant row shows sides and competitor count, fatigue cost as bars (`FatigueCost`, out of 5), price, and badges.
    - **Badges:** a Rules cell and a Win conditions cell. Each shows only what applies, sorted alphabetically.
    - Restrictions (Divas only, No Divas, PPV only, `<PPV> only`, `<Brand> only`) sit alphabetically among the rule badges.
    - Count-out is a **win condition**, not a rule.
- The docs page is the player-facing source of truth. CLAUDE.md requires asking the user whether it needs updating whenever a change touches what it documents.

## Match rules (spec §10 "Match rules", `config('gm.match_rules')`)

- **Schema:** migration `2026_10_19_000001_add_match_rules_to_match_types` adds the boolean columns `rope_breaks`, `ring_out`, `captain_fall` and `timed`. Values come from `MatchTypeSeeder` and `database/seeders/data/match_types.php`.
- **Rope breaks and count-outs:** both off in Hardcore, TLC, Table (including the Table Tornado Tag Elimination variants), Ladder, Battle Royal, Royal Rumble and Hell in a Cell (`no_rope_break_families`).
- **Ring outs:** allowed everywhere except Steel Cage, Battle Royal, Royal Rumble and Elimination Chamber (`no_ring_out_families`).
- **DQs and count-outs:** off in Normal Triple Threat and Normal Fatal 4-Way.
- **No title matches:**
    - any 3 vs 3 match, where all six are active competitors (`no_titles_sides`). Managers don't count, so Tag vs Tag w/Manager can still have a title;
    - Captain Fall;
    - all five Handicap variants;
    - Slobber Knocker (`no_titles_families`).
- **Elimination:** every Royal Rumble variant, plus the existing elimination types.
- **Timed:** all Iron Man variants (including Special Referee Iron Man) and Ultimate Submission.
- **Captain Fall:** the first wrestler booked on each side is that side's captain. Pinning, submitting or counting out the other team's captain wins the match.
- **Sim (`MatchResolver`):**
    - **Rope breaks on:** clean submission chances are multiplied by `gm.outcomes.rope_break_submission_factor` (0.6, _assumption_).
    - **Ring outs off:** no count-out finishes and no rival run-ins.
    - **Captain Fall:** the result names the losing side's captain as the one who took the fall.
- **Card builder:** a gold "Captain" label sits above the first row of each side when a Captain Fall type is selected. A type's fatigue cost is drawn with `FatigueCost`.
- **Slobber Knocker** is recorded in CLAUDE.md TODOS as pending design: it's really 1 vs N, and the user will define it.

## Match catalog changes

- **Normal Matches:** the Single Match category is relabelled `MatchFamily::Single → "Normal Matches"`. It now also holds Normal Triple Threat and Normal Fatal 4-Way. The `triple_threat` and `fatal_four_way` families are removed.
- **Elimination Tag is removed.** Its variants are split by team size and renamed with a "Tag Elimination" suffix:
    - Tag Team: Normal Tag Elimination, Tornado Tag Elimination, Table Tornado Tag Elimination.
    - 6-Man Tag: Normal 6-Man Tag Elimination, Tornado 6-Man Tag Elimination, Table Tornado 6-Man Tag Elimination, Captain Fall Tornado 6-Man Tag Elimination.
- **Renames:** Tornado 6-Man Tag Chamber → **Triple Threat Tornado Tag Chamber**; Armageddon HIAC (6-Man) → **Armageddon Hell in a Cell**.
- **Fatigue costs** (house rule, max **5 bars**). Family defaults are in `gm.match_type_defaults`, six-competitor bumps in `gm.match_rules.six_competitor_bars`, and per-variant overrides in the data file.

| Bars | Match types |
| --- | --- |
| 1 | Normal Matches, Tag Team, One on Two (Tornado), One on Tag |
| 2 | Tag Elimination, 6-Man Tag, One on Three (Tornado), One on Trio, Two on Trio |
| 3 | Table Tornado Tag Elimination, Captain Fall, Table Tornado 6-Man Tag Elimination, First Blood, Survivor Series Elimination, Hardcore, Ladder, TLC, Table |
| 4 | Six-competitor Hardcore, Ladder (incl. Money in the Bank), TLC and Table; Backstage Brawl, Battle Royal, Last Man Standing, Slobber Knocker, Steel Cage |
| 5 | Royal Rumble, Hell in a Cell, Elimination Chamber, Iron Man, 3 Stages of Hell, Buried Alive, Special Referee Iron Man / Hell in a Cell / Last Man Standing |

Unchanged from before: Fulfill Your Fantasy (1), Submission and Special Referee Single / Tag Match (2).

- **Specialties:** The Great Khali gets Steel Cage (spec §21).

## General UI

- **Full money:** `formatCompactMoney` is removed. Every budget uses `formatMoney` (`$15,000,000`).
- **Font floor:** every sub-12px size becomes `text-xs` (0.75rem). An arch test (`tests/Unit/ArchTest.php`) fails on any `text-[…]` or `font-size` under 12px in `resources/`. No `px` font sizes remain.
- **Bars rule** (CLAUDE.md): fatigue and win/loss streaks are always drawn as bars, never as bare text:
    - `FatigueMeter` for current fatigue;
    - `FatigueCost` (out of 5) for match costs;
    - `StreakMeter` (`md` and compact `sm`) for streaks.
- **Brand colour fix:** `bg-brand`, `text-brand-hot` and `border-brand` were transparent everywhere, because Tailwind resolved `--color-brand: var(--gm-brand)` once on `:root`, where `--gm-brand` doesn't exist. They now live in `@theme inline` in `resources/css/gm.css`. Arbitrary classes use `var(--gm-brand)` directly. This restores page-title kickers, the active nav underline and other brand accents.
- **New colour tokens** in `gm.css`: `--color-gm-orange` (safe fatigue, Unsatisfied morale), `--color-gm-red-deep` (Upset morale, unread pills), and `.gm-name-raw` / `.gm-name-smackdown` (email mentions).

## `/gm` (`saves/index.tsx`, `SaveController`)

- **Default save name:** `My GM Career {saved careers + 1}`.
- **Draft Rules** block above Save Name, with the user's five bullets. The budget comes from `config('gm.starting_budget')`. The old "budget · cap · picks" line is removed.
- **First-pick slot machine:** `SaveController@store` returns `201 {draft_url, first_brand}` to JSON callers. `components/gm/first-pick-reel.tsx` spins Raw/SmackDown `BrandBug` reels for 5s while the request runs, lands on the server's pick, flashes it for 1.6s, then visits the draft. Reduced motion shortens it. Validation errors close the reel and show under the name field.

## Draft and Free Agency (`draft.tsx`, `free-agency.tsx`, `DraftController`)

- **`PoolFilters`** (`components/gm/pool-filters.tsx`) with `filterPool()` and `isCruiser()` in `lib/gm.ts`: roster type, **Cruiser / LHW**, **Specialists** and name search. Used on both pages.
- **Stop drafting** opens `ConfirmDialog` (`components/gm/confirm-dialog.tsx`, a GM-styled shadcn dialog) with the original text: "Stop drafting? You can fill the remaining spots through Free Agency."
- **Skip rest of draft:** once the player has stopped (`player_done`), a button runs `POST draft/ai-finish` (`DraftService::runAi` in a transaction). The animated loop stops after the pick in flight.
- **Draft Picks board:** one list in pick order, `{#}. <WrestlerName> <BrandBug>`. An **All picks / By show** toggle (By show is the old two cards) is remembered in localStorage.
- **Skip animation** is a persistent on/off toggle, read before every rival pick.
- The "Legends sit out the draft" note is removed (it's in the `/gm` Draft Rules).

## Schedule and shows (`ScheduleController`, `schedule.tsx`, `card.tsx`, `ppv-entrants.tsx`)

- Every aired week links to its **Results** (`gm.results`).
- **Season switcher:** `?season=N` covers seasons 1 to current. Out-of-range seasons are a 404.
- **Air Week** moves out of the header into `components/gm/air-week-button.tsx`, with its style unchanged. It renders on the show page (current week, while booking) and on the PPV entrants page.

## Rosters (`rosters/index.tsx`, `rosters/show.tsx`, `RosterController`)

- **List:**
    - Name search; the whole row opens the wrestler (click or Enter).
    - Columns renamed to "Weight Class" and "Record (W-L)", plus a compact Streak bar column.
- **Morale badges:** solid backgrounds with readable text. Happy is green, Content yellow, Unsatisfied orange, Upset deep red with white text.
- **Detail page status panel:** a 3-column grid:
    - Morale, Record (W-L), Streak (`StreakMeter`: "N winning streak" / "N losing streak", up to 10 bars);
    - Weight Class (moved out of the title kicker), Injury, Fatigue;
    - Specialties, Popularity.
- **Title History:** each title name links to `titles?title={key}` (`championship_key` in the payload).

## Titles (`titles/history.tsx`)

- `?title={key}` opens that title's lineage.
- The championship list is grouped under the Raw and SmackDown bugs, with a divider between them.
- "Hall of Champions" is renamed "Current Champions".

## Email (`EmailController`, `email.tsx`, `gm-layout.tsx`)

- **Unread pills:** red with white text (`UnreadPill`), in the list, the page title count and the nav badge. Opening an email marks it read, and the count updates.
- **Name colouring:** the controller sends every save wrestler's name and current brand. `lib/mentions.ts` builds one longest-name-first, word-bounded matcher, and `MentionText` colours each name by brand in subjects, bodies and table rows.

## Spec and CLAUDE.md

- **Spec:** §10 gets the Normal Matches / Tag Elimination grouping, the renames and a "Match rules" section (rules, win conditions, timed, fatigue costs). §21 adds The Great Khali.
- **CLAUDE.md:**
    - the docs-page rule;
    - the bars rule;
    - rules vs. win conditions on `/gm/docs`;
    - match-type house rules live in `config('gm.match_rules')` and the seeders, applied with `migrate:fresh --seed` (no data-only migrations);
    - TODOS trimmed to the Slobber Knocker note.

## Tests

- **Pest:**
    - `tests/Feature/Http/UiTodosTest.php`: JSON new game, starting budget, skip rest of draft, schedule seasons, email mentions and read state, title keys, both docs routes;
    - `tests/Feature/Matches/MatchRulesTest.php`: rope breaks, ring outs, no-title types, DQ/count-out off, regrouping, Khali, Royal Rumble elimination, timed, per-variant fatigue bars and the 5-bar cap, ring-out and rope-break sim effects, Captain Fall summary;
    - the font-floor arch test;
    - catalog, outcome and stipulation tests updated for the renames and new costs.
- **Vitest:**
    - `pool-filter`, `mentions`, `streak-meter`, `first-pick-reel` (fake timers);
    - `card-builder` (Captain label, fatigue bars);
    - `pages/gm/docs` (alphabetical cards, applicable badges, count-out as a win condition, active tab and slide direction).

## Verification

- `composer ci:check` (Pint, PHPStan 7, `vp check`, `tsc`, Pest) and `npm run test` all pass.
- `php artisan gm:types` after the `MatchFamily` change; `php artisan wayfinder:generate --with-form` after the route changes.
- `php artisan migrate:fresh --seed` on a fresh database gives 97 match types, at most 5 fatigue bars, 5 timed types, and every Royal Rumble as an elimination match.
- Browser check against a throwaway SQLite database:
    - new-game reel;
    - draft filters, modal, skip rest of draft, board toggle, persistent skip animation;
    - schedule results and seasons; Air Week on the show page;
    - roster search, row click, colours and status grid;
    - title links and grouping;
    - email pills and coloured names;
    - docs tabs, cards, badges and the in-career header.
