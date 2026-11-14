# Rivalry heat rework

## Context

A rivalry started at 10 heat, and a quiet week cost 20 (raised from 5 on 2026-11-11). Only a meeting where one rival beat the other added heat. So a rivalry ended the first week its rivals didn't face each other head to head. In one career, Paul London vs Rey Mysterio started in week 2 and ended in week 3: both lost the same Fatal 5-Way to Johnny Nitro, and that didn't count.

The new rule: being in a match together is what keeps a rivalry going, whoever wins.

## Rules (house rule, 2026-11-12)

Starting a rivalry is unchanged. The same two superstars meet 2 times within 4 weeks (against each other), or a Start a Rivalry promo runs, or a crashed Interview Space starts one. Heat starts at 10.

After that, heat is settled once a week, after the shows (`RivalryService::settleWeek`):

| The week | Heat |
| --- | --- |
| The week it starts | No change (stays at 10) |
| The rivals share a match on a weekly show | +10 (once a week) |
| The rivals share a match at a PPV | +20 (once per PPV, on top of a weekly show meeting) |
| No shared match, but a Continue Rivalry promo for it | No change |
| Anything else (a quiet week) | −20; at 0 or below it ends |
| It reached 100 in an earlier week | Ends, with a `rivalry_concluded` headline |

Notes:

- Sharing a match means both compete in it, as opponents or partners. Two losers in the same Fatal 5-Way count.
- A Rival Interference finish still adds 10 (2026-11-11) and keeps the week from being quiet.
- PPV blow-offs are gone. `rivalry_blowoff` stays in the catalog only so old news still renders.
- In SvR 2006 a quiet week ended a rivalry outright. The −20 is the web version's fairer take.

## Changes

- **Config:** `gm.rivalries` gains `heat_per_ppv_meeting` (20) and loses `heat_per_promo` and `blowoff_min_heat`.
- **Database:** a new migration adds `rivalries.maxed_week`, the week heat reached 100.
- **`RivalryService`:**
    - `recordMeeting` only starts rivalries.
    - `hold()` replaces the Continue Rivalry heat-up.
    - `settleWeek()` replaces `weeklyDecay()`. It reads the week's aired matches and adds the `rivalry_concluded` headline.
- **News:** `NewsEventType::RivalryConcluded`, with its templates and catalog row.
- **Rules text:** the Rivalry page's "How rivalries work", `/gm/docs` (Promos, beside the rivalry promos) and CLAUDE.md.

## Verification

- `RivalryHeatTest`:
    - start week;
    - opponents, partners and fellow Fatal 4-Way competitors;
    - once a week;
    - PPV +20 with no blow-off;
    - Continue Rivalry holds;
    - quiet weeks down to 0;
    - 100 ends the week after, with the headline;
    - config.
- `RivalryOrderTest`, `PromoTest` (Continue Rivalry holds the heat), `rivalries.test.tsx`.
