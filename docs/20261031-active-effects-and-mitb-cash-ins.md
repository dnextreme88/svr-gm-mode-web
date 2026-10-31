# Active Effects, Money in the Bank Cash-in Timing & Balance Tweaks

Executed **2026-10-31**. Branch `feature/20261031-active-effects-and-mitb-cash-ins`, stacked on `feature/20261031-match-stipulations-and-title-contention` (#16).

## Why

Follow-ups to the title stipulations:

- the card page's alert box becomes **Active Effects**, showing everything in play;
- Money in the Bank can be cashed in before, during or after a match;
- the Unused morale clock and minor injuries are rebalanced;
- `/gm/docs` gets a Morale tab;
- small Schedule and card polish.

## What landed

- **Schedule current week:** the player's cards this week get a brand-coloured arc that sweeps round the cell border, with a soft pulsing glow (`.gm-current-week` in `gm.css`, a registered `--gm-sweep` angle). The "Book" label is gone; screen readers still hear "this week, to book".
- **Stipulation order:** the dropdown lists stipulations alphabetically under "No stipulation" (`stipulationOptions`).
- **Active Effects:** the card page's gold box is now **Active Effects**, always shown, with "No active effects at the moment" when nothing applies. It lists:
    - earned title shots still to book;
    - #1 contenders waiting;
    - Last Chance lockouts (`title_context.last_chance_lockouts`), until the champion loses or vacates the title;
    - the Money in the Bank holder until they cash in;
    - Buried Alive losers sitting out, with the weeks left (`buried_alive`).

    A Buried Alive loser's picker note now reads "Buried alive" instead of "Sitting out (blocked defection)". Tests: `active-effects.test.tsx`, `tests/Feature/Http/ActiveEffectsTest.php`.

- **Minor injuries last longer (house rule):** the 1–3 week minor injuries are now 2–4 weeks, and the 2–4 week ones 3–5 (`injury_types.php`). The 1–2 week ones and every serious injury are unchanged. Minor injuries now run 1–5 weeks (`gm.injuries.minor_max_weeks` = 5).
- **Morale on `/gm/docs`:** a new **Morale** tab (`MoraleDocs`): how morale works, the four complaints with their Content / Unsatisfied / Upset thresholds and what clears each, and defection (weekly odds, moving brands or sitting out). The numbers come from `gm.morale` and `gm.defection` through `DocsController`.
- **Morale: Unused** now reads 3 / 6 / 9 weeks for Content / Unsatisfied / Upset (was 2 / 4 / 8; `gm.morale.inactivity`). `MoraleTest` follows.
- **Money in the Bank cash-in timing (house rule):** `match_slots.mitb_cash_in` (`MitbCashInTiming`: before / during / after), added by its own migration `2026_10_31_000002_add_mitb_cash_in_to_match_slots`.
    - **Before:** `MitbService::cashIn` takes the position to insert at (the card's **Cash in** button opens `CashInButton` to pick it: before any match or after the Main Event).
    - **During:** `MitbService::cashInDuring` (through `WeekResolver::cashInDuring`, `POST results/{week}/cash-in`) adds the holder to the world title match up next and grows it into the family's variant with one more singles side; managers drop.
    - **After:** `WeekResolver::decide` sets `cash_in_offer` when a world title match airs with the player's briefcase still held. `proceed()` waits until `answerCashIn` (`POST results/{week}/cash-in-offer`) cashes in (`MitbService::cashInAfter` inserts a title match right after it) or passes; deciding another match first is refused, and Simulate all passes.
    - Cash-ins settle on the holder's own win (`settleCashIn`), with `WonVia::MitbCashIn` only when the holder takes the title.
    - The results list, the outcome card and Book to Win tag the match "Money in the Bank cash-in" (`CashInTag`).
    - Tests: `tests/Feature/Titles/MitbCashInTimingTest.php`, `cash-in.test.tsx`.
- **Match limits and the Main Event:** a cash-in is the one match that can take a card past its slots (7 on a weekly show, 6 on a PPV; `gm.card.cash_in_extra_slots`). The GM still books 6 / 5. The Main Event is always the card's last regular match, never a cash-in (`ShowResolver`, the card screen, the results reel), and reads "Main Event" everywhere.
- **Deferred:** cash-ins on non-world title matches and singles matches (CLAUDE.md TODO).

## Verification

- **Pest:** `tests/Feature/Titles/MitbCashInTimingTest.php` covers:
    - during: Triple Threat and Fatal 4-Way growth, the holder's win and loss, world titles only;
    - after: the offer waits, decide is refused meanwhile, the insert lands after it, Not now and Simulate all pass;
    - before: the chosen position and the default after the Main Event.
- **Also Pest:** `tests/Feature/Http/ActiveEffectsTest.php`, `MoraleTest` (3 / 6 / 9), `CatalogSeedTest` (minor 1–5).
- **Vitest:** `active-effects.test.tsx`, `cash-in.test.tsx`, `docs.test.tsx` (Morale, Money in the Bank), `schedule.test.tsx`, `titles.test.ts`.
- **Browser:**
    - the card shows Active Effects;
    - a live week's world title match offers "Cash in: Batista", becomes a Hardcore Triple Threat, and the outcome card tags it "Money in the Bank cash-in".
