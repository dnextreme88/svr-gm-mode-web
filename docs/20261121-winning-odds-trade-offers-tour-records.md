# Winning Odds, Rival Trade Offers, Guided Tour, Records Book & Draft Balance

Executed 2026-11-21 on `chore/20261119-gm-mode-ideas-todos`.

## Context

The user picked four TODOs to build (winning odds, rival GM trade offers, the guided tour and the records book) and asked for three draft and balance fixes: sorting and brand counts on the draft page, a draft that ends before the rival's last pick could be seen, and a $12M budget with the rival GM keeping $1M–$3M after the draft.

## Decisions (from the user)

- **Winning odds** are straight percentage points on top of an equal share: a side's points come off the other sides, so a specialist in a 1v1 is 60% to 40%, and two specialists cancel out. The old specialty bonus weight and its tests are gone; the specialty is now one modifier among five.
- **Rival trade offers** are sized so the rival comes out 1 or 2 of its own points ahead, picked at random per offer (the 90% and 100% bands it uses on the player's offers). Each asks for 1 to 5 of the player's superstars, how many varied at random (follow-up the same day; it first asked for 1).
- **The guided tour** opens on its own once per career, and is on the Schedule only, one step per menu bar tab.
- **The Records book** is a tab on Reports: PPV results only, both brands.
- `/gm/docs` updated.

## What changed

### Winning odds (`WinningOdds`, `gm.outcomes.winning_odds`)

- Modifiers: specialty +10 per side; best win streak +5 / +10 / +15 at 5 / 10 / 20; an Interview Space that aired that week +5 / −5; each minor injury −10; weight class +5 per class above the lightest side, only when every side is one class. Floor 5% _(assumption)_.
- `MatchResolver` rolls on the chances; the odds going in are stored on `match_results.winning_odds` and `gauntlet_bouts.winning_odds` (migration `2026_11_21_000001`).
- `CardPresenter` sends `winning_odds` (outcome and pending) and `bout_odds` (a pending Gauntlet, every pair).
- `<WinningOdds>` (`components/gm/winning-odds.tsx`) on the outcome card, each Gauntlet bout and Book to Win: 20 square 5% segments, **Why these odds?** opens the breakdown on click and on hover.

### Rival GM trade offers (`AiTradeOffers`, `gm.ai.trades.offers`)

- Week step `RivalTradeOffer` after `ApplyPendingMoves`: every 8th season week _(assumption)_, one open offer at a time, expiring after 2 weeks _(assumption)_ (`trades.expires_week`, migration `2026_11_21_000002`, `TradeStatus::Expired`).
- Email `RivalTradeOffer` (`EmailTexts` `trade.offer`) with Accept / Decline (`POST trade/offers/{trade}/accept|decline`); Trade page history tags **Their offer**.

### Guided tour

- `components/gm/guided-tour.tsx`: 12 steps (Schedule, Email, Rosters, Reports, Title History, Rivalry, Factions, Propose Trade, Free Agency, Financials, News, Docs), Back / Next / Finish / Skip tour, step bar.
- Schedule opens it on its own in the career's first week (`tour.auto`), once per browser (`gm.tour.{save}.{career}`); **Take the tour** reopens it.

### Records book (`PpvRecords`)

- Reports tabs: **Results** and **Records book** (`?tab=records`): most PPV main events, Match 1 openers, PPV wins, PPV losses, Royal Rumble eliminations (top 10, ties at the cut, `gm.records.leaderboard_size`); Royal Rumble, Money in the Bank, Survivor Series 5 vs 5 and interbrand series history; every PPV's results.

### Draft and balance

- `gm.starting_budget` $12M (was $15M).
- Rival draft lengths 12 / 9 / 6 months, stepping down to keep a random $1M–$3M reserve (`AiGm::draftLength`, `draftSpendable`, `draftReserve`).
- Draft page: Superstar and Class sorting (`sortPool`), Superstars / Divas / Specialists counts on each brand card (`pickCounts`), and a 1.8-second pause on the last pick before the "Draft picks are finished" overlay.

### Week steps (follow-up)

- The setup step bar became `StepTrail`; `WeekSteps` shows Book the week → Show results → Weekly financials → News → Emails ("Book Backlash", "Backlash results", "Backlash financials" on a PPV night) on the card, the live results and the end-of-week Financials, News and inbox, only for the week being played.

## Tests

- `tests/Feature/Matches/WinningOddsTest.php`, `tests/Feature/Ai/RivalTradeOffersTest.php`, `tests/Feature/Reports/PpvRecordsTest.php`, the rival draft budget in `tests/Feature/Season/DraftTest.php`.
- Frontend: `winning-odds.test.tsx`, `draft-sort.test.ts`, `reports.test.tsx`, the tour in `schedule.test.tsx`.
- Test fixtures that build big rosters now sign without the budget check (`GameBuilder::inSeason`, a few helpers), since 30 superstars on 12-month deals cost more than $12M.

## Assumptions (in config)

- `gm.outcomes.winning_odds.min_chance` 5.
- `gm.ai.trades.offers.every_weeks` 8, `answer_weeks` 2.
