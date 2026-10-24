# SvR 2006 GM Mode — Web Version

Web-based simulation of a custom SmackDown vs Raw 2006 GM Mode, with house rules.

## Source of truth
- **This file is the source of truth for the game's rules** (see Game rules below). When a change adds or alters a rule, update this file, the dated plan in `docs/`, and `/gm/docs`.
- `docs/20261017-spec.md` is the frozen build-phase spec. **Never edit it.** Read it only for historical detail (seed tables, original wording); its section numbers (§N) are still used in code comments. Where it disagrees with this file, this file wins.
- Anything marked *(assumption)* below or in config: keep the value in config and list it in the PR description.
- Deferred systems (Popularity, Match Rating, Power 25): stub only. Don't invent formulas.
- The in-game documentation page (`/gm/docs`: `DocsController` + `resources/js/pages/gm/docs.tsx`) is the player-facing source of truth for game rules. Keep it current. When a change touches anything it documents or should document (match types, match rules, fatigue, injuries, specialties, stipulations, promos, news events, emails, or a new rule or system), ask the user whether to update the docs page before finishing. Don't update it silently, and don't skip the question.

## Game rules

A web take on SmackDown vs Raw 2006 GM Mode with house rules. The original 2006 game had no momentum bar, weekly grading or angle system.

### Setup and season
- The player runs **Raw** or **SmackDown** against an AI rival GM. Budget **$15M**, granted once at the first draft; it carries over between seasons. Roster cap **30** (Superstars, Divas and Legends all count).
- **Rosters (new game):** **Manual draft pick** (default) runs the initial draft below. **Use WWE roster** skips it: each brand signs the 30 wrestlers the catalog places on it (the real WWE rosters of Nov 14, 2005, trimmed to 30), on the same contract and fee as a draft pick, and the game goes straight to title assignment. Anyone without a brand, Legends included, starts in Free Agency.
- **Initial draft:** up to 30 picks each, random first pick, alternating. Legends can't be drafted (they wait in Free Agency). The rival GM rolls 1–100 each pick: 60+ picks from its division (Raw: Divas; SmackDown: Cruiserweights and Light Heavyweights), else anyone affordable.
- After the draft, titles are awarded in tier order (world, second tier, tag, special); the player can leave one vacant. At award time a wrestler can hold only one title. The rival always crowns someone.
- A season is 52 weeks on one continuous calendar, **April 2005 to March 2006** (week 1 = Sun Apr 3, 2005; WrestleMania = Sun Mar 26, 2006). **Weeks start on Sunday**: PPVs air Sunday, then Raw Monday and SmackDown Friday. 15 PPVs: 6 Raw, 5 SmackDown, 4 shared (Royal Rumble, WrestleMania, SummerSlam, Survivor Series). Every season reuses the same calendar.
- **Booking order:** the player books a week's cards in date order: their brand's PPV card, then the interbrand card (Rumble/Survivor Series entrants or WrestleMania), then the weekly show. "Continue to …" (with a confirmation) locks a card and opens the next; a locked card can be unlocked until the week airs. The weekly show's **Air week N** button (with a confirmation) airs every card of the week, and the results list them in air order (PPV first) with the week's fatigue.
- **Schedule** is a Sunday-first month calendar with both brands' shows. Clicking an aired show opens its results (the rival's too); this week's cards open for booking; one of the player's own later show nights (their weekly show, their brand's PPVs, shared PPVs; never the rival's) offers **Simulate to here** (with a confirmation). Simulating books the player's cards for every week before the target with Preset rules, random promos and random Rumble entrants, and airs them normally: results, fatigue, injuries, title changes, contracts, morale, news and email all happen. It stops at WrestleMania and leaves the target week to book.
- **WrestleMania re-draft:** up to 10 random picks each from the rival's non-champions; the interbrand series winner swaps one for a manual pick and protects one of its own. The 30 cap still applies.

### Contracts and money
- Lengths 5 / 12 / 24 / 36 / 52 weeks; shorter costs less up front and more per week. Every contract ticks down 1 per week (injured or unused too) and charges weekly upkeep. At 0 the wrestler goes to Free Agency, titles vacate, briefcases void. Renegotiating resets the clock.
- An Expiring Contracts digest email lists everyone with 4 or fewer weeks left; a Contract Expired email follows.
- Free Agency signings and roster moves take effect the following week. Trades are up to 5-for-5, uneven allowed; champions and seriously injured wrestlers can't be traded; neither roster may end over 30. The rival GM answers every offer by email.
- A long deficit forces a choice: money promos, free match types only, or releases.

### Card building
- Weekly shows: **6 match slots and 2 promo slots**. PPVs: 5 match slots, no promos. Each slot: match type, then 2–6 competitors, then an optional stipulation.
- No per-card limit on bookings; double-booked wrestlers are flagged and take both fatigue costs. Seriously injured wrestlers and wrestlers sitting out (blocked defection, Buried Alive) can't be booked as anything.
- **Managers:** anyone on the roster can manage a w/Manager match they aren't competing in. Managing adds no fatigue but counts as being used. Jimmy Hart is a regular wrestler.
- **Special Referee** matches need a referee from the roster who isn't competing or managing and isn't seriously injured. The referee takes no fatigue, injury or record.
- **Titles:** a title can be on the line only if its champion(s) are in the match, and **each title only once per card**. Divas can only hold or challenge for the Women's title; the Cruiserweight title is for Cruiserweight and Light Heavyweight men.
- **Randomize** (one slot) and **Preset** (empty slots) only propose bookings; confirming goes through normal validation. They draw from bookable wrestlers not already on the card, never add stipulations or managers, and book a pending title shot first.

### Match types
- Families and variants are seeded from `database/seeders/data/match_types.php`. Normal Matches merges singles, Triple Threat and Fatal 4-Way.
- **Rules per type** (`config('gm.match_rules')`): no rope breaks or count-outs in Hardcore, TLC, Table, Ladder, Battle Royal, Royal Rumble and Hell in a Cell; no ring outs in Steel Cage, Battle Royal, Royal Rumble and Elimination Chamber; no DQ or count-out in Normal Triple Threat / Fatal 4-Way; no titles in 3 vs 3 matches, Captain Fall, Handicap and Slobber Knocker. Captain Fall is won by beating the other team's captain (first wrestler on the side).
- **Fatigue cost** per type is 1–5 bars; **prices** run $0–$55,000 (family defaults in `config('gm.match_type_defaults')`, per-variant overrides in the data file).
- **PPV exclusives:** Elimination Chamber (New Year's Revolution, Raw), Buried Alive (No Mercy, SmackDown; the loser sits out 4 weeks, their contract pauses, and they can't be injured by it), 30-man Royal Rumble (15 per brand, no titles; the winner's brand gets the WrestleMania main event), Traditional 3 vs 3 Survivor Series Elimination (always that PPV's Raw vs SmackDown main event), Money in the Bank (WrestleMania, 6-man ladder, no Divas or titles; the briefcase is a world title shot, cashable on any card within 52 weeks or until next WrestleMania, voided if the holder leaves the brand).
- **WrestleMania** is one 10-match interbrand card: 3 Raw slots, 3 SmackDown slots, the main event (Royal Rumble winner vs their brand's world champion), and a 3-match random interbrand series (1v1, 2v2, Money in the Bank) whose winner gets the re-draft perks.

### Promos
- Each promo rolls its success rate with the seeded RNG; the cost is paid either way. Interference and Slander are fixed at 50% and always make the neutral news headline. Slander backfires if used more than once in 4 weeks.
- `PromoEligibility` decides who can run each promo; unavailable promos are greyed out and booking rejects them. Highlights: Inactive Superstar targets someone **not booked on the card**; Interference needs a healthy non-Diva; Start a Rivalry needs two healthy wrestlers not already in a rivalry; Diva Bikini Contest needs two healthy Divas; Title Match Hype needs a saved title match on the card; Comeback Reminder needs someone seriously injured for more than 3 weeks.
- Each promo airs **before a chosen match** (default: before the main event). Interference and Start a Rivalry add **2 fatigue bars** there (success or fail) and roll injuries like a match.
- A Title Match Hype whose title match is gone fizzles; an Inactive Superstar whose target ends up booked fizzles. Popularity and rating effects are stubs. There are 14 promos (Injury Return was dropped as a duplicate of Comeback Reminder).
- **Promo stories (house rule):** every promo's news story is written after the show airs (`PromoStoryWriter`, texts in `PromoStories`) from what happened around it. Interview Space: the wrestler's match (success or fail × won or lost); unbooked, a generic interview plus extras for their form (3+ win or loss streak, a long wait for a title match, holding a title). Main Event Hype: the main event (title change, retained, the challenger's DQ/count-out win, or a non-title match), a success being the brand's headline. Title Match Hype: that title match, and where it was on the card. Every other promo: success or fail (Inactive Superstar and Title Match Hype can fizzle; Slander can backfire). Every situation has at least 4 variants, drawn with equal odds from the seeded RNG; stories that name a wrestler always name them.

### Fatigue and injuries
- Fatigue is 0–100%, drawn as 10 bars. Fatigue going into a match decides the risk: 0–70% safe, 80–90% rolls for a minor injury, 100% means a guaranteed serious injury in any match that adds fatigue.
- **Weekly recovery (house rule):** at the end of every week **everyone recovers 2 bars** (`gm.fatigue.rest_recovery_bars`), booked or not. A successful Inactive Superstar promo gives its target **2 more** (`gm.promos.recovery_bars`). Fatigue never drops below 0. Only wrestlers in matches or fatiguing promos gain fatigue; recovery applies to everyone. Examples: A starts at 30%, wrestles a 50% match (80%), ends the week at 60% and starts the next show there. B starts at 40%, isn't booked (still 40%), ends at 20%. An unbooked wrestler at 30% with a successful Inactive Superstar ends at 0%.
- Injuries: minor 1–4 weeks (can still compete; may escalate to serious each time they do), serious 5–52 weeks (can't be booked). Labels and durations are seeded.
- A champion's serious injury vacates the title immediately.
- The Trainer emails the GM about every injury and escalation; a seriously injured superstar emails 2 weeks before they're cleared.

### Outcomes
- Matches resolve in card order with promos in between. Outcomes are placeholders: equal base weights, plus a specialty bonus (specialists cancel out), then a finish event (Referee Attacked, Rival Interference, Foreign Object, Count-Out, Cheap Roll-Up) or a clean finish. A clean finish won by a Submission specialist is a submission. Titles change hands only on a decisive win (not DQ or count-out).
- The results screen lists every result; each opens its full outcome card with arrows to step through, plus each wrestler's fatigue going in → after the show → after recovery.

### Stipulations (max one per match)
- **Champion vs Contender:** 1v1 or 2v2, no title on the line; a contender win earns a title match on the next show or PPV, and the card can't air until it's booked.
- **Loser Leaves Show:** 1v1 or 2v2 only; the loser goes to Free Agency, their contract is refunded, titles vacate, and nobody can sign them for 4 weeks.
- **Last Chance:** only after the same participants have met twice in the current reign with the champion winning both; a loss locks the contender out of title matches against that champion until the title changes hands.
- A contender's DQ or count-out win counts as a champion win for every stipulation.

### Morale, defection and titles
- Morale is Happy / Content / Unsatisfied / Upset: the worst of four complaints (losing streak 3/5/7; contract demand at win streaks 10/15/18; no title match for 4/8/12 weeks; unused for 2/4/8 weeks). Injury and sit-out weeks don't count. The superstar emails the GM when a complaint reaches Unsatisfied or Upset (Content doesn't email).
- Unsatisfied and Upset wrestlers may try to defect. They transfer if the rival has room (titles vacate, briefcase voids), otherwise they sit out 2 weeks.
- Every title keeps a full lineage across seasons, including vacancies and the reason (defected, injured, Loser Leaves, released, contract expired), plus records.

### News and email
- News is a weekly report: one page per played week from week 1 (quiet weeks show "no headline/story this week"), with 3 headlines (Raw, WWE, Smackdown), 4 stories (2 per brand) and a ticker. Every card shows its headline plus a short summary of why it happened.
- Everything newsworthy in a week goes into one pool: every match result, title changes and defenses, Champion vs Contender shots, promo results, rivalries (started, heating up, 10 hot weeks, blow-off), signings, contracts with 2 weeks left, injuries and returns, upcoming PPVs. `NewsComposer` draws headlines and stories from it with the seeded RNG, weighted by priority. Brand cards only use their brand's events. The WWE card only uses cross-brand news (shared-PPV results, Royal Rumble winner, interbrand series, trades, defections, legends, re-draft, season reset). A Slander or Interference result always takes it. A plain match result gives way to bigger news from the same match. Whatever isn't picked runs in the ticker.
- Email carries private GM notices. JR (Raw) / Tazz (SmackDown): Welcome at each season start, Superstar specialties at season weeks 1 and 27, the Interference reminder after 13 unused weeks (once per stretch), Low on Cash at $500,000 or less (once every 4 weeks), contract digests, deficit and defection notices. Rival GM: trade answers (accept or decline, one superstar per line) and Slander reactions. Vince McMahon: reminders 2 weeks before SummerSlam, Survivor Series, Royal Rumble and WrestleMania, and the re-draft after WrestleMania. Trainer: every injury (who, what, how long). Superstars: morale complaints at Unsatisfied/Upset, contract up in 2 weeks, back from serious injury in 2 weeks.

### Deferred
- Popularity, Match Rating and the Power 25 ranking: structure only, no formulas. Controller (XInput) support is planned but not built.

## Stack
- Backend: Laravel + MySQL. All game logic lives here as service classes (week resolution, match sim, fatigue/injury, morale, contracts, titles, finances).
- Frontend: React + TypeScript via Inertia.js. The frontend books cards and displays results; it never decides outcomes.
- Tests: Pest (backend), Vitest + React Testing Library (frontend).

## Rules
- Use PHP backed enums for every enumerated value in the spec (weight class, morale, injury severity, match family, stipulation, end reason, email template…). Generate matching TS types; don't hand-write duplicates.
- All randomness goes through an injectable, seeded RNG stored on the save. Never call `rand()`/`Math.random()` directly. Tests use a fixed seed.
- Tunable numbers (odds, thresholds, durations, success rates) go in `config/gm.php`, not inline.
- Week resolution must be idempotent per `week_number` and run in a DB transaction.
- Seed data (roster, match types, injury types, titles, PPVs, promos, specialties) comes from the spec tables via seeders. The roster's `placeholder_brand` is the Nov 14, 2005 WWE roster (30 per brand, see `docs/20261024-2005-roster.md`): a manual draft ignores it, and "Use WWE roster" signs it.
- Every rule with a test list in the spec gets those tests before the feature is considered done.
- No login or user accounts for now: saves are global, `/` redirects to `/gm`, and there is no `users` table, Fortify or settings area. Don't reintroduce auth unless asked.
- A career is listed under Saved careers only after the player hits **Save career** (`saves.saved_at`). After that, progress is kept as it happens. Starting a new game deletes any never-saved career.
- Roster names are plain ring names with no nicknames (`Roddy Piper`, not `"Rowdy" Roddy Piper`). There are no managers-by-default; Jimmy Hart is a regular wrestler.
- On `/gm/docs`, a match's **rules** (DQ, Elimination, Ring out, Rope breaks, Special referee, Timed, Titles) and **win conditions** (Pinfall, Submission, Count-out, Captain fall, and the type's own condition) are separate: the Match rules and Win conditions cards sit side by side, and each match row shows only the badges that apply, sorted alphabetically. When you add a rule or win condition, add its description to the matching card in `resources/js/pages/gm/docs.tsx`.
- The `/gm/docs` **News & email** tab lists every news event from `App\Services\News\NewsCatalog` (group, card, tier; headline and summary templates come from `NewsTemplates`) and every GM email from `DocsController::emails()`. When you add a news event type, add it to `NewsCatalog` (a test fails otherwise). When you add an email, add its row to `DocsController::emails()`.
- Match-type house rules (rope breaks, ring outs, no-title types, six-competitor fatigue) live in `config('gm.match_rules')`; per-variant overrides go in `database/seeders/data/match_types.php`. Match prices: family defaults in `config('gm.match_type_defaults')`, per-variant `cost` overrides in the data file. Catalog data changes go in the seeders only; apply them with `php artisan migrate:fresh --seed` (it wipes saves, which is fine for now). Don't write data-only migrations; migrations are for schema changes.
- Fatigue and win/loss streaks are always drawn as bars, never as bare text or percentages: `<FatigueMeter>` (`components/gm/fatigue-meter.tsx`) and `<StreakMeter>` (`components/gm/streak-meter.tsx`), as on `/gm/saves/{#}/rosters/{#}`. This includes fatigue *costs* such as a match type's fatigue on `/gm/docs`.
- Name colour coding (SvR 2006 style): Cruiserweights and Light Heavyweights render blue, Divas red. Use `<WrestlerName>` from `resources/js/components/gm/wrestler-name.tsx` anywhere a wrestler name is listed.
- The card screen uses custom pickers, not `<select>`: `<WrestlerPicker>` (`components/gm/wrestler-picker.tsx`) for wrestler, manager and referee slots, and `usePopover`/`<PopoverPanel>` (`components/gm/popover.tsx`) for anything else that opens a list. Titles and stipulations are left/right cyclers (`components/gm/title-tools.tsx`).
- Clear and other destructive buttons use `gm-btn-danger` and confirm through `<ConfirmDialog danger>`.
- Custom styles live in `resources/css/gm.css` inside `@layer components`. Tailwind utilities (e.g. `text-ink-dim`) beat that layer regardless of specificity, so an override of a utility there needs `!important`.

## Architecture notes
- Catalog tables (`wrestlers`, `match_types`, `championships`, …) are shared; per-save state lives in `save_wrestlers` and friends.
- RNG: `$save->rng($stream, $week)` (Xoshiro256**). Stream keys must be stable across runs (e.g. `Show::streamKey()`), never DB ids.
- Week resolution: `WeekResolver` runs ordered `WeekStep`s after its `WeekGuard`s pass.
- `GameRuleException` returns a 422 JSON body to fetch callers; for Inertia requests it flashes a toast and redirects back with a `game` error.
- JSON (fetch) callers of mutating card endpoints get `204`, not a redirect. A 302 after a `PUT` makes the browser re-send the `PUT`.
- Promo availability lives in `PromoEligibility`: the card screen greys promos out from it and `PromoService::book` validates against it, so the two can't disagree. Promos air before a chosen match (`promo_slots.before_position`, default the main event); `ShowResolver` resolves them in between matches, so promo fatigue counts going into later matches.
- Special Referee matches store the referee as a `MatchParticipant` with `role = referee`. Anything that loops over participants must only treat `competitor`s as competitors.
- Booking order lives in `BookingOrder` (`shows.confirmed_at` marks a card the player finished). Card mutations go through `assertEditable` in the controllers, not the services, so the AI and the simulator (`WeekSimulator`) can still book. `WeekController::advance` checks `assertReadyToAir`; `WeekResolver` itself doesn't, so tests can resolve weeks directly.
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

The 2026-10-19 docs page, match rules and UI polish work is done; see [docs/20261019-docs-page-match-rules-and-ui-polish.md](docs/20261019-docs-page-match-rules-and-ui-polish.md). The 2026-10-20 card booking UI, Special Referee and promo rework is done; see [docs/20261020-card-booking-ui-special-referee-and-promos.md](docs/20261020-card-booking-ui-special-referee-and-promos.md). So is the 2026-10-20 results screen and weekly recovery change; see [docs/20261020-results-screen-and-weekly-recovery.md](docs/20261020-results-screen-and-weekly-recovery.md). The 2026-10-20 news and email rework is in [docs/20261020-stories-and-events.md](docs/20261020-stories-and-events.md). The 2026-10-21 calendar, booking order and simulation work is in [docs/20261021-calendar-schedule-and-booking-order.md](docs/20261021-calendar-schedule-and-booking-order.md).

- **Rival GM draft focus (decide later):** the rival rolls 1–100 per initial-draft pick and goes for its division on `ai_focus_from` (60) or higher, which is 41% of rolls, not 40%. If 40% is wanted, set `gm.draft.ai_focus_from` to 61 and update this file and the Rosters & draft tab on `/gm/docs`. Logic: `AiGm::draftPick`.
- **Alignment-based promo stories (needs face/heel alignment):** Interview Space stories where the wrestler is goofy or annoying, or bashes the fans in attendance, and Title Match Hype stories where a champion retains by heelish tactics. Add them to `PromoStories` once wrestlers have an alignment.
- **Attendance in promo stories (needs Popularity):** an unbooked Interview Space that hypes the show should be able to quote the week's attendance.
- **Free agents retiring (not built):** superstars should retire out of the Free Agency pool over time. When built, a retirement makes the WWE (neutral) news and emails the GM.
- **SummerSlam main event (not built, needs Popularity):** the show with the most fans going into SummerSlam gets its main event slot. Vince's SummerSlam reminder email already mentions it.
- **Slobber Knocker (pending design):** a 1 vs N match where one wrestler faces an endless line of opponents until they lose. For now it's seeded as a 1-on-1 `No Limit` variant and can't have titles on the line. The user has ideas for how it should really work, so ask before changing its booking or sim rules.
