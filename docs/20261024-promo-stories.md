# Promo stories

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

## Follow-ups (CLAUDE.md TODOS)

- Alignment-based stories: a goofy/annoying interview, bashing the fans, a champion retaining by heelish tactics. Needs face/heel alignment.
- Attendance figures in a show-hyping interview. Needs Popularity.

## Tests

- `PromoStoriesTest`: every standalone situation has 4+ variants; every promo type has stories; only known placeholders; Interview Space won/lost/unbooked (and that every variant of the pool can come up); Main Event Hype title change headline and failed-hype story; Title Match Hype retained with position and the cheap escape; Comeback Reminder names the wrestler and weeks left; Submission specialists win clean finishes by submission.
- Updated `NewsEventsTest`, `PromoTest` (story keys instead of fixed text), `PromoRulesTest`, `EnumValuesTest`, `CatalogSeedTest` (14 promos), `UiTodosTest` and `docs.test.tsx` (Promo stories panel).
