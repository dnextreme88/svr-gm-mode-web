# Promo stories and card screen polish

## Context

Every promo made the same generic `promo_result` card ("Raw's Interview Space promo succeeds.") with only the brand, promo and outcome, and never named the wrestler. Promos that take a wrestler should tell a story about them, and the hype promos should tell how the thing they hyped went. Injury Return duplicated Comeback Reminder.

## Rules changed (2026-10-24)

- **Stories are written after the show airs.** `PromoStoryWriter` (called by `ShowResolver` once every match and promo has resolved) works out each promo's situation and records its news event. `PromoService` no longer records promo news itself (it still sends the Slander email).
- **Each situation has at least 4 variants** (headline + summary) in `App\Services\News\PromoStories`, drawn with equal odds from the seeded RNG (`promo-stories.{show}` stream). The event payload stores `story` (the situation key) and `variant`; `NewsTemplates` renders both the headline and the summary from them.
- **Situations by promo:**
    - **Interview Space:** booked in a match → success/failed × won/lost, naming the opponent and how the match ended. Not booked → success draws from 4 generic interviews plus extras for the wrestler's form: a 3+ win streak (wants tougher competition), a 3+ losing streak (wants an easier opponent), a long wait for a title match (the first morale threshold, 4 weeks), or holding a title. A failed unbooked interview draws from 4 "fell flat" variants.
    - **Main Event Hype:** the main event's result: new champion, champion retained, the challenger won by DQ/count-out so the champion kept the title, or a non-title match. A success is the brand's `main_event_hype_result` headline (replacing the plain match result); a failure is a `promo_result` story about the same match.
    - **Title Match Hype:** that title match's result (new champion, retained, cheap escape) and where it was on the card ("in the main event", "to open the show", "in match N"). Fizzles when the title match is gone.
    - **Comeback Reminder:** names the injured wrestler, the injury and roughly how long until they're back.
    - **Inactive Superstar** (success, failed, fizzled), **Start/Continue Rivalry** and **Diva Bikini Contest** (name both wrestlers), **Advertising/Merchandise** (the amount), **PPV Hype** (the next PPV and how far away), **New Superstar** (success is the Debut story), **Slander** (succeeds, fails, backfires) and **Interference** (names the wrestler sent): success or fail.
- **How a match ended** in a story: "by disqualification", "by count-out", "by submission", the gimmick ("through a table", "inside Hell in a Cell", "in a Ladder match"…), "with a roll-up", else "with a pinfall".
- **Submission specialists:** a clean finish won by a Submission specialist is now a submission (`MatchResolver::cleanFinish`; the submission roll still happens, so the RNG stream is unchanged).
- **Injury Return promo removed** (enum case, seeder, config, eligibility, tests). 14 promos. The `injury_return` news event and the "back from injury" email are unrelated and stay.
- `/gm/docs` **News & email** has a **Promo stories** panel listing every promo's situations and variants. `gm.news.promo_story_streak_from` (3) is the streak that counts as "form".

## Card screen polish (2026-10-24)

- **Wrestler pickers** (`WrestlerPicker`):
    - Promo pickers list wrestlers alphabetically.
    - A wrestler booked in another match on the same card shows **Booked** on the far right in light gold (`text-gold-hot`). They can still be picked, since double booking is allowed. The flag comes from the card's per-wrestler booking counts (`bookedElsewhere` in `card-builder.tsx`) and shows in the manager and Special Referee pickers too.
    - Names are a size bigger (`text-base`); notes and the Booked label are smaller (11px, condensed, uppercase).
    - The list is 330px tall: Leave vacant plus 8 full wrestler rows.
    - The list can't scroll sideways (a 1px rounding overflow showed a horizontal scrollbar), and `.gm-scroll` scrollbar corners are transparent.
- **Match sides** (`SideGrid` and `sideColumns` in `card-builder.tsx`): every match lays its sides out on a grid, with "vs" between the sides of a row.
    - Up to 3 sides share one row.
    - 4 sides go 2 × 2.
    - 5 or more sides go in rows of 3 (6-ways as two rows of 3, Royal Rumbles in rows of 3), so every side sits under the one above it.
    - On small screens the sides stack, one dropdown per line, with a centered "vs" between every side, row breaks included.
- **Small screens** (below the `sm` breakpoint; wider layouts are unchanged):
    - The match card header stacks: the Match N / Main event label, then the full-width match type selector, then Cost on the left and Fatigue on the right.
    - The match type dropdown matches the selector's width, with a narrower category column, and each type's cost and fatigue go under its name.
    - The title and stipulation selectors take the full width and wrap long names instead of overflowing the page.

## News headlines and match ranking (2026-10-24)

- **Headline precedence: PPV → title → main event → gimmick.** Match results carry a priority (`ShowResolver::resultPriority`, `gm.news.match_result_priority`): undercard 12, main event 28, +30 for a title match, +60 for a PPV match, +6 for a gimmick match (anything but Single, Tag Team, 6-Man Tag or Handicap; the Table tag variants count as gimmicks), −6 for a DQ or count-out win. The steps are spaced so each one outranks everything below it and a DQ never flips one: a PPV match beats any weekly match, an undercard title match (42) beats a non-title gimmick main event (34), and a main event beats any non-title undercard match.
- **A title change always takes its brand's headline** (the highest-priority one if there are several); other headline-sized news is still a weighted draw. A simulated week had a reign record beat the title change that ended the reign.
- **A brand with no headline gets its biggest match.** `NewsComposer` promotes the story about the highest-ranked match (the match result, or the bigger news that replaced it on the same match, like a title defence or a promo story) instead of a weighted draw. Match results also record `main_event` and `ppv` in their payload.
- **Ladder matches are won by Briefcase retrieval** (was "Ladder retrieval"), like Money in the Bank. **TLC matches have no match-specific win condition:** they are won by pinfall or submission. Seed data only; `/gm/docs` lists one Briefcase retrieval condition.

## Quiet weeks (2026-10-24)

- **The News page is never empty.** `QuietWeekNews` runs last before the week advances: it lays out the week's page with `NewsComposer` and writes filler only for the cards left empty, so an old week's page shows that week's champions, budget and injuries.
    - **WWE headline** (`tickets_on_sale`): tickets for whatever airs next: next week's PPV by name (it airs Sunday, first), else Monday Night Raw with its date.
    - **Brand headline** (`brand_spotlight`): the brand's top champion by title order (world, second tier, tag, special) spent the week with fans in a city (`gm.news.tour_cities`); with no champions, the roster hung out at WWE HQ. Then a budget line: positive above `gm.emails.low_cash_threshold` ($500,000), a warning with advice at or below it.
    - **Brand stories:** first a random superstar's last match (`superstar_recap`: won or lost, opponent, how, show, how long ago; "waiting for a first match" if nobody has wrestled), then the injury report (`injury_report`) for the last `gm.news.injury_report_weeks` (26): praise for a clean roster, or the count, the latest injury and booking advice.
    - **Ticker** (`locker_room_note`), only when the ticker is empty: unhappy wrestlers (Unsatisfied or Upset), contracts within `gm.contracts.expiring_threshold_weeks` of running out, or all quiet.
- Texts live in `App\Services\News\FallbackStories` (4 variants for each headline and story, 2 for ticker notes), drawn with the seeded RNG. Filler has priority 1, and `NewsComposer` never promotes it over real news.
- The "how the match ended" phrasing moved from `PromoStoryWriter` to `App\Services\News\MatchFinish`, shared by promo stories and recaps.
- 5 new news event types (57 in all), listed on `/gm/docs` in a new **Quiet weeks** group. The News & email tab also has a **Quiet-week stories** panel (after Promo stories): what fills each card and when, then every situation with all its versions (`FallbackStories::catalog()`). Both panels share one `StoryCatalogPanel`.

## Follow-ups (CLAUDE.md TODOS)

- Alignment-based stories: a goofy/annoying interview, bashing the fans, a champion retaining by heelish tactics. Needs face/heel alignment.
- Attendance figures in a show-hyping interview. Needs Popularity.

## Tests

- `PromoStoriesTest`: every standalone situation has 4+ variants; every promo type has stories; only known placeholders; Interview Space won/lost/unbooked (and that every variant of the pool can come up); Main Event Hype title change headline and failed-hype story; Title Match Hype retained with position and the cheap escape; Comeback Reminder names the wrestler and weeks left; Submission specialists win clean finishes by submission.
- Updated `NewsEventsTest`, `PromoTest` (story keys instead of fixed text), `PromoRulesTest`, `EnumValuesTest`, `CatalogSeedTest` (14 promos), `UiTodosTest` and `docs.test.tsx` (Promo stories panel).
- `card-builder.test.tsx`: the Booked label; a 6-way in two rows of 3, a Fatal 4-Way as 2 × 2, singles and teams on one row with one "vs". `promo-slots.test.tsx`: promo wrestler lists are alphabetical. `saves/index.test.tsx`: follows the New Game button's "Play with WWE Roster" label.
- `NewsComposerTest`: a quiet brand's headline is its main event, or the news that replaced its result. `NewsEventsTest`: main event, gimmick and DQ priorities and the `main_event` flag. `MatchOutcomeTest`: Ladder's clean finish reads briefcase retrieval.
- `QuietWeekNewsTest`: an empty week fills every card; tickets name the next show and date, or the PPV; the top champion tours (world title first); WWE HQ and the low-budget warning; the last-match recap and the injury report; unhappy wrestlers in the ticker; a busy week gets no filler. `NewsComposerTest`: PPV → title → main event → gimmick precedence, an undercard title match over a non-title main event, a PPV main event over the weekly one, filler never promoted over real news. `NewsEventsTest`: title, gimmick (Table tag) and DQ priorities.
