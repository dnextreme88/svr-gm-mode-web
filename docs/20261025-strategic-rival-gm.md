# Strategic rival GM

Branch: `feature/20261025-strategic-rival-gm` (off `main`). Status: built (2026-10-25). See **As built** at the end for where it differs from the design.

## Context

A review of the rival GM (`app/Services/Ai/AiGm.php`) found it is almost entirely random:

- It books with Preset (`CardRandomizer::preset`), ignores fatigue, and mixes men and Divas freely. It books w/Manager types with no manager (managers are optional).
- Title matches get a flat 35% roll per slot (`gm.card.randomize_title_chance`), filled first slot to last. Titles get used up early, so the main event is the least likely place for a title.
- It always books Advertising (promo 1) and Merchandise Sponsorship (promo 2), and nothing else.
- It answers trades with a 50% coin flip (`gm.trades.ai_accept_chance`, in `TradeService::propose`).
- It never cashes in Money in the Bank.
- **It never signs, renews or releases anyone.** Every draft pick signs 52 weeks, so its whole roster likely expires at the end of season 1 (`TickContracts` → `ContractService::tick` → `release`). This is the most serious finding. The first test should confirm it.
- Other random parts: the draft (60+ on a 1–100 roll picks from its focus class: Raw Divas, SmackDown Cruiserweights and Light Heavyweights), title assignment (`ChampionshipAssignment::autoAssign`), Rumble and Survivor Series entrants (`PpvCardService::randomEntrants`), and its re-draft tags (`ReDraftService::aiTags`). In a long deficit it auto-picks cheap matches (`TrackDeficit`).

The house rules for the new behaviour:

- **Matches:** roll per match. **85% careful:** nobody may end the match above 70%. **15% aggressive:** prefer high-fatigue gimmick types and allow ending up to **110%**, so injuries, including serious ones, can happen.
- **Gender:** each match rolls **95/5** for intergender. At least 1 all-Diva match per card when the brand has 2 or more bookable Divas. Royal Rumble entrants are never Divas.
- **Interference:** every 4 weeks.
- **Slander:** after each one, roll the gap to the next: **3 weeks 10%** (it backfires; that's fine), **5 weeks 30%**, **6 weeks 60%**.
- **PPV Hype:** only for its own brand-only PPVs (not shared ones), **1 or 2 weeks before** (50/50, rolled once per PPV).
- **Rivalries:** at most **7** on its brand. When it drops below 7 it waits **2 weeks**, then starts new ones until it's back at 7. At **0** it doesn't wait: it creates **at least 4** that week.
- **Promo priority:** at or below the $500,000 low-cash mark, money promos come first. Otherwise PPV Hype, then Interference, Slander, Start a Rivalry and the other promos, with money promos filling whatever is left. It can use all 14 promos.

## Design

Split the AI into focused classes under `app/Services/Ai/`. `AiGm` stays the entry point (`bookShow`, `draftPick`) and delegates. The player's Randomize and Preset stay exactly as they are. Every roll uses the seeded RNG with stable stream keys (`ai.<thing>.{show->streamKey()}` or a week). Every number goes in a new `config('gm.ai')` block, marked _(assumption)_ where it's not a user-given number.

```php
'ai' => [
  'booking'    => ['intergender_chance' => 0.05, 'min_diva_matches' => 1, 'careful_chance' => 0.85, 'careful_max_fatigue' => 70, 'aggressive_max_fatigue' => 110, 'aggressive_min_bars' => 3],
  'titles'     => ['main_event_chance' => ['weekly' => 0.6, 'ppv' => 0.9], 'other_slot_chance' => 0.35, 'champion_vs_contender_chance' => 0.1],
  'promos'     => ['interference_every_weeks' => 4, 'slander_gap_weeks' => [3 => 0.10, 5 => 0.30, 6 => 0.60], 'ppv_hype_weeks_before' => [1 => 0.5, 2 => 0.5], 'title_hype_weights' => ['world' => 60, 'second' => 30, 'other' => 10], 'situational_weights' => [/* one per promo key, *assumption* */]],
  'rivalries'  => ['max_per_brand' => 7, 'refill_wait_weeks' => 2, 'reset_minimum' => 4, 'rivalry_match_chance' => 0.5],
  'roster'     => ['renew_at_weeks_left' => 4, 'renew_length' => 52, 'target_size' => 28, 'cash_reserve' => 500_000],
  'trades'     => ['legend_value' => 2, 'champion_history_value' => 1, 'focus_value' => 1, 'fair_ratio' => 1.0, 'fair_accept' => 0.9, 'unfair_accept' => 0.05],
  'mitb'       => ['cash_in_champion_fatigue' => 60, 'cash_in_weeks_left' => 4],
  'draft'      => ['max_focus_picks' => 12],
],
```

The low-cash mark reuses `gm.emails.low_cash_threshold`.

### 1. Fatigue-aware booking: new `AiBooker`

- A wrestler qualifies for a slot when `fatigue + type->fatigueCost() <= limit`. Reuse `CardRandomizer::pool`, `eligibleTypes`, `poolFor` and `bookedIds`. If `proposeSlot` and `fillSides` get a limit, it must be optional (default `null` = today's behaviour) so the player's Preset is unchanged.
- For each slot, roll careful (0.85) or aggressive:
    - **Careful:** limit 70. The type is picked only from types that enough wrestlers can fill under the limit.
    - **Aggressive:** limit 110, and it prefers types with `fatigue_cost_bars >= aggressive_min_bars`, falling back to any type it can fill.
    - If a slot can't be filled under its limit, it stays empty instead of breaking the rule. Unfilled AI slots are fine today.
- Wrestlers booked earlier on the card are excluded from later slots (as Preset's running `taken` list does). Tiring promos count the card's planned fatigue (see 3).

### 1b. Gender-separated booking (part of `AiBooker`)

A **Diva** is `RosterType::Diva` (`Wrestler::isDiva()`). It's the roster type, not a weight class.

- **Each match rolls 95/5** (`intergender_chance` = 0.05).
- **At least 1 all-Diva match per card**, Raw or SmackDown (`min_diva_matches` = 1), when the brand has **2 or more bookable Divas**. It's **planned** first so the Divas aren't used up elsewhere, but it goes on a **random slot** (any position, main event included, stream `ai.divaslot.{streamKey}`). If it's a **Women's Championship** match, it goes on **match 5 or the main event** instead (50/50; on a 5-slot PPV, the last two slots). That slot is taken before the main-event title plan in section 2, so the two don't fight over the main event. With 0 or 1 bookable Divas there's no Diva match and men fill the card.
- **95%, single-gender:** the other slots are all male. Types where `divas_allowed` is false or `divas_only` is true still apply. w/Manager types now get managers of the same gender as the competitors.
- **5%, intergender:** only these shapes, using the Divas left after the Diva match. A shape that needs more Divas than are free is skipped. With a single Diva only shapes 2 and 6 (one Diva manager) are possible. If no shape fits, the slot falls back to single-gender.
    1. **1 w/Manager vs 1 w/Manager** (any family: Single, Hardcore, Iron Man, Ladder, Last Man Standing…): male competitors, both managers Divas. _Rob Van Dam w/ Torrie Wilson vs. Triple H w/ Trish Stratus._
    2. **1 vs 1 w/Manager:** male competitors, the one manager a Diva. _Triple H w/ Lita vs. Shawn Michaels._
    3. **2v2** (tag family and other 2v2 types): each team is exactly 1 man + 1 Diva. _Edge, Lita vs. Matt Hardy, Torrie Wilson._ Never a 2-man team against a mixed team (_Edge, Christian vs. Matt Hardy, Torrie Wilson_ is refused).
    4. **3v3**, except Table Tornado 6-Man Tag Elimination: every team has the same number of Divas, at least 1, and not all Divas. _Bubba Ray Dudley, Spike Dudley, Stacy Keibler vs. Triple H, Shawn Michaels, Trish Stratus._
    5. **2 w/Manager vs 2 w/Manager:** male teams, both managers Divas. _Spike Dudley, William Regal w/ Trish Stratus vs. Edge, Christian w/ Lita._
    6. **2 vs 2 w/Manager:** male teams, the one manager a Diva. _Tajiri, Eugene w/ Melina vs. Matt Hardy, Jeff Hardy._
    7. **2v2v2** (Triple Threat Tornado Tag): every team is exactly 1 man + 1 Diva. _Edge, Lita vs. Christian, Torrie Wilson vs. Triple H, Trish Stratus._

    Singles, Triple Threat, Fatal 4-Way, handicap, battle royal and every other type never go intergender. The rule lives in one `IntergenderRules` helper with a unit test per shape, including the refused mismatch.

- **Rivalry pairs**, both the ones the AI starts and the rivalry matches it books, are always the same gender.
- **Royal Rumble entrants are never Divas** (a new rule for both brands). Today `PpvCardService::randomEntrants` drops Divas only for Survivor Series. Fix:
    - `randomEntrants` rejects Divas for both PPVs. This covers the AI and Simulate to here.
    - `setEntrants` refuses a Diva in the Rumble, so the player can't pick one either.
    - The Rumble entrant picker greys Divas out.
    - Add the rule to CLAUDE.md, and ask about `/gm/docs`.
    - **An all-Diva Royal Rumble is deferred** (there aren't 30 Divas). Add it to the TODOS in CLAUDE.md.

### 2. Title priority (part of `AiBooker`)

- The **main event first** (the last slot, or the PPV's last open slot). It rolls `main_event_chance` (weekly 0.6, PPV 0.9) for a title match, trying titles in tier order (world, second tier, tag, special). Reuse `TitleMatchPlanner::plan`, with a new optional preferred order / forced argument instead of its random pick.
- The other slots keep the 35% roll on whatever titles are left.
- The fatigue limit applies to the champion too: a careful slot won't put a worn-out champion on the line. It tries the next title instead.
- Earned title shots (`TitleMatchPlanner::obligations`) still go first.
- **Champion vs Contender:** a non-title 1v1 slot with a champion in it has a `champion_vs_contender_chance` of 0.1 to get that stipulation, booked through the normal `CardService::book` validation.

### 3. Strategic promos: new `AiPromoPlanner`

Replaces `AiGm::bookPromos`. **It runs after the matches are booked** (today promos go first), so it knows which title matches and wrestlers are on the card. It can use **all 14 promos**. It fills the 2 slots from this priority list, checking each candidate against `PromoEligibility::forShow` / `forPromo`:

1. **Low cash** (budget at or below the low-cash mark): Advertising, then Merchandise.
2. **PPV Hype** in the week rolled for its next brand-only PPV (stream `ai.ppvhype.{season}.{ppv_key}` picks 1 or 2 weeks out).
3. **Interference**, when 4 or more weeks have passed since its last one (from `promo_slots` history).
4. **Slander**, when the rolled gap has passed since its last Slander. The gap is rolled from `ai.slander.{lastWeek}`, so it stays deterministic without a new column. The first Slander uses the same roll counted from week 0.
5. **Start a Rivalry**, when the rivalry plan wants one (see 4).
6. **Situational promos:** a weighted random pick (`situational_weights`) from whichever of these fit this week:
    - **Title Match Hype**, when the card has a title match. It picks the title by weight: world 60%, second tier 30%, the rest 10% (`title_hype_weights`), from the title matches actually on the card.
    - **New Superstar**, for someone who joined recently (a signing, a trade or the re-draft).
    - **Comeback Reminder**, for someone seriously injured more than 3 weeks.
    - **Inactive Superstar**, for its most tired unbooked wrestler (or one heading toward an "unused" morale complaint).
    - **Continue Rivalry**, on its hottest rivalry.
    - **Main Event Hype**, when the main event is a title or rivalry match.
    - **Interview Space**, for an unbooked wrestler (a champion or someone on a streak first).
    - **Diva Bikini Contest**, with 2 healthy Divas who aren't in the Diva match where possible.
7. Anything left: Advertising, then Merchandise.

Wrestlers for tiring promos (`gm.promos.fatigue_bars`: Interference and Start a Rivalry 2 bars, Diva Bikini Contest 2, Continue Rivalry 1) must stay within the careful limit. Count the fatigue of their matches on this card, and prefer unbooked wrestlers. Placement keeps today's default spots (`PromoService::book` with `beforePosition` null).

AI Slander already makes the neutral news headline. Today `EmailService::slanderResult` goes to the AI's own inbox, which nobody reads. **Proposed:** when the AI slanders, JR/Tazz email the player instead ("SmackDown ran a smear campaign against us"). It's a new email, so add its row to `DocsController::emails()`.

### 4. Rivalries: new `AiRivalryPlanner`

- Counts the active rivalries where both wrestlers are on its brand (`RivalryService::active`).
- **Below 7:** if 2 or more weeks have passed since the brand dropped below 7 (the latest `ended_week` of its rivalries; none ended yet counts as waited), it starts one this week through Start a Rivalry, one a week until it's back at 7.
- **At 0:** no wait. It makes at least 4 that week: up to 2 through Start a Rivalry promos, and the rest by booking fresh 1v1 same-gender pairs against each other and starting each rivalry through `RivalryService::start` at booking. _(Assumption: starting at booking needs no schema change.)_ Low cash can take the promo slots, so the matches must cover the shortfall to reach 4.
- **Rivalry matches:** each active rivalry has a `rivalry_match_chance` of 0.5 to get its pair booked in a 1v1, subject to the fatigue limit. `recordMeeting` already heats it. The cap only stops the AI starting rivalries on purpose; rivalries that form on their own from repeat meetings are left alone.

### 5. Roster upkeep: new `AiRosterManager` and a `RivalRosterUpkeep` week step

- Runs after `TickContracts`, before `ApplyPendingMoves`, in `WeekResolver::STEPS`.
- Renews (`ContractService::renegotiate`, 52 weeks) every AI contract with 4 or fewer weeks left, when it can afford it above `cash_reserve`. Champions and its focus class go first.
- Signs free agents (`ContractService::signFreeAgent`, which takes effect next week as usual) until the roster reaches `target_size` (28) or the money runs out. It prefers wrestlers who keep every title fillable, then its focus class, then anyone. `signFreeAgent` already refuses anyone locked out by Loser Leaves.
- Contract emails stay player-only, as today.

### 6. Trades: new `AiTradeJudge`, called from `TradeService::propose`

- Each wrestler is worth 1, plus `legend_value` for a Legend, plus `champion_history_value` for a former champion (from the title records), plus `focus_value` for the AI's focus class.
- It accepts with `fair_accept` (0.9) when what it gets is worth at least `fair_ratio` × what it gives and the trade wouldn't leave it unable to fill a title. Otherwise it accepts with `unfair_accept` (0.05).
- _(Assumption: there's no Popularity yet, so this is the whole value model.)_ The emails don't change.

### 7. Money in the Bank: part of `AiBooker`

When the AI holds a briefcase, it calls `MitbService::cashIn` on its weekly show or PPV if either:

- its brand's world champion goes in at or above 60% fatigue, or
- the briefcase expires within 4 weeks (`MoneyInTheBank::expires_week`).

Once per card. `cashIn` appends its own slot after the others.

### 8. Draft balance: `AiGm::draftPick`

- The focus roll stays as it is.
- It stops chasing its focus class after `max_focus_picks` (12).
- Its last picks first fill any title that has no eligible holder yet (e.g. no Diva for the Women's title).

## Code notes (read during planning)

- `AiGm::bookShow` is called from `PrepareShows` for every rival card, including its 3 WrestleMania slots (`bookShow($show, $aiBrand)`; the WrestleMania card has `brand = null` and slots carry `owner_brand`). `WeekSimulator` also calls it for the player's cards during Simulate to here, after `bookRandomPromos`. The new strategy must apply only to the rival brand, so the simulator either keeps today's Preset path or gets an explicit flag.
- Slots: `CardService::ensureSlots`, `book($show, $position, Booking, $brand)` (validates through `CardValidator`; managers optional; a manager can't also compete). `Booking` holds `matchType`, `sides`, `managers` (side index => id), `titleChampionshipId`, `stipulation`, `refereeId`.
- `MatchType`: `sides`, `manager_sides`, `fatigue_cost_bars` / `fatigueCost()`, `divas_only`, `divas_allowed`, `titles_allowed`, `isOneOnOne()`, `isTwoOnTwo()`, `isTagStyle()`, `hasSpecialReferee()`. Variants are in `database/seeders/data/match_types.php` (e.g. `single` "No Manager vs. w/Manager", "w/Manager vs. w/Manager"; `tag_team` "Tag vs Tag w/Manager", "Tag w/Manager vs Tag w/Manager"; `six_man_tag` "Table Tornado 6-Man Tag Elimination"; Triple Threat Tornado Tag in hardcore and ladder).
- `SaveWrestler`: `fatigue` (0–100), `win_streak`, `loss_streak`, `weeks_unused`, `weeks_since_title_match`, `joined_brand_week`, `isSittingOut()`. `Championship`: `is_world`, `is_tag`, `sort_order`, `eligibility`, `allowsWrestler()`. `PpvEvent::isShared()` / `runsOn()`. `Show::streamKey()`, `isPpv()`, `matchSlotCount()`, `promoSlotCount()`.
- RNG (`App\Support\Rng\Rng`): `int`, `float`, `chance`, `pick`, `weightedPick(array $weights)`, `shuffle`, `sample`. Get streams with `$save->rng($key, $week)`.
- `PromoService::book($show, $position, $type, $targetId, $targetBId, $championshipId, $beforePosition)` checks eligibility, the one-promo-per-spot rule and the one-of-each-promo-per-wrestler rule. `slanderOverused` looks at the previous 3 weeks (`slander_overuse_window_weeks` = 4), so a 3-week gap backfires as intended.
- `ContractService::signFreeAgent` checks Free Agency, the Loser Leaves lockout and roster room. `renegotiate` supersedes the old contract.
- Tests: `tests/Support/GameBuilder` (`inSeason(raw, smackdown, size, player, length)`, `wrestler`, `giveTitle`), `Card` (`show`, `book`, `type`, `singles`), `FixedOutcomes`. `tests/Feature/Card/CardRandomizerTest.php` covers Preset and must keep passing.

## Files

- New: `app/Services/Ai/{AiBooker,AiPromoPlanner,AiRivalryPlanner,AiRosterManager,AiTradeJudge,IntergenderRules}.php`, `app/Services/Week/Steps/RivalRosterUpkeep.php`.
- Changed: `AiGm.php`, `CardRandomizer.php` (optional fatigue limit), `TitleMatchPlanner.php` (preferred order), `PpvCardService.php` (no Divas in the Rumble), `TradeService.php`, `EmailService.php` (AI Slander heads-up), `WeekResolver.php` (new step), `config/gm.php`, `DocsController.php` (email row), the Rumble entrant picker on the frontend.
- Docs: CLAUDE.md gets a **Rival GM** rules section, the Rumble rule and the deferred all-Diva Rumble TODO, and its architecture note about `AiGm` is updated. Update this plan as work lands. **Ask before updating `/gm/docs`** (a Rival GM card, the Rumble rule and the new email row).

## Verification

Pest feature tests in `tests/Feature/Ai/` with a fixed seed:

- **Season-end regression (write first):** resolve 53 weeks. The rival roster stays at 26 or more and has renewed its contracts. Run it before the fix too, to confirm the bug.
- **Gender:**
    - With `intergender_chance` 0, every AI match is single-gender, managers included.
    - A card with 2+ bookable Divas always has an all-Diva match, and none with 0–1 Divas.
    - The Diva match's slot varies across seeds, and a Women's title match only ever lands on match 5 or the main event.
    - With 1 Diva and chance 1, only shapes 2 and 6 appear.
    - With chance 1, every intergender match fits one of the 7 shapes, and the mismatched 2v2 never appears.
- **Rumble:** random entrants never include a Diva, and `setEntrants` with a Diva throws a `GameRuleException`.
- **Fatigue:** careful slots never book anyone whose fatigue plus the cost is above 70. Aggressive slots never go above 110. Force each mode with a config chance of 1/0.
- **Titles:** the main event gets the world title when the champion is fit, at a chance of 1. A tired champion is skipped on a careful slot.
- **Promos:**
    - Title Match Hype only when a title match is on the card, and it hypes one of those titles. New Superstar only for a recent arrival.
    - Interference 4 weeks apart. Slander gaps only from {3, 5, 6}. PPV Hype only 1–2 weeks before a brand-only PPV.
    - Low cash puts Advertising first.
- **Rivalries:** never starts one above 7; waits 2 weeks; at 0 it ends the week with 4 or more.
- **Trades, briefcase and draft:** a fair offer is accepted and a lopsided one declined (chances set to 1/0). MITB cash-in when the champion is tired. The draft respects `max_focus_picks`.
- Existing `CardRandomizerTest`, draft, trade and simulation tests still pass. Run `composer ci:check`.

## As built

- The season-end bug was real: before the fix the rival had **0** wrestlers after week 52.
- `RivalRosterUpkeep` runs **after `UpdateMorale`** (not straight after `TickContracts`), so the rival can refill what defections just took. It's still before `ApplyPendingMoves`.
- **Money:** with the promo priority as designed, money promos almost never air and the rival went broke before its week-52 renewals. Three additions, all _(assumption)_:
    - It counts as low on cash while its budget is below the renewal fees due within `gm.ai.roster.save_ahead_weeks` (30) + `renew_at_weeks_left`, plus the reserve (`AiRosterManager::needsCash`).
    - Only **one situational promo** a card; money promos take the other slot.
    - Renewals fall back to the longest shorter contract it can afford, and may dip into the $500,000 reserve (never below $0). Signings still keep the reserve.
    - Even so, 28 wrestlers cost about $11.6M a year against at most ~$16M of money promos, so the rival runs its budget down over seasons (TODO in CLAUDE.md).
- Interference, like Slander, counts its first gap from week 0.
- A Diva rivalry pair the plan wants booked doubles as the card's all-Diva match. A card can run two Start a Rivalry promos (with different wrestlers).
- The season-end test checks that no rival contract expires and the roster stays at 22+ (defections still move wrestlers both ways), rather than 26+.
- `GameBuilder::passiveRival()` switches off the rival's extras for tests about the player's side.
- The rival's Slander now emails the player (`EmailTemplate::RivalSlander`, "Rival Slander" row in `DocsController::emails()`).
