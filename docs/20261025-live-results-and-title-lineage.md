# Live results, Book to Win and title lineage

## Context

**Air week N** used to resolve the whole week in one go, and the results screen only replayed it. The player now decides their own matches on the results screen: each one can be simulated (the normal odds) or decided with **Book to Win**. Promos weren't shown on the results screen at all. The Title History lineage had redundant columns (Event, Lost, Ended) and didn't say which matches a champion defended in. The Rosters table had a Weight Class column that wasn't needed.

Later changes on the same branch:

- **News wording:** title change and match result news reworded.
- **Promos:** one promo per spot, new success rates, random Merchandise deals, and crashed Interview Spaces.
- **Injuries:** judged on fatigue after the match.
- **WWE headline:** the PPV now takes the WWE headline in a PPV week.

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

## Promo success rates, Merchandise deals and crashed interviews (2026-10-25)

- **Success rates** (`gm.promos.success_rates`): every promo is now 100% except these:

    | Promo                   | Success                      |
    | ----------------------- | ---------------------------- |
    | Advertising             | 80%                          |
    | Interview Space         | 90% (kept so it can fail)    |
    | Interference Promotion  | 50%                          |
    | Slander Promotion       | 50%                          |
    | Merchandise Sponsorship | depends on the deal it rolls |

- **Merchandise Sponsorship:** `PromoService::merchandiseTier` rolls one deal from `gm.promos.merchandise_tiers` with equal odds, then the success roll uses that deal's odds:

    | Deal       | Success |
    | ---------- | ------- |
    | $100,000   | 80%     |
    | $250,000   | 70%     |
    | $500,000   | 50%     |
    | $1,000,000 | 25%     |
    - A success pays the amount. A failure pays nothing.
    - The old consecutive-use scaling (`gm.promos.merchandise`, `merchandiseAmount`) is removed.
    - `PromoType::successRange()` feeds the card and `/gm/docs`, which show "25–80%".

- **Interview Space crashed:** when Interview Space fails for a wrestler who isn't booked on the card, `PromoService::crashInterview` picks a random healthy male superstar from the same brand. It skips Divas, the seriously injured and anyone sitting out. The pick is stored as the promo's second target, and the outcome note reads "Crashed by X".
    - If neither of the two is in an active rivalry, a rivalry starts between them, and the note ends with "rivalry started".
- **Interview Space stories:** `PromoStories` has two new situations, 4 variants each. Each variant is one combination of sit-down interview / interview × goofy / enthusiastic, so each pair is 50/50.
    - `interview_space.spotlight`: the fallback when an unbooked, successful interview has nothing about the wrestler's form (no streak, title wait or title). "{wrestler} had a sit-down interview tonight and was being goofy. It was a great interview and gave insights on how he would be used and ready to take over and dominate the Raw division!" The pronoun is "she" for a Diva.
    - `interview_space.crashed`: a failed unbooked interview that got crashed. "… The interview was on-going until {wrestler_b} crashed the party and decked {wrestler} with authority. What's gonna happen next between these two?" When the attack started a rivalry, it adds " The attack has started a rivalry between the two."
    - A failed interview with nobody to crash it still falls flat (`unbooked_flat`). Booked interviews keep their match stories, and form extras keep theirs.
    - The story says "the Raw division" (the brand) rather than "the Monday Night Raw division".
- **Merchandise stories:** these no longer say the deal grows with use.
- **Docs:** the `/gm/docs` Promos tab lists the deals and their odds, which promos can fail, and the crashed interview.

## Promo fatigue for Diva Bikini Contest and Continue Rivalry (2026-10-25)

- `gm.promos.fatigue_bars` gives Diva Bikini Contest **2 bars** and Continue Rivalry **1 bar**, on top of Interference and Start a Rivalry's 2.
- `PromoService::tire` already tires every target, so both Divas and both rivals take it where the promo airs, success or fail. They roll injuries on the fatigue it leaves them on, like a match.
- The bars are seeded onto `promo_types.fatigue_bars`. `php artisan db:seed --class=PromoTypeSeeder` applies them to an existing database without wiping saves, because the seeder uses `updateOrCreate`.
- **Docs:** the `/gm/docs` Promos tab ("When promos air") lists every fatiguing promo and its bars. The promo list draws them as bars. The panel's old going-in injury wording is replaced with the after-match rule.

## One of each promo per wrestler, and a saved-state Save button (2026-10-25)

- **Guard:** a wrestler can't be in two promos of the same type on one card. This covers every promo type: two Interview Spaces for Batista, or Melina in both Diva Bikini Contests.
    - `PromoService::book` refuses it: "{wrestler} is already in Promo N's {promo}." Different promo types are fine (Edge can have an Interview Space and an Interference).
    - On the card screen (`promo-slots.tsx`), the wrestler pickers grey out anyone in the other saved promo of the same type, with "In Promo N", and Save won't enable for them.
- **Save button:** Save promo is greyed out while the promo matches what's saved (type, wrestlers, title and spot), like Save match. It lights up again on any change, with "Unsaved changes" beside it, and greys out again once the save lands.
- **Discard button:** each promo has a **Discard** button (ghost, between Save and Clear), as matches do. It's enabled only with unsaved changes, and it puts the type, wrestlers, title and spot back to the saved promo (or empties a slot that was never saved).
- **Docs:** the `/gm/docs` Promos tab ("How promos work") states both.

## Continue Rivalry picker and alphabetical rivalries (2026-10-25)

- **One rivalry picker:** Continue Rivalry no longer has two wrestler pickers. A single picker (`components/gm/rivalry-picker.tsx`, built on `usePopover`/`PopoverPanel`) lists the brand's active rivalries as "Jeff Hardy vs. Rob Van Dam", sorted alphabetically.
    - Picking one sets both targets, alphabetical first.
    - A rivalry already in the other Continue Rivalry promo is greyed out ("In Promo N").
- **Always succeeds:** Continue Rivalry is 100% (see the success-rate change), and both rivals take 1 fatigue bar where it airs.
- **Rivalries are named alphabetically:** a rivalry is always 1v1.
    - `RivalryService::start` stores the alphabetically first wrestler as `wrestler_a` (`RivalryService::alphabetical`). The rivalry news ("{wrestler_a} vs {wrestler_b}") reads alphabetically too.
    - The Rivalry page sorts every pair alphabetically, older rivalries stored by id included.
    - `between()` still finds a rivalry in either order.
- **Docs:** the `/gm/docs` Promos tab describes the picker and the naming.

## Docs page (2026-10-25)

- **Results & titles (new tab):** four panels.
    - Live results.
    - Book to Win. Its DQ/count-out chance comes from config through `DocsController`'s `results` prop.
    - The results screen.
    - Title History, including the title-win row under each reign.
- **Schedule:** the Booking a week panel says Air week starts the live results.
- **Fatigue & injuries:** the Fatigue zones panel explains the after-match injury rule, with the configured serious share and the "110% Injury" and "Serious Injury: …!" display.
- **Promos:**
    - The new success rates, the Merchandise deals and their odds (`merchandise_tiers` prop), and the crashed Interview Space.
    - One promo per spot.
    - The Interference/Slander headline exception in a PPV week.
    - The promo list shows Merchandise as 25–80%.
- **News & email:** "The page" panel lists the WWE headline order. The event list picks up the new `ppv_results`, `title_change`, `match_result` and `ppv_upcoming` wording and placement from `NewsCatalog` and `NewsTemplates`.

## Rosters (2026-10-25)

- The Weight Class column is gone, and Specialties sits right after Streak.

## Files

- **Backend:**
    - `app/Services/Week/WeekResolver.php`, `Steps/AirShows.php`, `Steps/PpvBuzz.php`, `WeekContext.php`, `WeekSimulator.php`
    - `app/Services/Matches/ShowResolver.php`, `MatchResolver.php`, `MatchOutcomeHandler.php`, `ResolvedMatch.php`
    - `app/Services/Injuries/InjuryService.php`
    - `app/Services/Promos/PromoService.php`, `PromoStoryWriter.php`
    - `app/Services/Rivalries/RivalryService.php`, `app/Http/Controllers/Gm/RivalryController.php`
    - `app/Services/News/NewsComposer.php`, `NewsTemplates.php`, `NewsCatalog.php`, `PromoStories.php`
    - `app/Support/Rng/*`
    - Models: `app/Models/WeekAiring.php`, `MatchResult.php`, `Injury.php`, `PromoSlot.php`, `PromoType.php`
    - Controllers: `app/Http/Controllers/Gm/{Week,Results,TitleHistory,Contract,Trade,Schedule,Card,Docs}Controller.php`
    - Presenters: `app/Http/Presenters/{Card,Game}Presenter.php`
    - `config/gm.php`, `routes/gm.php`
    - Migrations: `2026_10_25_000001_create_week_airings_and_result_columns.php`, `2026_10_25_000002_add_fatigue_after_to_injuries.php`
- **Frontend:**
    - Pages: `resources/js/pages/gm/results.tsx`, `titles/history.tsx`, `rosters/index.tsx`, `docs.tsx`
    - Components: `components/gm/book-to-win-card.tsx`, `champion-tag.tsx`, `outcome-card.tsx`, `air-week-button.tsx`, `fatigue-progress.tsx`, `fatigue-meter.tsx`, `promo-slots.tsx`, `rivalry-picker.tsx`
- **Tests:**
    - Backend: `tests/Feature/Season/LiveResultsTest.php`, `tests/Feature/Titles/TitleHistoryTest.php`, `tests/Feature/News/{NewsEvents,PpvHeadline,PromoStories}Test.php`, `tests/Feature/Matches/FatigueInjuryTest.php`, `tests/Feature/Card/{Promo,PromoRules}Test.php`, `tests/Feature/Rivalries/RivalryOrderTest.php`
    - Frontend: `results.test.tsx`, `titles/history.test.tsx`, `rosters/index.test.tsx`, `docs.test.tsx`, `promo-slots.test.tsx`
