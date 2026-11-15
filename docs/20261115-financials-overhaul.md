# Financials Overhaul, Call-out Promo, Rivalry Booking, Slot Reordering & Superstar Profile

Executed **2026-11-15** on `feature/20261115-financials-overhaul`.

## Context

The Financials page works, but the game's economy is thin and partly fake. A flat $250k "show income" is paid every week while the attendance gate is computed and thrown away. Contracts have flat prices that ignore who is signed. There are no production, medical or release costs, and Interference/Slander only ever cost money. The page itself is a category list next to a bar list.

This plan does four things:

- Rebuilds the economy around the rules the user set.
- Redesigns the weekly statement as option B (itemized statement plus a split bar, grouped by night) with an option C trend as the week picker.
- Adds a Call-out promo.
- Makes Preset/Randomize book rivalries, lets the player drag match slots, and adds a Brand column to the Rivalries page.

Branch: `feature/20261115-financials-overhaul` off a fresh `main`. Per memory, nothing is committed until the user asks. Write a dated plan `docs/20261115-financials-overhaul.md`, add a `docs/README.md` entry, update CLAUDE.md's rules, and update `/gm/docs` (the user said yes).

## Decisions (from the user)

**Show production cost**

- $50,000 for each weekly show and $100,000 for each PPV night, charged to each brand on the card.
- Charged when the night closes.

**Superstar salaries (seeded per wrestler)**

- New `wrestlers.monthly_salary` column, filled in `database/seeders/data/wrestlers.php`:
    - Male non-Legend: $20,000
    - Legend: $45,000
    - Diva: $12,000
- Contract total = monthly × months. Months per length (config): 5 wk = 1, 12 wk = 3, 24 wk = 6, 36 wk = 9, 52 wk = 12.
    - Example: a male on 9 months costs $180k in total.
- **Paid weekly:** `weekly_cost = round(total / weeks)`, and there is no upfront fee (`upfront_fee` = 0).
    - Example: a male on 12 months pays $240k / 52 = $4,615 a week.
- The old `contracts.prices` table and `legend_multiplier` go.
- `ContractLength::label()` becomes 1 month / 3 months / 6 months / 9 months / 12 months.

**Affordability**

- A signing (draft, free agency, renegotiation, AI) needs `budget ≥ contract total`.
- The draft room's running budget is the budget minus the total of the contracts drafted so far _(assumption)_.

**Draft contract length**

- A length dropdown on each draft pick (1/3/6/9/12 months, default 12).
- `POST draft/pick` takes `length`. Random pick uses the dropdown's current value.
- **Use WWE roster** signs every placed superstar for 6 months (24 weeks).

**Releases**

- Early termination costs `weekly_cost × weeks_remaining`, posted as `ReleaseFee`.
- The Release confirmation shows the fee.
- Contract expiry and Loser Leaves are unchanged (Loser Leaves still refunds).

**Medical rehab**

- Every week, each brand pays $5,000 for each superstar with a minor injury and $10,000 for each with a serious one.

**Fan attendance revenue**

- Turn `gm.attendance.drives_income` on and remove the flat $250k (`weekly_show_income`).
- Gate = crowd × ticket price, as today. A shared night's gate is split between its brands, as `PayShowIncome::payGates` already does.
- **Tuning (2026-11-15):** ticket prices cut from $30 / $50 / $75 to **$20 / $35 / $50** (weekly, brand PPV, shared PPV). At the old prices the demo career's season 1 netted +$7.5M (Raw, rival) and +$11.9M (Smackdown, player); now +$0.75M and +$5.1M, so weekly shows about break even and PPVs carry the profit.

**Attendance bonus**

- +$100,000 to the brand whose nights drew the bigger total crowd that week. A tie pays nobody _(assumption)_.

**Advertising**

- Always succeeds (100%) and pays $200,000 the **same** week (no longer next week).

**Merchandise Sponsorship (a gamble, not tiers)**

- Free to run.
- The success chance is drawn from the existing four (80/70/50/25%), and the payout is drawn independently, with equal odds, from $10k / $25k / $50k / $100k.
- Failure pays nothing.
- _(Assumption: "retains 25%/80%" means the chance is still one of those four values, rolled each airing; confirm in review.)_

**Interference Promotion**

- Success: +$100,000 to the interfering brand.
- Failure or backfire: −$25,000 on top of its $50k cost.
- Same rule for both brands.

**Call-out promo**

- Success rate 80%. Costs $10,000 either way.
- Needs an active rivalry. Inputs: the rivalry, then one of its two rivals, then Face or Heel.
- Success: +10 heat, and the week counts as rivalry activity (no quiet-week decay, like Continue Rivalry's hold).
- No fatigue.
- News and email for face/heel × success/fail; the email comes **from the chosen superstar**.

**Deficit timing bug**

- Move `PayShowIncome` before `TrackDeficit`, so a week's gate counts before the deficit check.

## 1. Economy backend

**Config (`config/gm.php`)**

- `finances.show_cost` (weekly 50000, ppv 100000)
- `finances.medical` (minor 5000, serious 10000)
- `finances.attendance_bonus` (100000)
- `contracts.months` (map) and `contracts.salary` (superstar/legend/diva defaults, used by the seeder)
- `promos.amounts.advertising` = 200000 and `success_rates.advertising` = 1.0
- `promos.merchandise_payouts` = [10000, 25000, 50000, 100000] and `promos.merchandise_chances` = [0.8, 0.7, 0.5, 0.25] (replacing `merchandise_tiers`)
- `promos.interference_payout` (100000 / −25000)
- Call-out entries in `amounts`, `success_rates` and `fatigue_bars` (0)
- Remove `weekly_show_income` and set `attendance.drives_income` to true.

**`LedgerCategory` enum**

- Add `ShowProduction`, `Salary` (replaces the `ContractUpkeep` label: "Superstar salaries"), `MedicalRehab`, `ReleaseFee`, `AttendanceBonus` and `InterferenceResult`.
- Rename the gate line's label to "Fan attendance gate".
- `SigningFee` stays for old saves only.
- Run `php artisan gm:types`.

**Migration (schema)**

- `ledger_entries.show_id` (nullable FK): the night a line belongs to, for grouping.
- `wrestlers.monthly_salary` (unsigned int).
- `rivalries.brand` (nullable string; see section 6).
- Then run `migrate:fresh --seed`.

**`LedgerService::post`**

- Accept an optional `show` and store `show_id`.
- Match costs (`ShowResolver.php:288`) and promo costs/income (`PromoService::resolveOne`) pass their show.

**`ContractPricing`**

- Quote from `$wrestler->monthly_salary × months`, giving `{total, weekly}`.
- `ContractService::createContract` posts no signing fee and checks affordability (`GameRuleException`).
- `signDrafted` and `signWweRosters` take a length. WWE roster uses `ContractLength::TwentyFour`.

**Draft**

- `DraftService::pick`/`randomPick` take a length; the controller validates it against the `ContractLength` enum.
- `randomPick`'s affordability filter uses the total.

**Release**

- `ContractService::release` posts `ReleaseFee` (−`remainingValue()`), but only for a voluntary release. Expiry, Loser Leaves and defection skip it via a flag.
- `ContractController::release` returns it.
- `ReleaseButton` shows "Releasing costs $X".

**Show production**

- In `ShowResolver::close` (once per night: the last card of a PPV night, like `AttendanceService::tally`), post `ShowProduction` for each brand on the night.

**`PayShowIncome`**

- Gates only. Then the attendance bonus, computed from `AttendanceNights::forWeek` crowds summed per brand.

**New week step `PayMedicalCosts`**

- Placed after `TickContracts`.
- One line per brand per week: "Medical rehab (2 minor, 1 serious)".
- Reads active injuries via the existing injury model.

**`PromoService::resolveOne`**

- Advertising pays in the same week.
- New Merchandise roll, on streams `promo.merch.chance.*` and `promo.merch.payout.*`.
- Interference payout and penalty.
- Call-out branch (section 3).

**`AiRosterManager`**

- Affordability is `budget − total ≥ cash_reserve`.
- `AiPromoPlanner`'s low-cash logic still prefers Advertising (now $200k, sure).

**`WeekResolver` step order**

- `PayShowIncome` moves before `TrackDeficit`.

## 2. Financials page (option B + C)

**`FinancialReport::forWeek`** returns `nights`: each aired show night of the brand that week (name, PPV flag, crowd), followed by a "Week-wide" group. Each group holds its lines (`category`, `label`, `memo`, `amount`). It also returns `income`, `expenses`, `net`, and `opening`/`closing` balance.

- Promos fold into one "Promos" line per night, with a breakdown memo, e.g. "Advertising +$200,000 · Slander −$50,000", net +$150,000.

**History** is unchanged, plus a `ppv` name per week so the trend can mark PPV weeks.

**Past financials for both brands (kept from today's page)**

- Every past week of either brand stays viewable.
- The URL carries both choices (`?brand=raw&week=12&season=1`). Switching brand keeps the week, and changing week keeps the brand, so you can compare Raw and Smackdown for the same week by flipping the toggle.
- Three ways to reach a past week:
    - The ‹ › stepper, back to week 1 of season 1.
    - Clicking a bar on the season net trend.
    - A **Season** switcher (latest first) when there's more than one season, as on the Reports chart.
- The rival's past statements stay read-only, as today.
- `FinancialsController` takes `season` alongside `brand`/`week`. `FinancialReport::history` already groups by season; the trend shows one season at a time.
- Old weeks recorded before this change (no `show_id`) fall into the Week-wide group with their original labels, so nothing disappears.

**`financials.tsx` redesign**

- Keep the Raw/Smackdown toggle (brand logos via `<BrandBug>`). It still defaults to the player brand.
- Week stepper (‹ Week 5 · Backlash + Smackdown ›), with "Latest" to jump back.
- KPI row: Balance, Net this week, Income, Costs.
- **Split bar:** income segments over cost segments, as a plain SVG/flex with labelled legend.
- **Itemized statement** grouped by night (gold night headers, PPV logo via `<PpvBug>`), then Week-wide (Salaries, Medical, Release fees, Attendance bonus).
- **Season net trend** (`components/gm/finance-trend.tsx`, plain SVG drawn like `attendance-chart.tsx`):
    - Net bars above and below zero; PPV weeks gold; the selected week outlined.
    - Click or arrow keys select a week. "Show as a table" fallback. Scrolls sideways on phones.
- Deficit panel unchanged.
- **Statement timing:** the current week's statement appears once the week has fully aired (the default stays the last resolved week). A half-aired PPV week isn't shown until its weekly show airs.

## 3. Call-out promo

**Enum and seed data**

- `PromoTypeKey::CallOut` ("Call-out").
- `PromoTypeSeeder` row with `needs_rival_pair` behaviour, plus a new `needs_alignment` flag.
- New `promo_slots.alignment` (face/heel), with an `Alignment` enum (Face, Heel).

**`PromoEligibility`**

- `call_out`: the brand's active rivalries, as `pairs`. Unavailable with "No active rivalry".

**`PromoService::book`**

- Validates the rivalry (`rivalries->between`), the chosen rival (`target_wrestler_id` must be one of the pair, `target_wrestler_b_id` = the other) and the alignment.
- One Call-out per wrestler per card (existing rule).

**`resolveOne`**

- On success, `RivalryService::recordCallOut` adds 10 heat (`gm.rivalries.heat_per_call_out`) and marks the week held (reuses `hold`).

**Frontend (`promo-slots.tsx`)**

- `RivalryPicker`.
- A two-option radio of the rivalry's superstars.
- Face/Heel radio tiles.
- The payload adds `alignment`.

**News**

- `NewsEventType::CallOutFace` / `CallOutHeel` in `NewsCatalog` (Promos group).
- `PromoStories` keys `call_out_face.success|failed` and `call_out_heel.success|failed`, at least 4 variants each, 1–2 paragraphs of 6–7 sentences (story-text rule).
- `PromoStoryWriter` case.

**Email**

- `EmailTemplate::CallOutFace` / `CallOutHeel`.
- `EmailService::callOut()` via `fromSuperstar($wrestler)`, to that brand only.
- `EmailTexts` keys `call_out.face.success|failure` and `call_out.heel.success|failure`, at least 3 variants each.
- `DocsController::emails()` rows.

**Rival GM**

- `AiPromoPlanner` gets a `call_out` weight in its situational list _(assumption: same weight as Continue Rivalry)_, with face/heel at 50/50.

## 4. Preset/Randomize book rivalries (`CardRandomizer`)

**Rivalry candidates**

- Active rivalries with both rivals in the pool, not already on the card, and fitting the 70% fatigue rule.

**Preset**

- After title obligations, book up to `gm.card.rivalry_target` = 3 rivalries into open slots, in random slot order.
- Each one uses a shape drawn by weight (`gm.card.rivalry_shapes`):
    1. **1v1**: any eligible singles type, e.g. Hardcore.
    2. **Triple Threat**: both rivals plus a random third.
    3. **Partners**: the rivals as a 2v2 team against a random team (`teams()`, else random).
    4. **Manager**: one rival wrestles a random superstar in a 1 vs 1 w/ Manager variant, with the other rival managing that opponent.
    5. **Two rivalries**: two rivalries in a 6-Man Battle Royal plus 2 random.
- A shape that can't fit (types, fatigue, gender for 2v2 partners) falls back to the next shape, then to 1v1.
- Fewer than 3 rivalries available books what exists.
- Rival pairs are same-gender. Random fillers follow the existing fill rules.

**Randomize (one slot)**

- If an unbooked rivalry is available, there's a 50% chance (`gm.card.randomize_rivalry_chance` _(assumption)_) to use a rivalry shape that fits the slot's chosen type; otherwise random as today.

**Simulate to here**

- Uses Preset, so it inherits this behaviour.
- The AI keeps `AiBooker`'s own rivalry logic.

## 5. Drag to reorder match slots (`/shows/{id}`)

**Library**

- `@dnd-kit/core` + `@dnd-kit/sortable`: keyboard sensor, screen-reader announcements, touch support.

**UI**

- A grip handle on each slot header (44px touch target).
- Plus "Move up" / "Move down" in a small slot menu for keyboard and touch users.
- Drop placeholder and outline in the brand colour.
- Respects `prefers-reduced-motion`.
- The Main Event label follows whichever slot is last, as today.

**Rules**

- Only the player's own, non-fixed slots move: no MITB cash-in, interbrand owner slots, PPV fixed slots or WrestleMania fixed slots. Fixed slots render without a handle and act as walls.
- Dragging is disabled, with the reason shown, while any slot has unsaved changes or the card is confirmed.
- Promos keep their spot number (`before_position`), so "before the main event" stays before the last match.

**Backend**

- `PUT shows/{show}/slots-order` (`CardController::reorder`, `assertEditable`) with the new position order.
- `CardService::reorder` validates that the set is the same and fixed slots are unmoved.
- Positions are rewritten in one transaction, using a temporary offset to dodge the unique index, as `MitbService::makeRoom` does.
- Returns 204 to fetch callers.
- Run `wayfinder:generate --with-form`.

## 5b. Superstar profile (`/rosters/{saveWrestler}`) follows the new contracts

How it's populated today:

- `RosterController::show` builds `wrestler` from `WrestlerPresenter::row`: name, roster type, weight class, morale, fatigue, W–L, streaks, specialties, injury, active contract (weeks left, weekly cost) and titles.
- The rest of the page: `complaints`, `title_history` (`TitleReign`), `contracts` (contract history: length, weekly, upfront, signed week, status) and `quotes` (`ContractPricing::allQuotes`, which today is flat by length × 1.5 for Legends).
- [rosters/show.tsx](resources/js/pages/gm/rosters/show.tsx) renders Status, Contract (5 length buttons "5w…52w", Up front / Per week / Total, Sign / Re-sign / Release), Title history and Contract history.

Changes:

- `WrestlerPresenter::row` adds `monthly_salary` and a `salary_tier` (Superstar / Legend / Diva).
- The Status panel shows "Salary tier · $20,000 a month".
- **Contract panel:**
    - The length buttons read "1 mo / 3 mo / 6 mo / 9 mo / 12 mo" (from `ContractLength::label()`).
    - The quote shows **Per week** and **Contract total** only (no Up front).
    - With an active contract, a **Release fee** line appears (weekly × weeks left, the same figure as `ReleaseButton`'s confirmation).
    - Sign / Re-sign are disabled with a visible reason when the total exceeds the budget.
- **Contract history:**
    - Rows read "12 months · signed W5 · $4,615/wk · Active".
    - A released contract shows its release fee.
    - Old contracts with an `upfront_fee` keep showing it ("+$100,000 up front").
- `quotes` come from the new `ContractPricing` (per wrestler). The free agency page and the draft room use the same quotes.

## 5c. Superstar profile: Injury history and Special info

**Injury history**

- Remove the Injury field from the Status card.
- Add an **Injury history** panel beside Title and Contract history, with columns **Week**, **Type** and **Name**:
    - Week: "S1 · W14", plus the PPV name if any.
    - Type: Minor/Serious chip. An escalated injury is marked "(from minor)".
    - Name: the injury label.
- Newest first. A still-active injury shows "out N weeks" in the Week cell, so the current injury stays visible.
- Data: `injuries` (`started_week`, `severity`, `injury_type_id`, `escalated_from_id`, `healed_week`) for that `save_wrestler_id`.
- Empty state: "No injuries yet."

**Special info**

- A panel listing career moments, newest first. Each links to its results where one exists.
    - **Money in the Bank:** won (season, week), then cashed in (week and result: won or lost the title), expired, or voided. From `money_in_the_bank` (`won_week`, `status`, `cash_in_match_id`, `resolved_week`).
    - **Survivor Series:** "Team Raw / Team Smackdown in the Traditional 3 vs 3 Elimination, S1 · W33, won/lost". From match participants of slots whose match type is the Survivor Series elimination.
    - **WrestleMania interbrand series:** each match they were in (1v1, 2v2 or Money in the Bank) and its result. From `interbrand_series.match_ids`.
    - **Re-draft tags:** "Protected by the immunity tag, S1 · W52" (`interbrand_series.exemption_wrestler_id`) and "Taken with the manual draft pick" (`handpick_wrestler_id`), plus re-draft picks from `draft_picks` (phase re-draft, with source and pick type).
- Built in a new `SuperstarCareer` service (`app/Services/Roster/SuperstarCareer.php`), passed as `injury_history` and `special_info` props from `RosterController::show`.
- Empty state: "Nothing special yet."

## 5d. News: story cards start collapsed, shorter headlines

**Today**

- Story cards (the ones below each headline) clamp the summary to **2 lines** with CSS `line-clamp-2`. It's line-based, not a character count.
- Clicking anywhere on the card toggles it (`news-grid.tsx:244-251`).

**Story cards**

- The summary starts **hidden**.
- The header shows a chevron button ("Show story" / "Hide story", `aria-expanded`, rotates on open, 44px touch target) that reveals the full summary.
- Headline cards are unchanged.
- Update `news-grid.test.tsx`.

**Short headlines**

- Rewrite every headline template as a short summarized headline:
    - `NewsTemplates` (≈100 keys)
    - `PromoStories` headlines
    - `FallbackStories` headlines
    - Conditional-story titles stay as they are.
- Rule: **at most 6 words of template text, ideally under 40 characters** (e.g. "New record for Cruiserweight title!", "New champion crowned!", "Cena retains the WWE title"). One wrestler name token is allowed where it's the point.
- The detail the headline used to carry (who beat whom, via what) moves into the first sentence of the summary, which still follows the story-text rule.
- Enforced by a new test in `tests/Feature/Text/` that counts template words.
- CLAUDE.md's wording rules for title changes and match results move from the headline to the summary, and the test fixtures update with them.
- **Legacy keys:** the `*.legacy` templates keep their old wording. A fresh DB has no old news anyway.

## 5e. Diva Bikini Contest continues a Diva rivalry

- If the two Divas in a Bikini Contest are rivals, it also works like Continue Rivalry for them: `RivalryService::hold`.
- A week where they don't share a match keeps the heat instead of losing 20.
- A week where they do share a match already gains heat, so the hold changes nothing.
- It holds on the promo's success, which is always, at 100%.
- Bikini Contest pickers mark rival pairs with a "Rivalry: holds heat" note.
- The Rivalry page rules and `/gm/docs` (rivalry promos) say so.

## 5f. Randomizer gender balance (Preset, Randomize, Simulate to here)

Reuse the rival GM's `IntergenderRules` and add the following.

**Equal sides**

- Every side gets the same number of Divas, and a Diva never faces only men on her own:
    - 1v1, Triple Threat, Fatal 4/5-Way, Six-Pack, Battle Royal and Gauntlet: all Divas or all men. If the first pick is a Diva, every opponent is a Diva.
    - 2v2 and 2v2v2: if one team has a Diva, every team has the same number.
    - 3v3: the same Diva count per team, per `IntergenderRules`.
- A shape that can't be filled that way is re-rolled: a new type and new picks, up to N tries (`gm.card.gender_rerolls`). After that, all men.

**All-Diva match**

- With 2+ bookable Divas, Preset books at least one all-Diva match per card, on a random slot (the AI's `bookDivaMatch` rule).

**Diva managers**

- In w/ Manager and w/ Manager vs. w/ Manager matches, managers are drawn from Divas first (any gender of competitor), falling back to anyone left.

**Rivalry shapes (section 4) follow the same balance**

- e.g. the Partners shape needs the opposing team to match the rivals' Diva count.

Tests: each shape's balance, re-roll, the all-Diva guarantee, and Diva managers.

## 5g. Demo save at season 2 week 1

There's no seeding command for an advanced save today; only `gm:types` exists. Add a dev command, `php artisan gm:demo-save {--brand=smackdown} {--season=2} {--week=1}`, that does the following:

1. New game with Use WWE roster.
2. Auto-assign titles.
3. Start the season.
4. Run `WeekSimulator` to WrestleMania.
5. Air WrestleMania all-simulated.
6. Auto-run the re-draft: the player's manual pick and immunity are chosen like the rival's (`ReDraftService::mostValuable`).
7. Advance to the target week.
8. Set `saved_at` so it appears under Saved careers.

**Reusable after every reset**

- The command is permanent, so it can be run any time after `migrate:fresh --seed`.
- A `composer demo` script runs both in one go: `migrate:fresh --seed`, then `gm:demo-save`.
- It uses a fixed RNG seed by default (`--seed=` to vary it), so every run builds the same season-2 career.
- It isn't wired into `DatabaseSeeder`: a full simulated season takes a while, and tests and plain resets shouldn't pay for it.
- Documented under CLAUDE.md's Commands.

When the work is done:

- Run `php artisan migrate:fresh --seed`, then `php artisan gm:demo-save`.
- Open a few profiles in the preview: a MITB winner, a Survivor Series member, an interbrand series entrant, a re-draft pick, and someone with injuries. Screenshot them.
- Note: `migrate:fresh` wipes existing saves (fine per CLAUDE.md).

## 5h. Follow-up additions

**Smooth disclosures**

- Every show/hide toggle added in this plan animates. That covers news story cards, the Financials statement groups, and the profile panels' filters if collapsible.
- Use a shared `<Disclosure>` (`components/gm/disclosure.tsx`):
    - The height animates with the CSS grid `grid-template-rows: 0fr → 1fr` transition (about 220ms, ease-out), with opacity alongside.
    - The chevron rotates 180°.
    - Content stays in the DOM, `inert` while closed.
    - Under `prefers-reduced-motion` it switches instantly.
- No JS height measuring, so no layout jank.

**Injury history extras**

- Columns: **Week**, **Type**, **Name** and **Ended**.
- Ended is the `healed_week` as "S1 · W18". An escalated minor shows "Became serious W16". Still active shows "Out, N weeks left".
- A segmented filter above the table: **All** (default) / **Minor** / **Serious**, with counts ("Minor 3"), client-side.

**MITB cash-in victim**

- The Money in the Bank entry in Special info also names **the champion it was cashed in on**, and the title: "Cashed in on Batista for the World Heavyweight Championship, S1 · W30 · won".
- Read from the cash-in match's participants (`cash_in_match_id`): the champion(s) going in for that title.

**Smackdown default roster**

- In `database/seeders/data/wrestlers.php`, Stacy Keibler moves from `$freeAgentDivas` to `$smackdownDivas`, and Sylvain Grenier moves from `$smackdown` to `$freeAgents` (house rule; still 30 per brand).
- Update `docs/20261024-2005-roster.md` and CLAUDE.md's roster note.
- Check the faction seeds (Stacy is in The Super Heroes and the Dudley Boyz; Sylvain is in La Résistance). They stay as they are, since factions span brands.

**CLAUDE.md TODO: ECW brand (deferred, not built)**

- Add a TODOS entry for a third brand, ECW, with 4 titles:
    - World: ECW World Championship
    - Secondary: ECW TV Championship
    - Tag: ECW World Tag Team Championship
    - Special: ECW Hardcore Championship
- The Hardcore Championship can only be on the line in gimmick matches (a Normal Triple Threat can't take it; a Hardcore, Table or Ladder match can).

## 6. Rivalries page Brand column

- `rivalries.brand` is set in `RivalryService::start` from the rivals' brand (wrestler A's brand).
- `RivalryController` passes it to the page.
- `rivalries.tsx` adds a "Brand" `<th>` before "Rivalry", with `<BrandBug brand=… />` in each row ("—" if null).

## 7. Docs, rules and tests

- **CLAUDE.md**: rewrite "Contracts and money" (salaries, release fee, show costs, medical, gate, bonus, interference money) and the Advertising/Merchandise/Call-out promo rules; add rivalry booking under Randomize/Preset; add reordering under Card building; add the Rivalries brand column.
- **`/gm/docs`**: a Finances panel, promo table rows (Advertising, Merch, Interference, Call-out), contract lengths and salaries, and the reorder note.
- **Pest tests (new or updated)**:
    - `ContractPricing` per class and length
    - Draft pick with a length
    - WWE roster signs at 24 weeks
    - Release fee
    - Show production once per night
    - Medical weekly
    - Gate plus bonus (and tie)
    - Advertising pays the same week
    - Merch roll
    - Interference ±
    - Call-out booking validation, heat and hold, news and email
    - Preset books up to 3 rivalries and each shape
    - Reorder endpoint (fixed slots refused, unique swap)
    - `FinancialReport` nights grouping
    - `PayShowIncome` before `TrackDeficit`
    - Rivalry brand stored
- **Vitest**: financials page (night groups, trend selection), draft length dropdown, promo editor Call-out inputs, slot reorder (keyboard move), rivalries Brand column.
- The story-text rule test covers the new texts. The `NewsCatalog` test covers the new events.

## Verification

- `php artisan migrate:fresh --seed`, `php artisan gm:types`, `php artisan wayfinder:generate --with-form`
- `vendor/bin/pest`, `npm run test`, then `composer ci:check`
- Preview (`preview_start`):
    - New game with a manual draft: pick lengths and check the running budget.
    - Use WWE roster: check that contracts are 6 months.
    - Book a card with an Advertising and a Call-out promo, drag Match 2 below Match 5, and Preset (check the rivalry bookings).
    - Air a PPV week.
    - Open Financials: check that the Backlash and weekly show nights are grouped, plus salaries, medical, gate and bonus; switch Raw/Smackdown; select a week from the trend.
    - Rivalries page: check the logos.
    - Screenshot desktop and mobile.
