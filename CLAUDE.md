# SvR 2006 GM Mode — Web Version

Web-based simulation of a custom SmackDown vs Raw 2006 GM Mode, with house rules.

## Source of truth
- Full spec: `docs/20261017-spec.md`. Read the relevant section before working on a feature; quote the section number in plans.
- Anything marked *(to confirm)* or *(assumption)* in the spec: implement it as written, keep the value in config, and list it in the PR description.
- Deferred systems (Popularity, Match Rating, Power 25): stub only. Don't invent formulas.

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

## Commands
- `php artisan test` — backend tests
- `npm run test` — frontend tests
- `npm run dev` / `php artisan serve` — local dev

## Git Conventions

- **Git**: feature branches follow `type/YYYYMMDD-description` (e.g. `enhancements/20260519-blog`, `chore/20260404-...`, `feature/...`). Commit subjects are lowercase and imperative (`add`, `fix`, `refactor`, `update`), and the first line must stay within 100 characters (it is the only part shown in summaries). PRs merge to `main`.
- **Pull requests**: always assign the repo owner [dnextreme88](https://github.com/dnextreme88) as both assignee and reviewer on every PR you create (e.g. `gh pr create --assignee dnextreme88 --reviewer dnextreme88`), so they don't have to do it manually afterward.
- **Formatting** is enforced by Pint's custom ruleset in [pint.json](pint.json) (Laravel preset + tweaks like `concat_space: false` and blank line before control statements). Run `vendor/bin/pint` before committing.
- **Plans**: implementation/upgrade plans live in [docs](docs), one Markdown file per plan named `{YYYYMMDD}-kebab-case-topic.md` where the date is when the plan was executed (see the existing and renamed files there for the format). Add a matching entry to [docs/README.md](docs/README.md) — link the dated filename and bold the executed date.
- **Executing a plan**: before starting the work in a plan, pull the latest `main`, then create a new branch off it with a meaningful name per the `type/YYYYMMDD-description` convention above. Do all plan work on that branch, never directly on `main`.
- **Tests** use Pest — all tests in this suite are written with Pest. `RefreshDatabase` is commented out in [tests/Pest.php](tests/Pest.php), so DB-touching tests must opt in deliberately. When you change a file, run the relevant tests (`vendor/bin/pest`); if no test covers the change, write one before finishing.
