# WrestleMania re-draft rework

## Context

The old re-draft made up to 10 random picks per brand from the rival's non-champions and skipped any pick that would take a brand past 30. With "Use WWE roster" both brands start at 30, and the rival fills to 30, so the re-draft usually moved nobody and wrote no news.

## Rules (house rule, 2026-11-15)

- Each brand drafts **5** superstars from the other (`gm.ppv.redraft_picks_per_brand`), alternating. Every pick is a swap, so no roster cap applies. Picks are random from the other brand's non-champions: every champion (singles or tag) is exempt, and injured superstars, minor or serious, stay in the draft. Both tags only take non-champions.
- **Immunity tag:** the brand whose weekly shows drew more fans this season before WrestleMania week (`SummerSlamMainEvent::totals`; ties: draw score, then a seeded coin flip). Stored on `interbrand_series.immunity_brand`. It protects one of its own non-champions.
- **Manual draft pick:** the interbrand series winner. It picks first and chooses that first superstar (confirmed in a modal). The protected superstar can't be taken.
- The rival GM tags its most valuable superstars (`ReDraftService::mostValuable`).

## Flow

1. WrestleMania week closes into the re-draft (`SaveStage::Redrafting`). The News page redirects there until it's done, so the results' Continue lands on it.
2. `/gm/saves/{#}/redraft` (`RedraftController`, `resources/js/pages/gm/redraft.tsx`, laid out like Propose Trade): immunity tag → manual pick → **Start the re-draft**, which steps `POST redraft/step` and animates each pick (scroll, highlight in the picking brand's colour, move across; Skip animation).
3. **Continue** (`POST redraft/finish`) records the `redraft_results` WWE headline for WrestleMania week and opens the News.

## News

- `redraft_results` (new, WWE headline, priority 100): 3 paragraphs: the picks (Raw, then Smackdown), the tags (crowd race, the protected superstar, each interbrand series match, the series score and the manual pick), and the season closing.
- Each brand's card leads with its last WrestleMania match (the main event for the brand that had it), from `headline_slots` in the payload (`NewsComposer::leadingMatch`).
- `redraft_pick` is now a ticker line; `season_reset` is no longer written.

## Also

- Vince's re-draft email describes the new rules.
- `composer demo` (`migrate:fresh --seed`, then `php artisan gm:demo-saves`) builds three test careers on Smackdown: WrestleMania week with Matt Hardy (Smackdown) or Jeff Hardy (Raw) the Royal Rumble winner, and season 2, week 1. It replaces `gm:demo-save`.

## Tests

`tests/Feature/Ppv/PpvRulesTest.php` (re-draft block), `resources/js/pages/gm/redraft.test.tsx`, the docs page test.

## WrestleMania card (house rule, 2026-11-15)

- **8 matches** (was 10): the Royal Rumble winner's brand books Matches 1 and 3, the other brand 2 and 4; the interbrand series is Matches 5–7 (1v1, 2v2, Money in the Bank) and the main event is Match 8.
- **Series entrants:** no more random draw for the player. The rival GM picks its most valuable bookable non-champions when the card is set up; the player picks theirs with the match's own superstar pickers (VACANT until filled) and **Save** (`PUT shows/{show}/series/{position}`). WrestleMania can't continue or air while a spot is empty. When a week resolves without the player picking (Simulate to here, tests), empty spots are filled at random.
- **Main event match type:** the brand with the main event picks a one-on-one type the title can be on the line in (`PUT shows/{show}/main-event-type`); the rival draws one that keeps both competitors at 70% fatigue or less.
- **Colours:** interbrand cards colour each match's ribbon by its owner (Raw red, Smackdown blue, shared gold), and series sides get a brand border.
- Tests: `tests/Feature/Ppv/WrestleManiaCardTest.php`, plus the WrestleMania cases in `PpvRulesTest`.

## Shared PPV cards (house rule, 2026-11-15)

- SummerSlam, the Royal Rumble and Survivor Series are each one 7-match card both brands book (the brand-only Rumble and Survivor Series cards and their one-match interbrand cards are gone, so the results show one card).
- The bigger weekly-show draw before the PPV gets: SummerSlam 1, 3, 5 and the main event (7); Royal Rumble 1, 2 and 6 (the Rumble is Match 7); Survivor Series 4, 5 and 6 (the 3 vs 3 is Match 7). The other brand gets the rest.
- The rival books its slots when the week opens; the Rumble and 3 vs 3 entrants are picked from the slot's button on the card.
