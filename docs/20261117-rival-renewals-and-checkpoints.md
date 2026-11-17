# Save career checkpoints, rival renewals, a bigger re-draft, Survivor Series 5 vs 5 and Tornado Tag Turmoil

## Context

**Save career** only flagged a career as saved (`saves.saved_at`); every action was written to the database as it happened, so nothing could be undone. It should work like a save slot in a video game: save, play on, and loading the career goes back to the save.

The rival GM re-signed every expiring contract for 12 months. It should judge each superstar on how they did during the contract, like a human GM.

The re-draft page opened with a **How the re-draft works** card; its text belongs in a modal, and the rival's use of its tags had no animation.

## Save career checkpoints (house rule, 2026-11-17)

- New table `save_checkpoints` (one per career, cascades with it): the checkpoint's week, stage and budget for the list, a fingerprint, and the payload (gzip-compressed JSON, base64).
- `CareerCheckpoint::capture` stores the `saves` row (minus id, name, saved_at and timestamps) and every row of every per-career table with their ids. `CareerCheckpoint::TABLES` lists them in foreign-key order: tables with a `save_id`, and child tables (`match_slots`, `match_participants`, `match_results`, `gauntlet_bouts`, `promo_slots`, `tag_team_members`, `trade_items`, `save_faction_members`) through their parent.
- `CareerCheckpoint::restore` deletes the career's rows children first and re-inserts the snapshot in one transaction, then clears `FactionNamer`.
- `hasChanges` compares a fingerprint (row count, highest id and latest `updated_at` per table, plus the `saves` row) with the one taken at the save.
- `POST saves/{save}/persist` saves (toast "Saved "{name}" (Season 1, week 12)."); new `POST saves/{save}/load` restores, then opens the career. Only loading from Saved careers rolls back.
- Saved careers lists each career as its save and flags progress since then; loading with progress asks first, then shows the loading overlay.
- Header: an always-present **Save career** button (Saving… → Saved with a check for 2 s → Save career) and "Last saved: S1 · wk 12" (`game.last_saved`, replacing `game.saved`).
- `composer demo` saves each demo career when it's built.

## Rival contract renewals (house rule, 2026-11-17)

`AiContractJudge` decides on the contract's final week (`gm.ai.roster.renewal`); the first rule that applies wins, and a re-sign is 6 or 3 months at even odds:

1. Champion: always re-signed (budget permitting).
2. Lost more than 50% of decided matches: let go.
3. Title wins during the contract at the threshold for its length (1, 3, 6/9, 12 months: 1, 2, 3, 4): re-signed 80%.
4. A morale complaint open more than 50% of the weeks _(assumption)_: let go 80%.
5. Booked in 80%+ of the weeks (serious-injury weeks not counted): re-signed 80%.
6. Booked in a third or fewer: let go 80%.
7. Otherwise: re-signed 80%.

The budget fallback to shorter deals, the champions-then-focus order, contract demands and 12-month free-agent signings are unchanged. `needsCash` now estimates renewals at 6 months.

## Re-draft page

- The rules card is a modal titled **Post-WrestleMania Season N draft**, opening with a welcome paragraph. It opens on its own once per re-draft (per browser), and a button of the same name reopens it.
- When the rival holds the immunity tag or the manual draft pick, a `SuperstarReel` in its colour reveals who it protected or took, then the list scrolls to them and highlights them. Once per browser; Skip animation skips it.

- The rules window is titled **Post-WrestleMania Season N draft** and opens with a welcome paragraph; the start button reads **Start the Post-WrestleMania Season N Draft**.
- Each brand's card names who its tags went to, separated by commas ("Immunity tag: Raw has chosen Edge, Kane, Carlito to be exempted from the draft").
- In Steps 1 and 2 the side you aren't choosing from (its card and roster) is at 50% opacity.

## Bigger re-draft (house rule, 2026-11-17)

- Each brand drafts **10** (`gm.ppv.redraft_picks_per_brand`), the immunity tag protects **3** (`redraft_immunity_count`) and the manual draft pick takes the holder's first **2** picks (`redraft_manual_picks`), fewer when fewer are eligible (`immunityNeeded`, `manualNeeded`).
- Migration: `interbrand_series.exemption_wrestler_ids` and `handpick_wrestler_ids` (JSON lists) replace the single-id columns.
- `ReDraftService`: `setImmunity` / `setHandpick` take exactly that many; `step()` gives the tag holder's nth pick its nth manual choice; the rival picks its most valuable ones in turn (`mostValuableMany`).
- Page: checkboxes (up to the count), "(n of 3 chosen)", the confirmations list every name, and the rival's reel plays once per superstar ("Immunity tag · 1 of 3").
- News, Vince's emails and the WrestleMania reminder now say three protected and two manual picks.

## Survivor Series 5 vs 5 (house rule, 2026-11-17)

The main event is now **Traditional 5 vs 5** (sides `[5, 5]`, `gm.ppv.survivor_series_team_size` 5): five entrants per brand on the entrants page, the rival picks five, and every text that said 3 vs 3 says 5 vs 5.

## Tornado Tag Turmoil (house rule, 2026-11-17)

- A new Gauntlet variant (107 types): 5 tag teams (sides `[2, 2, 2, 2, 2]`), 4 tag bouts, each bout's winning team facing the next team. Bouts are decided as tag matches; a tag bout is never a rivalry meeting. Flat 3 bars, record once, no titles.
- Its only stipulation is a #1 Contender's Match, for a tag title (`TitleBookingRules`, `lib/titles.ts`).
- On the card its 5 teams sit in one row (`sideColumns`), both pickers of each team labelled in gold by its order ("1st Participant" … "5th Participant").

## Salaries raised and contracts paid up front (house rule, 2026-11-17)

- Monthly salaries: superstars $40,000 (was $20,000), Divas $30,000 (was $12,000), Legends $75,000 (was $45,000). A male superstar's 1, 3, 6, 9 and 12 months cost $40,000, $120,000, $240,000, $360,000 and $480,000.
- Every contract is paid in full when it's signed (`ContractService::createContract`, a **Contract signing** ledger line, `contracts.upfront_fee`): the initial rosters in week 1, Free Agency, renewals and the rival's signings. The header's budget drops at once. No weekly salaries (the tick charges only an old contract with nothing paid up front).
- Releasing early refunds the unused weeks (`Contract::remainingValue`, a **Contract refund** line, replacing the release fee); renegotiating refunds the old contract's unused weeks and charges the new one; Loser Leaves Show refunds the same way.
- Match and promo money (prices, promo costs, payouts, Interference results) is recorded as it airs but reaches the budget once the night's news is in: when a PPV night ends (`WeekResolver`), and for the weekly shows in the new `SettleShowMoney` step right after the shows (`ledger_entries.settled`, `LedgerService::settle`).
- The draft's money left is the budget itself (`DraftService::availableBudget`). The Financials page drops "Salaries per week"; the profile and Release confirmation show the refund.

## No competitor limit (house rule, 2026-11-17)

The card's 6-competitor limit (`gm.card.max_competitors`) is gone, so Tornado Tag Turmoil (10) is bookable on weekly shows and PPVs like the other Gauntlets, and Randomize, Preset and the rival can draw it. The 30-man Royal Rumble and the Survivor Series 5 vs 5 stay out of ordinary slots (`gm.card.fixed_only_families`); they only fill their PPV's fixed main event.

## Rival Diva cap (house rule, 2026-11-17)

The rival GM never holds more than 9 Divas running Raw or 6 running Smackdown (`gm.ai.roster.max_divas`), in Free Agency signings and the initial draft. A demo season with the new renewals and prices left Raw with 18 Divas and 12 men, too few for its 15 Royal Rumble entrants.

The rival's free-agent signings fall back to shorter deals when money is tight (the longest it can afford while keeping its $500,000 reserve), so it can refill its roster after the season's renewals.

## News: match results last, brand tickers (house rule, 2026-11-17)

- Plain match results are the last fallback: a brand's headline goes to its other news first (a promoted story), and only with no other real news does its biggest match lead; results only fill the story cards other news leaves empty, biggest first, and never run in the news ticker.
- Raw and Smackdown each get a ticker (`BrandTickers`): the brand's crowd in gold, a line per match on its weekly show in card order ("Edge def. Kane · Normal Matches — Single"), then its leftover news. In a week the brand is on a PPV, it lists the weekly show from the week before. Cross-brand leftovers get a WWE ticker when there are any.
- Headline wording updated (the GM's edits in `NewsTemplates`), e.g. title changes read "A new {title} was crowned!". The #1 contender's longer headline is allowed past the 6-word rule.

## Tests

- `tests/Feature/Game/CareerCheckpointTest.php`: save → play two weeks → load restores every table exactly; mid-airing restore; other careers untouched; no checkpoint opens as is; list and header data; one checkpoint per career; deleted with the career; every per-career table is registered.
- Frontend: header button states (`gm-layout.test.tsx`), loading from Saved careers (`saves/index.test.tsx`), the rules modal and the rival's tag reels (`redraft.test.tsx`).
- `RivalSeasonEndTest` now expects champions kept and the roster refilled, not every contract renewed.
- `tests/Feature/Ai/RivalDivaCapTest.php`: the cap on each brand, and signing below it.
- `tests/Feature/Ai/RivalRenewalTest.php`: each renewal rule, the final-week timing and the budget fallback.
- `tests/Feature/Ppv/BiggerRedraftTest.php`: 3 protected, 2 manual picks in order, 20 picks, the rival's tags, fewer when fewer are eligible, the headline names; the older re-draft tests run at the original size.
- `tests/Feature/News/BrandTickersTest.php` and two `NewsComposerTest` cases: the brand tickers, the PPV week's earlier show, and match results as the last fallback.
- Survivor Series: the 5 vs 5 main event; Gauntlet: Tornado Tag Turmoil's bouts, Book to Win and tag-title #1 contendership; card-builder: its one-row layout.
