# SvR 2006 GM Mode — full build plan (7 phases)

## Context

The repo is still the stock Laravel 13 + Inertia v3 React starter kit: Fortify auth, Wayfinder, Pest 4, vite-plus, sqlite. No game code exists yet. The goal is the whole GM Mode described in `docs/20261017-spec.md`, covering every section except §18 (Controller Support). The work runs in 7 phases. Each phase ends green on `php artisan test` and `npm run test` and gets one commit naming the phase.

Spec rules that shape everything (from CLAUDE.md):

- All game logic lives in Laravel services.
- All randomness goes through a seeded RNG stored on the save.
- Every tunable value goes in `config/gm.php`.
- Every enumerated value is a backed enum, with generated TS types.
- Week resolution is idempotent and runs in one transaction.
- Deferred systems (Popularity, Match Rating, Power 25) are stubs only.

## Repo facts the plan depends on

- **Testing setup:**
    - `tests/Pest.php` currently **applies** `RefreshDatabase` to `Feature`, which contradicts CLAUDE.md ("commented out"). I'll leave it as is, so Feature tests get a fresh DB.
    - Unit tests stay DB-free.
    - Tests run on in-memory sqlite (`phpunit.xml`). Migrations will avoid driver-specific SQL so they also run on MySQL.
- **Pint:** `pint.json` is the bare `laravel` preset. CLAUDE.md mentions `concat_space: false` and blank-line tweaks that don't exist yet. I won't change it unless you say so.
- **Vitest:** no `test` script and no RTL/jsdom deps. vitest and `@testing-library/dom` are only transitive. **Needs approval (AGENTS.md: no dep changes without approval):** add devDeps `vitest`, `@testing-library/react`, `@testing-library/jest-dom`, `@testing-library/user-event` and `jsdom`, plus a `"test": "vitest run"` script and a `vitest.config.ts`.
- **CI:** `composer ci:check` also runs Pint, PHPStan level 7, `vp check` and `tsc`. I'll keep all four green each phase, not just the two test commands.
- **TS enum generation:** a custom artisan command, `php artisan gm:types`, reflects `App\Enums\*` into `resources/js/types/enums.ts` (const objects, union types and labels). It adds no new composer dependency. A Pest test fails if the committed file drifts from the enums.
- **Auth:** existing Fortify auth stays. A `Save` belongs to a `User`.
- **Design skill:** the `frontend-design` skill isn't installed in this session. I'll follow its approach by hand: a committed aesthetic direction and no default shadcn look.
- **Branch:** pull `main`, then create `feature/20261018-gm-mode` from it. This plan gets copied to `docs/20261018-gm-mode-build.md`, with a row in `docs/README.md`.

## Architecture

- **Catalog vs save state.** Seeded lookup tables are global: `wrestlers`, `match_types`, `injury_types`, `championships`, `ppv_events` and `promo_types`. Each save gets its own `save_wrestlers` rows, which hold brand, morale, fatigue, streaks and clocks. That way several saves can share one seed.
- **RNG.**
    - `App\Support\Rng\Rng` is the interface: `int`, `float`, `chance`, `pick`, `weightedPick`, `shuffle`.
    - `SeededRng` implements it with PHP's `Random\Randomizer` and `Xoshiro256StarStar`.
    - `RngFactory::for(Save, week, stream)` derives each engine from `hash(save.rng_seed, week, stream)`. A retried or replayed week therefore produces identical rolls, and `MatchResult.rng_seed_step` records the step.
    - A Pest `arch()` test bans `rand`, `mt_rand`, `random_int`, `array_rand`, `shuffle` and `str_shuffle` in `app/`. A second test greps `resources/js` for `Math.random`.
- **Week pipeline.** `WeekResolver::resolve(Save, week)` locks the save row, checks `week_resolutions (save_id, week_number UNIQUE)`, and runs ordered `WeekStep` classes inside `DB::transaction`. Each phase registers its own steps.
- **Final step order:**
    1. Air the weekly show, then the PPV, resolving matches in card order.
    2. Titles and stipulations.
    3. Injuries and escalation.
    4. Fatigue recovery.
    5. Contract tick and upkeep.
    6. Expiries.
    7. Morale.
    8. Defection.
    9. Apply pending Free Agency moves.
    10. MITB expiry.
    11. Emails.
    12. News composition.
    13. Advance `current_week`.
- **Rival GM.** An `AiGm` service drives the non-human brand: draft picks, card booking via `CardRandomizer`, trade answers, Rumble and Survivor Series picks, and title awards. The models stay brand-agnostic, so a second human can be added later.
- **Frontend.** Inertia pages live under `resources/js/pages/gm/*`. The frontend only sends bookings and choices. Every result comes from the server. Wayfinder route helpers are used throughout.

## Phase 1 — Foundation (§9, 10, 11, 12, 14, 21)

**Enums** (`app/Enums`):

- Brand, BrandScope, RosterType and WeightClass.
- Morale, InjurySeverity, MatchFamily (also used for specialties) and MatchMethod.
- FinishEvent, Stipulation, ChampionshipKey and ChampionshipEligibility.
- TitleHolderType, WonVia and ReignEndReason.
- ContractLength (int-backed: 5, 12, 24, 36, 52).
- PromoTypeKey, PpvKey, EmailTemplate, NewsEventType, NewsTier and NewsSlot.
- MoraleComplaintType, MitbStatus, TitleShotStatus, TradeStatus, DraftPhase, DraftPickSource, DraftPickType, ShowType and LedgerCategory.

**Migrations (catalog):**

- `wrestlers`: name, slug, roster_type, weight_class, billed_weight_lb (nullable), placeholder_brand (nullable), is_default_manager, overall (nullable, deferred), hometown (nullable).
- `wrestler_specialties`: wrestler_id, family.
- `injury_types`: label, severity, min_weeks, max_weeks, escalates_to_id (nullable).
- `match_types`: name, family, variant, sides (JSON, e.g. `[1,1]`, `[2,2]`, `[1,2]`, `[1,1,1]`), manager_sides (JSON), min/max competitors, fatigue_cost_bars, cost, dq_enabled, countout_enabled, divas_only, ppv_only, ppv_exclusive_id (nullable), brand_restriction (nullable), gm_bookable, win_method.
- `championships`: key, name, brand, eligibility.
- `ppv_events`: key, name, brand_scope, month, season_week, exclusive_match_type_id.
- `promo_types`: key, name, base_amount, targets_rival, variable_amount.

**Seeders** read PHP data arrays transcribed from the spec tables:

- **Wrestlers: 98 total.** 32 Raw, 31 SmackDown, 6 Raw Divas, 7 SmackDown Divas, 22 Legends. Weight classes follow §12, including the rebalance. Jimmy Hart is the default manager.
- **Specialties:** 19 wrestlers and 24 specialty rows.
- **Injury types:** 13 minor and 22 serious, with escalation links. Laceration doesn't escalate.
- **Match types:** 97 rows from the §10 taxonomy, including Traditional Survivor Series and MITB.
- **Championships:** 8.
- **PPVs:** 14 (5 Raw, 5 SmackDown, 4 shared).
- **Promo types:** 11.

**Services and config:**

- `config/gm.php` holds every tunable, with the defaults listed under Ambiguities below.
- RNG classes, `RngFactory`, and the `gm:types` command, which outputs `enums.ts`.

**Tests:**

- Seeded counts per table and per category.
- Weight-class spot checks: every rebalanced wrestler, all Divas Featherweight, Khali and Andre Ultra.
- Escalation mapping.
- The 14 PPVs split 5/5/4.
- Championship eligibility.
- Every enum's values, as dataset tests.
- RNG determinism: same seed gives the same sequence, different streams differ.
- The arch "no rand" test and the enums.ts drift test.

## Phase 2 — Season & week engine (§1, 2, 7)

**Migrations:**

- `saves`: user_id, name, player_brand, rng_seed, current_season, current_week, draft_order.
- `save_brands`: save_id, brand, budget, is_human, deficit_weeks.
- `seasons`: number, started_week, ended_week, starting_budget, royal_rumble_winner_id.
- `save_wrestlers`: brand (null = FA), morale, fatigue, wins, losses, win_streak, loss_streak, weeks_unused, weeks_since_title_match, unbookable_until_week, unsignable_until_week, joined_brand_week.
- `contracts`: save_wrestler_id, length_weeks, upfront_fee, weekly_cost, signed_week, weeks_remaining, status.
- `pending_roster_moves`: Free Agency moves that take effect next week.
- `draft_picks`.
- `shows`: save_id, season, week_number, brand (nullable for interbrand), show_type, ppv_event_id, status (booking / locked / aired).
- `week_resolutions`.
- `ledger_entries`: save_id, brand, season, week, category, amount, memo, morph ref.
- `emails`: brand, season, week, template, from, subject, payload JSON, read_at.
- `news_events`: the full §19 shape, created now so later phases can write to it.
- Minimal `title_reigns`, `tag_teams` and `money_in_the_bank`. §2's expiry rules have to vacate titles and void briefcases, so these tables can't wait until Phase 4.

**Services:**

- `NewGameService`: the save, both brands, the RNG seed, every wrestler in FA, and a random draft order.
- `DraftService`: alternating picks, the 30 cap, stopping early, undrafted wrestlers to FA. The $15M is granted once.
- `ChampionshipAssignment`: opens reign #1 with `awarded`.
- `CalendarService`: weeks, months and PPV weeks.
- `ContractService`: sign, renegotiate (which clears the contract-extension complaint hook), release, tick and expire.
- `LedgerService` and `FinancialReport`: weekly income, expenses and net.
- `EmailService`: Expiring Contracts digest and Contract Expired, with JR/Tazz as sender.
- `NewsRecorder`, `WeekResolver` with its steps, and the `AiGm` draft logic.

**UI:**

- Broadcast-style app shell with brand theming.
- New Game and brand pick.
- Draft room: live board, pick clock, both rosters, budget.
- Championship assignment.
- Schedule (monthly calendar).
- Email inbox, including the digest table with red 1-week rows, champion flags, and Renegotiate/Release actions that link to negotiation.
- Rosters list with a wrestler detail and contract negotiation page.
- Free Agency.
- Financials.

**Tests:**

- All 7 tests listed in §2.
- Resolving the same week twice is a no-op.
- A failing step rolls back the whole week.
- Draft order is randomized per seed and alternates.
- Neither brand can exceed 30.
- Budget is granted only once.
- Upkeep posts to the ledger.

## Phase 3 — Card building & match results (§4, 6, 20, plus the §21 effect)

**Migrations:**

- `match_slots`: show_id, position, match_type_id, title_championship_id, stipulation, cost, fixed_kind (nullable), rating and fan_delta (nullable, deferred).
- `match_participants`: slot_id, save_wrestler_id, side, role (competitor / manager).
- `promo_slots`: the table only, used in Phase 7.
- `injuries`: save_wrestler_id, injury_type_id, severity, started_week, ends_week, escalated_from_minor.
- `match_results`: §20 shape. Each participant's result is stored on its row.
- `rivalries`: wrestler_a, wrestler_b, heat, weeks_active, active. Needed for the Rival Interference finish.

**Services:**

- `CardValidator`:
    - Slot counts: 6 matches + 2 promos on weekly shows, 5 + 0 on PPVs.
    - 2–6 competitors.
    - Managers only on w/Manager sides and never also competing in that match.
    - Seriously injured and sitting-out wrestlers blocked as both competitor and manager.
    - Divas-only, PPV-exclusive and brand restrictions.
    - Multi-booking allowed but flagged.
- `FatigueService`: bars, zones checked going into the match, double booking adds both costs, cap at 100, rest recovery from config. Managing adds 0 (§4, to confirm).
- `InjuryService`: minor roll in the 80–90% zone, guaranteed serious at 100%, label and duration roll, escalation roll each time a minor-injured wrestler competes, fresh duration for escalated injuries, return detection.
- `MatchResolver`: equal base weights, plus a specialty bonus per specialist that cancels when both sides have it. Rolls the winner, then the finish (clean chance from config). Finish events are filtered by the match's DQ and count-out flags and by whether an active rival exists outside the match. Decides the method.
- `ShowResolver`: resolves matches in order and updates W/L and streaks.
- `RivalryService`: basic heat and creation rules.
- `CardRandomizer` core: participants only, used by `AiGm` to book the rival card.

**UI:**

- Card builder:
    - Slot list and match type picker with live cost and a fatigue indicator.
    - Wrestler picker with 10-bar meters, projected fatigue, an injury-zone chip and specialty badges.
    - Yellow flag for multi-booked wrestlers, and a manager selector.
    - Promo slots visible but disabled until Phase 7.
- Match outcome screen: full-screen cards flash one at a time and advance on Enter or click. Each shows winner/loser, method, finish beat, title banner slot, and stipulation slot.
- Reports page, with the rating and fan columns shown as "—".

**Tests:**

- Fatigue zones and the "checked going in" rule (100% → serious; reaching 100% mid-match → no injury).
- Minor-injured wrestlers can be booked; seriously injured can't, as competitor or manager.
- Escalation label and duration come from the target type's range.
- Finish events appear only where the flags allow.
- Rival Interference requires an active rival who isn't in the match.
- Specialty bonus applies and cancels correctly, once per specialist in tag matches.
- Same seed gives the same results.
- **Vitest (card builder):** booking flow, multi-book flag, blocked wrestlers disabled, projected fatigue zone, manager overlap rejected.

## Phase 4 — Titles & stipulations (§10, 16, 22)

**Migrations:**

- Expand `title_reigns` to the full §16 shape, including holder_snapshot and won/lost show and match.
- `tag_team_members`.
- `title_shots`.
- `last_chance_lockouts`.
- Expand `money_in_the_bank`, adding `cash_in_slot_id`.

**Services:**

- `TitleService`:
    - Defend, change hands only on a decisive win (DQ or count-out means retained), count defenses.
    - Vacate automatically for `injured`, `defected`, `loser_leaves`, `released` and `contract_expired`. On tag titles, any member triggering one vacates the team.
    - Open vacant rows, then close them with `awarded` or `vacancy_match`.
- `TitleRecords`: longest, most reigns, most defenses and shortest, per title and across all titles.
- `StipulationService`:
    - **Champion vs Contender:** 1v1 or 2v2, title not on the line. Creates a `TitleShot` due on the next card, delayed by a serious injury, voided on vacancy, and enforced so the card can't air until it's booked.
    - **Loser Leaves Show:** 1v1/2v2 only. Release, refund ledger line, title vacate, MITB void, 4-week re-sign lockout.
    - **Last Chance:** eligibility computed from reign title matches with identical participants that the champion won every time. A loss creates the lockout, which lapses when the reign ends.
- `MitbService`: award, cash-in as an extra 1-on-1 title match allowed after the card is locked, `mitb_cash_in` reigns, spend on loss, expiry at 52 weeks or the next WrestleMania, and every voiding path.
- Card builder: cycle the defended title left/right, limited to titles whose champions are in the match. Stipulation picker that shows Last Chance only when eligible.

**UI:** Title History with lineage per title, Hall of Champions, and Records. A wrestler title history tab on the roster detail. The MITB cash-in button.

**Tests:**

- Every §16 rule.
- Every §22 rule, including the Batista/Undertaker examples.
- MITB expiry and voiding.
- Records across seasons.

## Phase 5 — Morale, defection, trades & News (§8, 15, 19)

**Migrations:** `morale_complaints`, `trades`, `trade_items`.

**Services:**

- `MoraleService`:
    - The four complaints, with config thresholds: 3/5/7 losses, 10/15/18 wins, 4/8/12 weeks without a title match, 2/4/8 weeks unused.
    - Morale is the worst active level.
    - Title-demand eligibility depends on the brand's titles.
    - Serious-injury and sit-out weeks are excluded.
    - Clearing rules follow the spec; managing counts as being used.
    - Each new level sends an email.
    - New signings and draftees start Happy.
- `DefectionService`: odds by morale. Transfer when the rival has room (keeps the contract, vacates with `defected`, voids MITB). Otherwise a 2-week block via `unbookable_until_week`. Sends emails and writes news.
- `TradeService`: up to 5 per side, champions and seriously injured excluded, rejected if either side ends over 30, voids MITB, `AiGm` decides acceptance.
- `NewsComposer`: renders the full §19 catalog from templates, wires events emitted in Phases 2–4, fills tier and brand slots, promotes a top story when a brand has no headline, drops leftovers down a row, dedupes per match. Thresholds come from config.

**UI:**

- Propose Trade, showing each brand's resulting roster size before sending.
- Rivalry list with heat and duration.
- News carousel: newest first, one page per week, arrow keys and swipe. Each page has 3 headline cards, 4 story cards, an expandable card view, and a ticker marquee.
- Morale badges everywhere.

**Tests:**

- Morale level calculation and each clearing rule.
- Injury and sit-out weeks excluded from the clocks.
- Defection: transfer vs blocked, title vacate, MITB void, block lasting exactly 2 weeks.
- Trade validation, every rule.
- News slot filling, headline promotion and dedupe.
- **Vitest (News grid):** layout counts, carousel navigation, card expand, ticker contents.

## Phase 6 — PPV rules (§13, 23)

**Migrations:** `interbrand_series`. Add `is_interbrand` and `owner_brand` on `match_slots`, so a shared slot can appear on both brands' cards. `seasons.royal_rumble_winner_id` already exists.

**Services:**

- `RoyalRumbleService`: the fixed 30-man slot on both brands' Rumble cards. Each GM picks 15 entrants and the AI picks at random. No titles on the line. The winner is recorded on the season.
- `SurvivorSeriesService`: a fixed Traditional 3v3 main event, Raw vs SmackDown, no titles, each GM picks a team.
- `WrestleManiaCardBuilder`:
    - One 10-match card: 3 Raw slots, 3 SmackDown slots, and a main event (Rumble winner vs their brand's world champion).
    - If either is seriously injured, the main event becomes a normal slot booked by the winner's brand.
    - 3 random interbrand matches: 1v1 Normal, 2v2 Normal, and MITB.
- `InterbrandSeriesService`: best of 3. MITB counts for the retriever's brand. The winner gets the exemption and hand-pick tags.
- `ReDraftService`:
    - Up to 10 picks, randomized order then alternating, random draws from the rival's eligible non-champions.
    - Exemption protects one wrestler; hand-pick replaces one random pick.
    - Remaining picks are skipped at 30.
    - Drafted wrestlers keep their contract and morale. A drafted MITB holder's briefcase is voided.
- `SeasonService`: rollover after WrestleMania, budget carried over, `season_reset` news, MITB expiry.

**UI:** Rumble entrant picker, Survivor Series team picker, WrestleMania card view, and the re-draft room with the exemption and hand-pick steps.

**Tests:**

- Rumble 15/15 split.
- Winner's brand gets the main event; injury fallback.
- Interbrand participants exclude champions, seriously injured and sitting-out wrestlers.
- Series 2-of-3 and MITB brand attribution.
- Re-draft: champions ineligible, exemption honored, 9 random + 1 manual, cap skip, no title vacated, no new budget.

## Phase 7 — Promos & card tools (§5, 24)

**Migrations:** `promo_usages`, which tracks Slander's 4-week window, Merchandise scaling and PPV Hype stacking.

**Services:**

- `PromoService`:
    - Validation, e.g. New Superstar only on a wrestler who joined within `gm.promos.new_superstar_window_weeks`.
    - A seeded success roll. Cost is paid even on failure.
    - Advertising pays +$150k next week.
    - Merchandise follows a config scaling curve.
    - Slander backfires automatically on overuse.
    - Popularity and rating effects are recorded as stubs with no formula.
    - Interference and Slander always produce a neutral headline.
- Full `CardRandomizer`, following §24:
    - Bookable pool rules.
    - Match type rules.
    - Title-match rules: one title per card, champion included, eligible challengers, Last Chance lockouts.
    - Exact 422 messages.
    - Preset obligations go first, with fixed slots preserved, card order, `unfilled_slots`, and a cost/deficit warning.
- Endpoints `POST /shows/{show}/slots/{slot}/randomize` and `POST /shows/{show}/preset`. Confirming reuses the normal save endpoint.

**UI:** promo slot picker with cost and odds. A Randomize/Preset preview modal with re-roll, confirm, cancel, the 422 error and the unfilled list.

**Tests:**

- All 14 backend tests listed in §24.
- Promo roll, cost on failure, Slander backfire, Merchandise scaling, Advertising payout.
- **Vitest:** the 4 frontend items listed in §24.

## Visual direction (all phases)

**Look:** late-2000s TV broadcast graphics.

- Near-black "arena" background with a subtle light-rig gradient.
- A condensed display face for headings and chyrons (e.g. Oswald or Anton via bunny fonts) over a clean sans for data.
- Angled lower-third panels, scoreboard-style numerals, and a scrolling ticker.

**Brand accents:** CSS variables switched by `data-brand` on the shell.

- **Raw:** scarlet `#D6001C`, with a gritty scratched-metal texture.
- **SmackDown:** electric blue `#1E63FF` on brushed silver.

**Signature components:**

- The 10-segment fatigue meter in green/yellow/red.
- Match outcome cards with a slam-in "NEW CHAMPION" / "AND STILL…" banner. Animations respect reduced motion.
- The News grid styled like a sports-desk wall.

## Ambiguities — proposed defaults (all go in `config/gm.php` and the PR description)

**Gaps the spec leaves open (I need your call or OK):**

1. **Rival GM AI:**
    - Books with Preset rules.
    - Draft and FA picks are random. The spec has no overall or popularity data to rank on.
    - Accepts a valid trade with config probability `0.5`.
    - Awards titles at random among eligible wrestlers.
2. **Contract pricing (no numbers in spec):**

    | Length | Upfront | Weekly |
    | ------ | ------- | ------ |
    | 5w     | $10k    | $20k   |
    | 12w    | $25k    | $15k   |
    | 24w    | $50k    | $12k   |
    | 36w    | $75k    | $10k   |
    | 52w    | $100k   | $8k    |
    - Flat for every wrestler, with a ×1.5 Legend multiplier.
    - Draft picks sign at a default 52w from the $15M.
    - Re-draft wrestlers keep their contract; renegotiation charges the new upfront fee.

3. **Income:** ratings are deferred, so base show income is a config stub. The default of `$0` makes promos and LLS refunds the only income. Merchandise pays $10k, +$10k for each consecutive week used, capped at $100k, and resets after 2 unused weeks.
4. **Deficit enforcement (§7):** after `deficit_weeks_limit = 4` weeks below $0, a blocking modal forces one of three choices: run an Advertising/Merch promo, switch to free match types only, or release someone.
5. **Calendar:**
    - A season is 52 weeks and starts the week after WrestleMania (season week 1 = mid-April).
    - PPVs sit on fixed `season_week`s derived from the §13 dates (a 2-PPV month gets both).
    - Kept in `config/gm.calendar` as an explicit week→month/PPV table.
6. **Match type values (spec gives none):**
    - **Fatigue bars:** 1 for standard singles/tag/TT/F4W/handicap/battle royal; 2 for most gimmicks; 3 for HIAC, Iron Man, LMS, Ladder, TLC, Elimination Chamber, Buried Alive and MITB; 2 for the 30-man Rumble.
    - **Cost:** $0 for standard; $1k–$50k scaled by family within the §4 range.
    - **DQ and count-out both off:** Hardcore, TLC, Ladder (incl. MITB), Table, HIAC, Steel Cage, LMS, Backstage Brawls, Buried Alive, First Blood, Elimination Chamber, Rumble, Battle Royal and Slobber Knocker.
    - **DQ on, count-out off:** Submission and Iron Man.
    - **Both on:** everything else (standard singles, tag, TT, F4W, handicap, Fulfill Your Fantasy, Survivor Series elimination).
    - Stored in seeder data, easy to edit.
7. **Non-bookable types:**
    - 3 Stages of Hell, Special Referee, and the 10–25-man Rumbles are seeded with `gm_bookable = false`.
    - Slobber Knocker is treated as a 2-competitor gauntlet.
8. **Shared PPV cards:**
    - On Rumble and Survivor Series, each brand books its own card. The interbrand match appears on both and uses one of each brand's 5 slots.
    - WrestleMania is one combined card.
9. **Multi-person W/L:** the winning side gets a win and every other side gets a loss. This feeds the streak and morale clocks.
10. **Champions and the title-match demand:** a current champion is exempt from that complaint.
11. **TitleShot "next card":** the next card that hasn't been booked yet after the result. In practice that's the following week's weekly show.
12. **MITB cash-in:** an extra match outside the slot count, inserted right after a match the GM chooses (default: the champion's last match). Allowed until the week resolves.
13. **Tag teams:** none are seeded, since the spec lists none. The GM forms teams on the Rosters page, and awarding or winning tag gold with a pair creates one automatically.
14. **Rivalries:**
    - Created by the Start a Rivalry promo, or after 2 meetings on opposite sides within 4 weeks.
    - Heat +10 per meeting or Continue promo, −5 per idle week.
    - Ends at 0 heat, or with a blowoff when they meet at a PPV after heat ≥ 50.
15. **Default config odds (all "TBD" in the spec):**
    - Minor injury 15% in the yellow zone; escalation 20% per match; clean finish 60%.
    - Specialty bonus +1 weight on a base of 1.
    - Defection 10% Unsatisfied / 25% Upset.
    - Rest recovery 2 bars per unbooked week.
    - Upset-win margin and `overall` are stubbed, since overall is deferred.
16. **Hints email:** a few rule-based hints (PPV in 2 weeks, unbooked roster, low budget, pending title shot). The spec gives no content.
17. **Rumble with fewer than 15 bookable wrestlers on a brand:** the brand enters as many as it can, down to a config minimum of 10.

**Spec "to confirm" items, implemented as written:**

- §4: managing adds no fatigue but counts as used.
- §6: fatigue is checked going into the match.
- §8: blocked-defection weeks don't count toward inactivity.
- §15: all five clearing rules; new wrestlers start Happy.
- §16: injury vacates only on serious injuries. `gm.titles.vacate_on_injury_over_weeks` is kept as an option, off by default.
- §22: vacancy voids pending shots; LLS is 1v1/2v2 only.
- §23: interbrand participants are random.
- §24: Randomize keeps an already-chosen match type.

**Minor spec notes:**

- Tazz is both a Legend and the SmackDown email sender. I'm treating the sender as the commentator persona only.
- The 30-man Rumble is the only exception to the 2–6 competitor rule. MITB and Armageddon HIAC are 6-man.

## Commits

Commits go at the end of each phase, after all checks pass, for example `add phase 1 foundation: enums, catalog seeders, seeded rng, gm config`. Subjects stay lowercase, imperative and under 100 characters. Each carries the Co-Authored-By trailer. No push or PR unless you ask. After each phase I post a short summary and list the "to confirm" items implemented.

## Verification (every phase)

- `php artisan test` (Pest, including the arch and drift tests).
- `npm run test` (Vitest + RTL).
- `vendor/bin/pint --test`, `composer types:check` (PHPStan 7), `npm run types:check` and `npm run check`.
- `php artisan migrate:fresh --seed` against sqlite. I'll also run it against MySQL if a local server is reachable; otherwise I'll say so.
- A manual pass in the in-app browser through `php artisan serve` + `npm run dev`: that phase's flow, e.g. Phase 2 new game → draft → advance week → email digest, with screenshots checked in both brand themes.
