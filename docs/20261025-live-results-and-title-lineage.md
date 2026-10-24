# Live results, Book to Win and title lineage

## Context

**Air week N** used to resolve the whole week in one go, and the results screen only replayed it. The player now decides their own matches on the results screen: each one can be simulated (the normal odds) or decided with **Book to Win**. Promos weren't shown on the results screen at all. The Title History lineage had redundant columns (Event, Lost, Ended) and didn't say which matches a champion defended in. The Rosters table had a Weight Class column that wasn't needed.

## Live results (2026-10-25)

- **Air week** (`WeekController::advance`) calls `WeekResolver::begin()` and opens the results screen. It runs the steps before `AirShows` (`PrepareShows`), records fatigue going in, marks the week's shows `airing`, and airs up to the player's first match.
- **Air order** is unchanged: PPVs, then the interbrand card, then the weekly shows.
    - The rival GM's own shows always simulate, on their own, as soon as their turn comes. Friday's SmackDown waits for Monday's Raw.
    - The player's shows and the interbrand cards wait for the player.
- **One match at a time, in air order** (`WeekResolver::decide()`; `POST results/{week}/decide` with `slot_id` and `winner_side`). Only the match up next can be decided, and a stale `slot_id` is refused.
    - `ShowResolver::airNext()` airs the promos placed in front of that match, then the match itself.
    - `ShowResolver::close()` airs a finished show's remaining promos and writes the promo stories.
- **Simulate all** (`POST results/{week}/simulate-all`) decides everything left.
- **After the last match**, the steps after `AirShows` run (recovery through `AdvanceWeek`) and the `WeekResolution` is written.
- **Between requests**, the week's `WeekContext` data and its show RNG streams live in `week_airings`. `WeekContext::stream()` keeps one generator per stream for the whole week, and `Rng::state()` / `RngFactory::restore()` serialise the Xoshiro engine. An all-simulated live week therefore gives exactly the same results as `resolveWeek()`, which still airs a whole week in one call (tests, Simulate to here). Simulate to here finishes a half-aired week without rebooking it.
- **While a week airs:**
    - Its cards can't be edited (status `airing`).
    - The Air button reads **Resume results** (`game.airing`).
    - The schedule opens the results.
    - Signings, releases and trades are refused ("Finish this week's results first.").

## Book to Win (2026-10-25)

- `MatchResolver::resolve()` takes an optional booked side, and that side wins.
- A booked **title** match always ends decisively: pinfall, submission or the type's own win condition, never DQ or count-out.
- Any other booked match ends by DQ (Referee Attacked) or count-out on a **5%** roll (`gm.outcomes.booked_non_decisive_chance`), but only where the type allows them (`dq_enabled`, `countout_enabled && ring_out`). Otherwise it ends with a clean finish.
- Simulated matches are unchanged.
- `match_results.booked` marks a booked result, and the outcome card shows **Booked to win**.

## Results screen (2026-10-25)

- **Undecided matches** list as `A vs B`.
    - The next one has a **Manual book to win** button and opens by itself.
    - Its card lists each side with **Book to Win**, plus **Simulate match**. A title match notes that a booked win is always decisive.
    - Later matches show **Waiting**, and their buttons are disabled.
- **Simulate all** sits beside **Continue**. Continue is disabled until the week is in.
- **Promo rows** sit before the match they aired in front of: **Promo N** and the promo, the wrestler(s) underneath, and the result on the right. **Succeeded** is green; **Failed**, **Fizzled** and **Backfired** are red. `PromoSlot::status()` works the result out.
- **(C)** marks the champion going into a title match, in the list and on the outcome card. `match_results.champion_reign_id` stores the reign on the line; undecided matches use the current reign.
- **Fatigue this week** shows a placeholder until every match is decided, then appears and plays its animation.
- `?match={slot}` opens that match's card (Title History links).

## Title lineage (2026-10-25)

- **Columns:** # | Holder | Won | Reign | How | Weeks | Defenses (Event, Lost and Ended are gone).
    - **Won** is the season and week, with the PPV name in gold under it, linked to the match.
    - **Reign** is the holder's Nth reign with that title, matched by the holder ids.
    - **How** is "Awarded", or the match type it was won in (plus "(Money in the Bank cash-in)"). A vacant row gives the vacancy reason ("Vacated: Injured").
- **Matches under each reign** (`matches`, newest first):
    - One row per defense: `match_results` with that `champion_reign_id` and no title change, so a DQ or count-out loss counts. Defenses shows the defense number.
    - Last, the match the title was won in (`kind: won`), with **Won** in the Defenses column. An awarded title has none.
    - Each row shows `vs. {opponents}`, the date and PPV and the match type, and links to that match's results.

## Title change and match result news (2026-10-25)

Brand news cards already sit under their brand, so these two no longer name the show, and they now say how the match was won.

- **Title change:**
    - Headline: "NEW CHAMPION! Jillian Hall defeats Vickie Guerrero for the Women's Championship via pinfall!"
    - Summary: "Jillian Hall beat Vickie Guerrero via pinfall and walks out with the Women's Championship! The champion looks forward to meeting new challengers that she will dethrone!"
    - The pronoun comes from the title: "she" for the Women's Championship (the Divas' only title), "he" for every other title. Tag titles say "The champions look forward… that they will dethrone" (and "walk out").
- **Match result:**
    - Headline: "Funaki defeats Maria in decisive fashion. He made sure to get the win via briefcase retrieval." The pronoun follows the winner: "She" for a Diva, "They" for a team.
    - A DQ or count-out win reads "… defeats … via disqualification. Not the cleanest win, but a win is a win." (`match_result.not_decisive`), since it wasn't decisive.
    - Summary: "In what was a thrilling match, Funaki got the win over Maria as they competed in a thrilling Ladder match!" The match is named in a word: its family label ("Ladder", "TLC", "Hardcore"…), or singles / Triple Threat / Fatal 4-Way for Normal Matches.
- **Payloads:**
    - Both events carry `via`, from `ResolvedMatch::via()`: the result's win condition in lower case (pinfall, submission, disqualification, count-out, or the type's own condition).
    - `match_result` adds `pronoun`, `match_kind` and `outcome` (`decisive` / `not_decisive`).
    - `title_change` adds `champion_pronoun`, `the_champion`, `walks` and `looks`.
- **Old news:** events recorded before this have no `via`, so `NewsTemplates` renders them with the old wording (`title_change.legacy`, `match_result.legacy`).
- **Headline variants:** `NewsTemplates` now picks `type.variant` headline templates the same way it already picked summaries.
- **Docs page:** the News & email tab lists the templates straight from `NewsTemplates`, so it shows the new wording.

## One promo per spot (2026-10-25)

Two promos can't air back to back, so each spot (the match a promo airs before) holds one promo.

- **Booking:** `PromoService::book` refuses a spot another saved promo holds: "Another promo already airs before the main event. Two promos can't air back to back." Without a pick, a promo takes the main event's spot, or the latest one still free. This covers the AI GM and Simulate to here, which book promos without a pick.
- **Card screen** (`promo-slots.tsx`): in the Airs row, a spot the other promo holds has a red X, a greyed-out background and a not-allowed cursor. It's disabled, with the tooltip "Promo N already airs here". A new promo defaults to the latest free spot, and Save stays off while the chosen spot is taken.
- **Docs:** the `/gm/docs` Promos tab ("When promos air") states the rule.

## Injuries judged after the match (2026-10-25)

Until now the fatigue going _into_ a match decided injuries: 80–90% rolled a 15% minor chance, and 100% was a guaranteed serious injury. So a superstar going from 60% to 100% in one match couldn't get hurt.

- **The rule now:** `InjuryService::rollFor` judges the fatigue right after the match or tiring promo, before the 100% cap: going in + cost × 10%.
    - **0–70%:** no injury.
    - **80–100%:** always an injury: 80% minor, 20% serious (`gm.injuries.serious_share`, replacing `minor_chance`).
    - **Over 100%** (e.g. 70% + a 4-bar Battle Royal = 110%): always serious.
- **Unchanged:** a minor-injured superstar who competes still rolls the 20% escalation first. A match or promo that adds no fatigue never injures.
- **New column:** `injuries.fatigue_after` keeps the uncapped total (migration `2026_10_25_000002`).
- **Results fatigue card:**
    - After the show: a total over 100% draws all 10 bars with a red "110% Injury" label (`FatigueMeter`).
    - After recovery: a seriously injured superstar shows "Serious Injury: {injury}!" in red instead of their fatigue, and the trail reads "60% → 100% → Injured". A minor injury still goes through recovery: the recovered meter and trail stay, with "Minor Injury: {injury}!" in yellow beside them. `ResultsController` sends each wrestler's injury that week.
- **Docs:** the `/gm/docs` Fatigue & injuries tab explains the new rule with the configured odds.

## WWE headline precedence (2026-10-25)

In Backlash week the WWE card led with a failed SmackDown Slander, because only shared PPVs (and the Royal Rumble winner) made WWE-card news, and Slander/Interference always took the WWE headline.

- **PPV week: the PPV wins the WWE headline.**
    - `ShowResolver::announcePpvMainEvent` records a `ppv_results` headline (WWE card) for every PPV card's main event. The Royal Rumble keeps its own winner result.
    - The headline names who won, the title if one was on the line, and the gimmick if it's one (` in a Ladder match`, from `matchKind`).
    - Variants (`outcome`):
        - `main_event`: "Backlash main event: Edge defeats John Cena via pinfall!"
        - `title_change`: "… in a Ladder match to win the WWE Championship!"
        - `title_retained`: "… to retain the WWE Championship!"
        - `title_kept`: "… by disqualification, but the WWE Championship stays put!"
    - Each variant has a matching summary.
    - Priority: the interbrand card's main event (Survivor Series, WrestleMania) is 95, a brand PPV's is 85 (`gm.news.ppv_main_event_priority`), and the Royal Rumble winner stays 90.
    - `NewsComposer` picks the biggest `ppv_results` first for the WWE card, ahead of Slander/Interference. It uses its own dedupe key (`ppv-main:{slot}`), so the brand card keeps the same match's result or title change.
- **Non-PPV week, in order:**
    1. A Slander or Interference result.
    2. Other cross-brand headline news, drawn by priority: trades, defections, legends, the re-draft, the interbrand series, the season reset, a PPV main event being set, and the PPV buzz.
    3. A promoted cross-brand story.
    4. The quiet-week "Tickets on sale now for {event}!" filler.
- **PPV buzz on the WWE card:** `PpvBuzz` now records "{ppv} is N weeks away" on the WWE card for every PPV, brand PPVs included (a story 2 weeks out, a headline 1 week out). Before, brand PPVs only made their brand's card.
- **Catalog and docs:** `NewsCatalog` describes the new `ppv_results` and `ppv_upcoming` placement, and the `/gm/docs` News & email tab ("The page") lists the WWE headline order.

## Docs page (2026-10-25)

- `/gm/docs` has a new **Results & titles** tab with four panels: Live results, Book to Win (its DQ/count-out chance comes from config through `DocsController`'s `results` prop), The results screen, and Title History.
- The Schedule tab's Booking a week panel now says Air week starts the live results.

## Rosters (2026-10-25)

- The Weight Class column is gone, and Specialties sits right after Streak.

## Files

- **Backend:**
    - `app/Services/Week/WeekResolver.php`, `Steps/AirShows.php`, `WeekContext.php`, `WeekSimulator.php`
    - `app/Services/Matches/ShowResolver.php`, `MatchResolver.php`, `MatchOutcomeHandler.php`
    - `app/Support/Rng/*`, `app/Models/WeekAiring.php`
    - `app/Http/Controllers/Gm/{Week,Results,TitleHistory,Contract,Trade,Schedule}Controller.php`, `app/Http/Presenters/{Card,Game}Presenter.php`
    - Migration `2026_10_25_000001_create_week_airings_and_result_columns.php`
- **Frontend:**
    - `resources/js/pages/gm/results.tsx`, `titles/history.tsx`, `rosters/index.tsx`
    - `components/gm/book-to-win-card.tsx`, `champion-tag.tsx`, `outcome-card.tsx`, `air-week-button.tsx`
- **Tests:**
    - `tests/Feature/Season/LiveResultsTest.php`, `tests/Feature/Titles/TitleHistoryTest.php`
    - `results.test.tsx`, `titles/history.test.tsx`, `rosters/index.test.tsx`
