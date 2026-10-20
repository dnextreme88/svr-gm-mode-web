# Results screen, weekly recovery and CLAUDE.md as the rulebook

## Context

Three requests on the same day as the card booking rework:

- `CLAUDE.md` replaces `docs/20261017-spec.md` as the source of truth for the game's rules. The spec is frozen: it was the build-phase spec and is no longer edited.
- The week's results were two separate screens: a flashing one-at-a-time reel, then a "That's a wrap" summary. They become one screen.
- Fatigue recovery changes to match SvR 2006, and Inactive Superstar gets a fatigue effect.

## Rules changed (house rules, 2026-10-20)

- **Weekly recovery:** every wrestler recovers `gm.fatigue.rest_recovery_bars` (2) at the end of the week, booked or not. Before, only wrestlers who didn't compete recovered.
- **Inactive Superstar:** targets a wrestler who isn't booked anywhere on the card (before: a seriously injured wrestler). On success the target recovers `gm.promos.recovery_bars.inactive_superstar` (2) extra bars at the end of the week. If the target gets booked on the card after the promo, it fizzles ("Booked on the card").
- Only wrestlers in matches or fatiguing promos gain fatigue; recovery applies to everyone. Fatigue never drops below 0. Worked examples:
    - A starts at 30% and wrestles a 50% match: 80%, then 60% after recovery, which is where they start the next show.
    - B starts at 40% and isn't booked: still 40% after the show, then 20%.
    - An unbooked wrestler at 30% with a successful Inactive Superstar: 30% − 20% − 20% = 0%.

## Backend

- New `fatigue_logs` table and `FatigueLog` model. Each row holds one wrestler's fatigue for a week: `start`, `after_shows`, `end` and `recovered_bars`.
    - `AirShows` snapshots everyone's fatigue before the shows air.
    - `RecoverFatigue` applies recovery and writes the log. It upserts per save, week and wrestler, so a retried week doesn't duplicate rows.
- `PromoEligibility`: Inactive Superstar now offers the brand roster minus anyone booked on the card.
- `PromoService`: Inactive Superstar fizzles if its target was booked on the card by air time.
- `ResultsController` sends each show's `fatigue` list: everyone who appeared on the show (matches and promos), in card order, with their log and whether they got the Inactive Superstar rest.
- `DocsController` and `CardController` send `recovery_bars` per promo type.

## Frontend

- `pages/gm/results.tsx`: one screen.
    - The left column lists every result. Show titles are 24px, participants 18px and the match line 14px, and titles use the gold chip.
    - Each result's method label has a chevron (›). It opens that match's outcome card in the right column, sliding in from the side.
    - Under the card: ◀ / ▶ arrows (also ←/→ keys) and an 18px "N / total" counter. The "Press Enter or click to continue" hint is gone.
    - Under that, `FatigueProgress` animates each wrestler on the open result's show: going in → after the show → after recovery, with a Replay button.
    - Continue goes to News.
- `pages/gm/news.tsx`: a Continue button on the right goes to the inbox.
- `FatigueCost` takes `recovers` to draw green bars. Inactive Superstar's recovery shows that way on `/gm/docs` and on the card screen's promo grid.
- `FatigueMeter` segments fade between values.
- `/gm/docs` explains the new weekly recovery rule.

## CLAUDE.md

- The Source of truth section now says the spec is frozen and must not be edited.
- A new **Game rules** section summarizes the spec plus every house rule since: setup and season, contracts and money, card building, match types, promos, fatigue and injuries, outcomes, stipulations, morale, defection and titles, news, and deferred systems.

## Tests

- Backend: `WeeklyRecoveryTest` covers:
    - booked wrestlers recover;
    - unbooked wrestlers recover;
    - the Inactive Superstar bonus, floored at 0;
    - no bonus when it fails;
    - it fizzles when the target gets booked;
    - one log row per wrestler per week.
- `PromoRulesTest` covers the new Inactive Superstar targets. `FatigueInjuryTest` now checks match fatigue through the log.
- `GmScreensTest` checks the results page's fatigue payload.
- Frontend:
    - `results.test.tsx`:
        - chevron, gold title, 24px heading;
        - no Enter prompt;
        - arrows, keys and wrap-around;
        - 18px counter;
        - Continue goes to News;
        - the fatigue stages animate.
    - `docs.test.tsx` checks the green recovery bars.
