# PPV night News, WrestleMania season end and re-draft polish

## Context

A PPV week only showed its news once the whole week had aired, so the weekly show was booked without the PPV's news in front of the player. WrestleMania week also still had weekly shows after the PPV, which put the re-draft and the new season a step later than the night that ends the season.

## PPV night News (house rule, 2026-11-16)

- **No PPV:** unchanged. The weekly shows air, then the News.
- **A PPV the player books** (their brand's PPV or an interbrand one): once the PPV night's last match is decided, the results' Continue opens the News. The week's page shows only what happened since the night began (`news_after_id` stored on the airing when `WeekResolver::begin` starts it; `NewsComposer::ppvNightNewsFrom`), so a signing made earlier in the week still waits for the week's end. The News's Continue reads "Continue to Monday Night Raw" and opens the weekly show to book. After the weekly shows air, the News shows the same week again, redrawn with everything.
- **The rival's brand-only PPV:** unchanged. It airs with the weekly shows, and the News after the week leads with it on the WWE card.

## WrestleMania ends the season (house rule, 2026-11-16)

- `ShowScheduler` gives WrestleMania week no weekly shows. When the PPV night ends and the week has no weekly show (`WeekResolver::hasWeeklyShows`), the week closes there: results → re-draft → News.
- The News after the re-draft continues to the Schedule (`?welcome=1`), which opens a **Welcome to Season N** modal (`welcome_season`), shown once per browser.

## Re-draft page

- The immunity tag is confirmed in a modal, like the manual pick.
- Each random pick spins a slot-machine reel (`SuperstarReel`) in the picking brand's colour through the other brand's draftable superstars, stops on the drawn one and closes, then the existing scroll-and-move plays. The manual pick has no reel; Skip animation skips it.
- The GM header and menu bar are hidden on the page.
- Every re-draft route returns the 404 page outside a running re-draft (`RedraftController::assertRunning`).

## Superstar profile

Special info adds a **Re-draft** table (`SuperstarCareer::redrafts`, prop `redrafts`): From and To brand logos, Season (with the WrestleMania logo; the season whose WrestleMania it followed) and Pick as that brand's pick out of 5 ("3/5"), marked for the manual draft pick. The old one-line "Re-drafted" moments are gone.

## Tests

- `tests/Feature/Week/PpvNightNewsTest.php`: the PPV night's News and its Continue, WrestleMania week's shows, and WrestleMania → re-draft → News → welcome.
- `PpvRulesTest`: the 404 gating and the profile's re-draft rows.
- Frontend: the reel, the immunity confirmation, the hidden header.
