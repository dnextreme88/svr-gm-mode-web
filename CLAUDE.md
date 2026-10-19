# SvR 2006 GM Mode — Web Version

Web-based simulation of a custom SmackDown vs Raw 2006 GM Mode, with house rules.

## Source of truth
- Full spec: `docs/20261017-spec.md`. Read the relevant section before working on a feature; quote the section number in plans.
- Anything marked *(to confirm)* or *(assumption)* in the spec: implement it as written, keep the value in config, and list it in the PR description.
- Deferred systems (Popularity, Match Rating, Power 25): stub only. Don't invent formulas.
- The in-game documentation page (`/gm/docs`: `DocsController` + `resources/js/pages/gm/docs.tsx`) is the player-facing source of truth for game rules. Keep it current. When a change touches anything it documents or should document (match types, fatigue, injuries, specialties, stipulations, or a new rule or system), ask the user whether to update the docs page before finishing. Don't update it silently, and don't skip the question.

## Stack
- Backend: Laravel + MySQL. All game logic lives here as service classes (week resolution, match sim, fatigue/injury, morale, contracts, titles, finances).
- Frontend: React + TypeScript via Inertia.js. The frontend books cards and displays results; it never decides outcomes.
- Tests: Pest (backend), Vitest + React Testing Library (frontend).

## Rules
- Use PHP backed enums for every enumerated value in the spec (weight class, morale, injury severity, match family, stipulation, end reason, email template…). Generate matching TS types; don't hand-write duplicates.
- All randomness goes through an injectable, seeded RNG stored on the save. Never call `rand()`/`Math.random()` directly. Tests use a fixed seed.
- Tunable numbers (odds, thresholds, durations, success rates) go in `config/gm.php`, not inline.
- Week resolution must be idempotent per `week_number` and run in a DB transaction.
- Seed data (roster, match types, injury types, titles, PPVs, promos, specialties) comes from the spec tables via seeders. The roster's brand assignment is a placeholder; rosters are built by the draft.
- Every rule with a test list in the spec gets those tests before the feature is considered done.
- No login or user accounts for now: saves are global, `/` redirects to `/gm`, and there is no `users` table, Fortify or settings area. Don't reintroduce auth unless asked.
- A career is listed under Saved careers only after the player hits **Save career** (`saves.saved_at`). After that, progress is kept as it happens. Starting a new game deletes any never-saved career.
- Roster names are plain ring names with no nicknames (`Roddy Piper`, not `"Rowdy" Roddy Piper`). There are no managers-by-default; Jimmy Hart is a regular wrestler.
- On `/gm/docs`, a match's **rules** (DQ, Elimination, Ring out, Rope breaks, Timed, Titles) and **win conditions** (Pinfall, Submission, Count-out, Captain fall, and the type's own condition) are separate: the Match rules and Win conditions cards sit side by side, and each match row shows only the badges that apply, sorted alphabetically. When you add a rule or win condition, add its description to the matching card in `resources/js/pages/gm/docs.tsx`.
- Match-type house rules (rope breaks, ring outs, no-title types, six-competitor fatigue) live in `config('gm.match_rules')`; per-variant overrides go in `database/seeders/data/match_types.php`. Catalog data changes go in the seeders only; apply them with `php artisan migrate:fresh --seed` (it wipes saves, which is fine for now). Don't write data-only migrations; migrations are for schema changes.
- Fatigue and win/loss streaks are always drawn as bars, never as bare text or percentages: `<FatigueMeter>` (`components/gm/fatigue-meter.tsx`) and `<StreakMeter>` (`components/gm/streak-meter.tsx`), as on `/gm/saves/{#}/rosters/{#}`. This includes fatigue *costs* such as a match type's fatigue on `/gm/docs`.
- Name colour coding (SvR 2006 style): Cruiserweights and Light Heavyweights render blue, Divas red. Use `<WrestlerName>` from `resources/js/components/gm/wrestler-name.tsx` anywhere a wrestler name is listed.
- Custom styles live in `resources/css/gm.css` inside `@layer components`. Tailwind utilities (e.g. `text-ink-dim`) beat that layer regardless of specificity, so an override of a utility there needs `!important`.

## Architecture notes
- Catalog tables (`wrestlers`, `match_types`, `championships`, …) are shared; per-save state lives in `save_wrestlers` and friends.
- RNG: `$save->rng($stream, $week)` (Xoshiro256**). Stream keys must be stable across runs (e.g. `Show::streamKey()`), never DB ids.
- Week resolution: `WeekResolver` runs ordered `WeekStep`s after its `WeekGuard`s pass.
- `GameRuleException` returns a 422 JSON body to fetch callers; for Inertia requests it flashes a toast and redirects back with a `game` error.
- JSON (fetch) callers of mutating card endpoints get `204`, not a redirect. A 302 after a `PUT` makes the browser re-send the `PUT`.
- The rival GM (`AiGm`) books with Preset rules and makes random draft picks. In the draft room the frontend steps the AI one pick at a time via `POST draft/ai-step`, so it can animate each pick.

## Commands
- `php artisan test` — backend tests (Pest); `npm run test` — frontend tests (Vitest + RTL, jsdom)
- `composer ci:check` — Pint, PHPStan (level 7), `vp check`, `tsc` and tests
- `php artisan gm:types` — regenerate `resources/js/types/enums.ts` from `App\Enums` after changing an enum. A drift test fails if you forget.
- `php artisan wayfinder:generate --with-form` — regenerate route helpers. Always pass `--with-form`; pages use `.form` variants.
- `php artisan migrate:fresh --seed` — rebuild the catalog
- `npm run dev` / `php artisan serve` — local dev

## Git Conventions

- **Git**: feature branches follow `type/YYYYMMDD-description` (e.g. `enhancements/20260519-blog`, `chore/20260404-...`, `feature/...`). Commit subjects are lowercase and imperative (`add`, `fix`, `refactor`, `update`), and the first line must stay within 100 characters (it is the only part shown in summaries). PRs merge to `main`.
- **Pull requests**: always assign the repo owner [dnextreme88](https://github.com/dnextreme88) as both assignee and reviewer on every PR you create (e.g. `gh pr create --assignee dnextreme88 --reviewer dnextreme88`), so they don't have to do it manually afterward.
- **Formatting** is enforced by Pint using [pint.json](pint.json) (currently the bare Laravel preset). Run `vendor/bin/pint` before committing. Frontend formatting/lint runs through `npm run check` (vite-plus). The generated `resources/js/types/enums.ts` is excluded from it.
- **Plans**: implementation/upgrade plans live in [docs](docs), one Markdown file per plan named `{YYYYMMDD}-kebab-case-topic.md` where the date is when the plan was executed (see the existing and renamed files there for the format). Add a matching entry to [docs/README.md](docs/README.md) — link the dated filename and bold the executed date.
- **Executing a plan**: before starting the work in a plan, pull the latest `main`, then create a new branch off it with a meaningful name per the `type/YYYYMMDD-description` convention above. Do all plan work on that branch, never directly on `main`.
- **Tests** use Pest — all tests in this suite are written with Pest. [tests/Pest.php](tests/Pest.php) applies `RefreshDatabase` to every `Feature` test (in-memory sqlite); `Unit` tests stay DB-free. Build game state with the helpers in `tests/Support`: `GameBuilder` (`newSave`, `inSeason`, `wrestler`, `giveTitle`), `Card` (`show`, `book`, `type`, `singles`) and `FixedOutcomes` (scripted winners, matched by who is in the match). Arch tests ban `rand()`-family calls in `app/` and `Math.random` in `resources/js`. When you change a file, run the relevant tests (`vendor/bin/pest`); if no test covers the change, write one before finishing.

## TODOS

The 2026-10-19 docs page, match rules and UI polish work is done; see [docs/20261019-docs-page-match-rules-and-ui-polish.md](docs/20261019-docs-page-match-rules-and-ui-polish.md).

- **Slobber Knocker (pending design):** a 1 vs N match where one wrestler faces an endless line of opponents until they lose. For now it's seeded as a 1-on-1 `No Limit` variant and can't have titles on the line. The user has ideas for how it should really work, so ask before changing its booking or sim rules.
