# Royal Rumble record, rival Rumble entrants, rival draft lengths and fixes

## Context
The superstar profile's Special info had no Royal Rumble entry, and the Rumble itself only stored a winner (one 30-way result), so there were no entry numbers or eliminations to show. Two bugs also turned up while building the demo careers: an aired SummerSlam couldn't be opened from the Schedule, and a superstar could hold a title while seriously injured.

## Royal Rumble record (house rule, 2026-11-15)
- When the Rumble's result is in (booked or simulated), `RoyalRumbleLog` writes one `royal_rumble_entries` row per entrant: entry number, eliminations, who eliminated them, elimination order and whether they won. It runs on its own RNG stream (`rumble.{show}`), so no other result changes.
- **Entry numbers:** the entrants are shuffled once and numbered 1–N in that order, so every number is used exactly once (1–30, fewer when a brand sends fewer than 15). The order is random and seeded: the same career always gets the same numbers. It doesn't affect who wins.
- **Eliminations:** entrants come in one by one. After each arrival someone in the ring is thrown out by someone else in the ring at `gm.ppv.rumble.elimination_chance` (0.6), and always while more than `ring_cap` (6) are in *(assumption: both)*. The winner and the superstar the finish text names stay to the end, and the winner eliminates them last.
- The winner is decided as before: equal odds per entrant plus a Royal Rumble specialty bonus (nobody has one yet), or Book to Win on the live results.

## Special info: Royal Rumble table
`/rosters/{#}` opens Special info with a Royal Rumble table (`SuperstarCareer::rumbles`, prop `royal_rumbles`): Week (season and week, with the PPV), Entrant ("#19 of 30"), Eliminations, Won ("Yes", or "No · by {who eliminated them}"). The week links to the match's results.

## Rival GM's Rumble entrants
`PpvCardService::aiEntrants`: 15 non-champions at random. Only with fewer than 15 non-champions does it add champions, first those not booked on its other cards of the Royal Rumble night, then those who are. `PrepareShows` now sets entrants after the rival's other cards that night are booked. Survivor Series teams stay random.

## Rival draft contract lengths
In the initial draft the rival signs each pick for a random 1, 3, 6, 9 or 12 months (`AiGm::draftLength`, its own stream per pick, so who it picks doesn't change), and only considers superstars it can afford at that length.

## Fixes
- **SummerSlam on the Schedule:** SummerSlam has been one shared card with no brand since 2026-11-01, but the Schedule only looked for a brand-less card at WrestleMania. A PPV night now opens the brand's own card, else the shared one, so an aired SummerSlam opens its results and SummerSlam week opens its card.
- **Seriously injured champion:** injuries are rolled before a title changes hands, so a winner seriously hurt in the same match kept the title. `ShowResolver::airMatch` now re-checks the winners after the result (`InjuryService::vacateIfInjured`) and vacates with the reason `injured`.

## Demo careers
`composer demo` (`migrate:fresh --seed`, then `gm:demo-saves`) builds three Smackdown careers: WrestleMania week with Matt Hardy (Smackdown) or Jeff Hardy (Raw) as the booked Royal Rumble winner, and season 2, week 1. It heals the Hardy and the world champion he faces, so the main event is playable.

## Tests
`tests/Feature/Ppv/RoyalRumbleRecordTest.php` (record, profile, rival entrants, draft lengths), `tests/Feature/Matches/FatigueInjuryTest.php` (title won while seriously injured), `tests/Feature/Attendance/SummerSlamMainEventTest.php` (Schedule link).
