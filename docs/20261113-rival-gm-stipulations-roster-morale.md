# Rival GM: Stipulations, Full Roster, Morale & Rival Financials

## Context

The rival GM books matches, promos and rivalries, but it leaves some things to chance:

- It never books a Loser Leaves Show or a Last Chance stipulation.
- Its #1 Contender's Matches are random 10% rolls.
- It stops signing at 28 superstars.
- It does nothing about its roster's morale. Unused superstars drift to Upset and defect.

This plan gives it a plan for each, and adds the rival's books to the Financials page.

## Rules (house rule, 2026-11-13)

All numbers live in `config('gm.ai')`.

### Loser Leaves Show and Last Chance

The rival rolls on a fixed interval. Its first roll is at its first card of the season.

| Stipulation | Interval | Roll | Then |
| --- | --- | --- | --- |
| Loser Leaves Show | 26 weeks (6 months) | 85% books one, 15% skips | Skipped: the next roll is 26 weeks later |
| Last Chance | 17 weeks (4 months) | 85% books one, 15% skips | Skipped: the next roll is 17 weeks later |

- **A won roll keeps trying.** Each of its cards tries again until one is eligible and booked. The next roll is a full interval after it books.
- **Loser Leaves Show:** a 1v1 between its two least valuable superstars of one gender, valued by trade value (`AiTradeJudge::value`).
    - Champions, Legends and anyone over its fatigue limit are left out.
    - The loser goes to Free Agency, as for the player.
- **Last Chance:** a title match against a challenger who qualifies (`LastChanceService::availableFor`: met the champion twice in this reign and lost both).

### #1 Contender's Match before its own PPVs

- **When:** on its weekly shows in the 3 weeks before each of its brand-only PPVs, it books at least one #1 Contender's Match.
- **Which title:** rolled by weight, among titles it can fill: world 45%, secondary (Intercontinental or United States) 25%, tag 15%, special (Women's or Cruiserweight) 15%.
- **Match shape:**
    - Multi-man matches (Triple Threat and up) are preferred for the secondary and special titles. _(assumption: 70% of the time)_
    - The world and tag titles lean 1v1 / 2v2. _(assumption: 70% of the time)_
- **The pay-off:** it holds the contendership on its weekly shows until the PPV. At the PPV the title match against exactly the contender(s) goes first, satisfying the Active Effect.

### Filling the roster

- **Target:** it signs up to the full 30 (was 28), keeping its $500,000 cash reserve.
- **Every signing checks the budget,** Legends included. A Legend it can't afford isn't signed.
- **Who:** while it has fewer than 3 Legends, each signing rolls 55% Legend, 45% anyone else (title fillers and its focus class first, as now). If the roll picks a Legend but none is affordable, it signs a non-Legend instead.
- **Legend cap:** at 3 Legends it only signs non-Legends.

### Morale

- **Who it helps:** each card, for each of its superstars with a complaint, it rolls to help.
    - A complaint at Content or Unsatisfied: 75%.
    - Upset: 95%.
    - Most upset first, one fix per superstar per week.
- **How it fixes each complaint:**
    - **Unused:** books them in a match.
    - **Losing streak:** books them to win a singles match. This is the rival's Book to Win, stored as `match_slots.booked_side` and honoured when its show simulates. As with the player's, a booked title match always ends decisively.
    - **No title match:** books them against the champion of a title they're eligible for, when the champion fits its fatigue limit.
    - **Contract demand:** renews their contract in its roster upkeep, if it can afford to.
- **Order:** morale bookings come after title obligations and rematches, before its random fills.

### Rival financials

- The Financials page gets Raw / Smackdown buttons, starting on the player's brand.
- The rival's statement, history and weekly upkeep are read-only: the deficit recovery choice stays the player's.

## Changes

- **Config:** `gm.ai.stipulations` (intervals, 85% chances, #1 contender lead weeks, title weights, multi-man preference), `gm.ai.roster.target_size` 30, `legend_chance` 0.55, `max_legends` 3, `gm.ai.morale` (75% / 95%).
- **Database:**
    - `save_brands.ai_plans` (json): roll due weeks and won-roll flags.
    - `match_slots.booked_side` (nullable int): the rival's Book to Win.
- **`AiBooker`:**
    - morale bookings;
    - Loser Leaves and Last Chance when a roll is won;
    - the pre-PPV #1 Contender's Match;
    - booked-to-win slots.
- **`TitleMatchPlanner` / obligations:** the rival holds a #1 contendership for its next brand-only PPV within 3 weeks.
- **`ShowResolver`:** a simulated slot with `booked_side` wins as booked.
- **`AiRosterManager`:** fill to 30 with the Legend roll and cap; contract-demand renewals.
- **Financials:** a brand toggle (`?brand=`), read-only for the rival.
- **Docs:** CLAUDE.md, `/gm/docs` (ask first), this plan.

## Verification

- **Pest:**
    - interval rolls (book, skip, retry until eligible);
    - Loser Leaves picks the least valuable pair;
    - Last Chance only when eligible;
    - #1 Contender's Match in the 3 weeks before a brand-only PPV, held, then paid off at the PPV;
    - title weights;
    - roster fill to 30 with the Legend cap and budget check;
    - morale fixes (unused, losing streak booked to win, title match, contract renewal) with the 75% / 95% rolls;
    - `booked_side` wins on a simulated show.
- **Vitest:** the Financials brand toggle.
