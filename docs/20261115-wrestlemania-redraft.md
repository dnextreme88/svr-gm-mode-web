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
