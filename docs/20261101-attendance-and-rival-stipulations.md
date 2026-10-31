# Fan Attendance, PPV Night First, Shared SummerSlam, Rival Stipulations & "Superstar" Wording

Executed **2026-11-01**.

Mostly fan attendance: every show, and every PPV night, now draws a crowd once it airs. It drives the week's WWE news, a gold ticker line, a gate shown beside each show's results, and who gets SummerSlam's main event. A PPV week now airs the PPV night before the weekly show is booked, and SummerSlam became one card both brands book. The same branch has the rival GM book Winner Takes All and #1 Contender's Match, sets its draft focus to exactly 40%, makes the card's progress bar count saved matches only, and says "superstar" instead of "wrestler" everywhere the player reads it.

## Why

- The original SvR 2006 GM Mode had no attendance. This house rule adds a crowd from what the card has on it. Popularity is still deferred and is a separate system; attendance doesn't use it.
- SummerSlam's main event was promised to "the show with the most fans" in Vince's email, with nothing behind it. Playing it, the player booked their own SummerSlam card without seeing the rival's matches.
- Booking the PPV and the weekly show together meant results that should shape the weekly show (a Champion vs. Contender shot won at the PPV, a new champion) weren't known when it was booked.
- The rival GM booked Champion vs. Contender but not the two stipulations added on 2026-10-31.
- The rival's draft focus roll was 60+ on 1–100, which is 41% of rolls. The intended number is 40%.
- The card's "N of 6 matches booked" bar filled as soon as a slot was filled in, before Save.

## Fan attendance

- **Data:** `shows.attendance` (the crowd) and `shows.attendance_breakdown` (kind, factors, noise, score, gate), migration `2026_11_01_000001_add_attendance_to_shows.php`. A PPV night's cards (brand cards plus the interbrand card) all store the same crowd.
- **When:** `ShowResolver::close` calls `AttendanceService::tally` after the promos and before the promo stories. A weekly show is scored on its own. A PPV night is scored when its last card closes. Win streaks and rivalry heat are snapshots from the start of the week (`AirShows::start`, kept in `WeekContext::data` between live-results requests).
- **Score** (`AttendanceCalculator`, points in `gm.attendance.points`), in the player's priority order:

| Factor | Points |
| --- | --- |
| Full card: Match 1 booked · Main Event booked · every match slot booked | 3 · 3 · 4 |
| Main Event: world title / other title · gimmick · rivals facing each other | 10 / 6 · 5 · 5 |
| Match 1: world title / other title · gimmick · rivals | 7 / 4 · 4 · 4 |
| Hot rivalries: each at 60+ heat whose rivals meet on the card | 10 each, max 20 |
| Legends booked (any role) | 1: 8 · 2+: 15 |
| Promos: share of Interference/Slander that succeeded × 10; with neither, share of the others × 6 | 0–10 |
| Best win streak going in | 5+: 5 · 10+: 10 |
| Seeded noise | ±5 |

- **Crowd:** `min + (max − min) × score / 100`. Weekly 5,000–20,000; brand PPV 10,000–30,000; shared PPV 20,000–50,000. Worked example: 67 → 15,050 on a weekly show; a bare full card scores 10 → 6,500.
- **Gate:** crowd × $30 / $50 / $75. Shown on the results screen; `PayShowIncome` pays gates instead of the flat $250,000 only when `gm.attendance.drives_income` is true (false for now, so the numbers can be checked first). A PPV night's gate is split between its brands.
- **News:** `AttendanceNews` (week step right after `AirShows`) records one `attendance` event (WWE card, 4 variants) for the week's biggest crowd, naming the others in its summary. `NewsComposer` makes it the WWE headline after a PPV main event and a Slander/Interference result, keeps it out of the ticker, and returns the week's crowds as `attendance`. The News ticker leads with them in gold ("Fan attendance: Monday Night Raw 15,050 · Friday Night Smackdown 12,300"). A PPV main event's summary gains "N fans were in the building." (`NewsRecorder::rewrite`).
- **Promo stories:** two new unbooked Interview Space variants quote the crowd (`{attendance}`); they're skipped when a show has no crowd.
- **Results screen:** `AttendanceChip` beside each show's name: "Attendance 15,050 · Gate $451,500" in gold, with the factors on hover.
- Every number is in `config('gm.attendance')` and is an _(assumption)_ to tune.

## SummerSlam main event

`SummerSlamMainEvent` totals each brand's weekly-show crowds this season before SummerSlam week. The bigger draw gets the main event (a tie goes to the total draw score, then a seeded coin flip).

- **One card:** SummerSlam is now a single interbrand card (`ShowScheduler::ensurePpv`), set up like WrestleMania with owned slots (`PpvCardService::setUpSummerSlam`): the bigger draw owns Match 1, Match 3 and the main event (5), the other brand Matches 2 and 4. Ownership is fixed when the week opens (`SummerSlamMainEvent::cardHeadliner` reads it back).
- **Rival pre-booked:** `AdvanceWeek` has the rival book its SummerSlam slots as soon as the week opens, so the player books around them; `PrepareShows` books any it left empty.
- The card screen says who has the main event, and Vince's 2-weeks-out email names who's ahead.

## PPV night first

A PPV week airs in two steps (`WeekResolver::PHASE_PPV` → `PHASE_PPV_DONE` → `PHASE_WEEKLY`, kept in the airing's context):

1. The player books the PPV night (their PPV card, then the interbrand card). Its last card has **Air {PPV}**: `begin()` airs only the PPV cards (`AirShows::ordered` filters on the phase), skipping `TitleShotGuard`, and the rival doesn't book its weekly show yet (`PrepareShows`).
2. When the PPV's last match is in, `proceed()` stops before the closing steps. The results screen shows **Continue to {weekly show}** (`continue_to`), and nothing counts as airing (`awaitingWeekly()`, `GamePresenter`), so the weekly show can be booked. Until the PPV airs, the weekly show is blocked: "Backlash airs first. This show is booked once its results are in." (`BookingOrder::blocker` / `waitsForAiring`).
3. The weekly show's **Air week N** calls `continueWeek()`: the guards run (an earned title shot must be booked), the rival books its weekly show with the PPV results in hand, the weekly shows air, and the week finishes; Continue opens the News.

- `BookingOrder` works per night (`night()`, `nightUp()`): "Continue to …" only moves within a night, the night's last card airs it, an aired card can't be edited or unlocked.
- A Champion vs. Contender shot won at a PPV is due that week (`TitleShotService::create`), on the weekly show booked after it.
- `resolveWeek()` (tests, Simulate to here) still airs a whole week at once; `simulateAll(wholeWeek: true)` carries a paused week on past the PPV, and `WeekSimulator` books the weekly show first.

## Fan attendance chart (Reports)

The Reports page opens with a **Fan attendance** panel (`AttendanceChart`, inline SVG, no new dependency). It plots every aired night of a season over weeks 1–52: Raw's weekly shows as a solid red line with round markers, Smackdown's as a dashed blue line with square markers (so the brands differ by more than colour), PPV nights as gold diamonds, each line labelled at its end. A summary line gives each brand's weekly average and the biggest crowd. Hover or keyboard focus on a point shows its night, crowd and gate; **Show as a table** lists the same numbers. Season buttons appear once there's more than one season. On phones the chart keeps a 560px minimum width and scrolls sideways inside its panel, with the tooltip flipping below points near the top so it isn't clipped. `ReportsController` passes `attendance` from `AttendanceNights` (now with each night's `week`).

## Rival GM stipulations

`AiBooker`:

- `numberOneContender()`: a non-title match whose sides fit one of the brand's titles (one per side for singles, 2v2 / 2v2v2 for tag, all Divas for the Women's title), with nobody holding it and no shot waiting, rolls `gm.ai.titles.number_one_contender_chance` (10%) after the Champion vs. Contender roll. Used in `fillSlot` and for the all-Diva match.
- `bookWinnerTakesAll()`: once per card, after the main event's title roll, with 2+ singles champions fresh enough and their titles not on the card or owed, rolls `winner_takes_all_chance` (weekly 8%, PPV 20%) for a 1v1, or a triple threat at `winner_takes_all_triple_chance` (50%), in a random non-main-event slot.
- Both roll only when someone fits, so cards without candidates use the RNG as before. An earned #1 contendership was already booked as the title match against those contenders (`TitleMatchPlanner`).

## Also in this branch

- **Draft focus:** `gm.draft.ai_focus_from` 60 → 61 (40% of rolls).
- **Progress bar:** `CardBuilder` counts the saved `slots`, not the editor's drafts.
- **"Superstar" wording:** every player-facing "wrestler" became "superstar": column headers (Rosters, Draft, Free Agency, docs), labels and accessible names on the card screen, trade and rivalry rules, `/gm/docs`, toasts (`GameRuleException` messages), promo requirements, emails, news filler and the ledger's upkeep line. Identifiers, routes, columns and `{wrestler}` tokens are unchanged. Already-stored news and email keep their wording.
- `MatchType::isGimmick()` replaces `ShowResolver`'s private copy, so attendance and the news share it.

## Tests

- `tests/Feature/Attendance/AttendanceCalculatorTest.php`: every factor, the worked example, the ranges, the gate.
- `tests/Feature/Attendance/AttendanceTallyTest.php`: crowds after airing, determinism, Legends / hot rivalries / main event, the WWE headline and ticker, flat income vs gates.
- `tests/Feature/Attendance/SummerSlamMainEventTest.php`: leader, tie-breaks, the shared card's slot owners, the rival's pre-booking.
- `tests/Feature/Season/BookingOrderTest.php`: the weekly show waits for the PPV to air, the two-step week end to end (the rival's weekly show booked after the PPV), simulating past a paused week, a PPV title shot due that week. `WeekChipTest`: the chip follows the night.
- `tests/Feature/Ai/RivalStipulationsTest.php`: Winner Takes All and #1 Contender's Match bookings.
- `AttendanceTallyTest` also covers the Reports page's `attendance` data.
- Frontend: `attendance-chart.test.tsx` (summary, axis top, series and diamonds, tooltip, table, seasons, empty), `booking-steps.test.tsx` (Air {PPV}, the weekly show waiting for it, aired cards), `attendance-chip.test.tsx`, the ticker in `news-grid.test.tsx`, unsaved matches off the progress bar in `card-builder.test.tsx`.

## Docs page

`/gm/docs` has a new **Fan attendance** tab (`AttendanceDocs`, numbers from `config('gm.attendance')` via `DocsController`): how a crowd is drawn, the crowd ranges and ticket prices, the draw score's factors, where attendance shows up, and the SummerSlam main event. Booking and Scheduling explains the two-step PPV week; the Rival GM tab adds #1 Contender's Match, Winner Takes All and when it books; Champion vs. Contender's due card, the WWE headline order, the gold ticker line and the show-income note are updated.
