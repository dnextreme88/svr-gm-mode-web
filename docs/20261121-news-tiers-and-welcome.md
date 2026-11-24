# News Tiers, Re-draft Picks Headline & Season Welcome

**Executed:** 2026-11-21

## Why

The News page's tickers only list match results now (house rule, 2026-11-17), so news recorded at the Ticker tier was never shown, and `/gm/docs` still listed it as "Ticker". Season 1, week 1 also opened on quiet-week filler.

## Rules

- **No ticker news.** Nothing is recorded at the Ticker tier any more.
    - Losing Streak, Contendership Voided, Contract Demand, Title Demand and Morale Unrest are brand **stories**.
    - **Locker Room Note** is a brand story: the second empty story slot gets the locker room (Unsatisfied or Upset superstars, else contracts about to run out) ahead of the injury report, which fills it when the locker room has nothing to report. The "all quiet" note is gone.
    - **MITB Expiring** is a **WWE headline** the week after the Royal Rumble, while a briefcase is still held (it runs out at WrestleMania). It leads the WWE card right after the PPV headlines, before a Slander or Interference result (`gm.news.mitb_expiring_priority`).
    - **Re-draft Pick** is one WWE headline written when the re-draft ends, always the WWE headline after the WrestleMania re-draft and before week 1 of the next season. It replaced the Re-draft Results headline and the ticker line per pick (`redraft_results` stays only for old news).
- **Welcome to Smackdown vs Raw** (`welcome`): Vince McMahon's WWE headline, always and only in season 1, week 1, in 2 paragraphs, so the season never opens on filler.
- **Upset Win merged into Streak Snapped.** Upset Win was never switched on (it waited for overall ratings). An upset is now someone beating a superstar on a 10+ win streak: Streak Snapped's headline, with an upset summary. The `upset_win` event type and its `upset_win_overall_margin` setting are removed.
- **News events table on `/gm/docs`:** a Priority column (from `NewsCatalog::priorities()`, which reads the same config the game does, with a note where a priority varies), sorted by priority or event name, and filters for category and card, all kept in the URL. Losing Streak, Contract Demand, Title Demand and Morale Unrest were recorded at priority 0; as stories they now rank at 20, 20, 20 and 25 (`gm.news.morale_priority`).
- The `*.legacy` news templates (old wording for news recorded before a payload carried `via` or `pronoun`) are removed.

## Changes

- `NewsCatalog` (the `/gm/docs` News & email "Plays as" column), `NewsTemplates`, `FallbackStories` (locker room story texts), `NewsComposer` (welcome, re-draft and MITB precedence), `QuietWeekNews`, `MitbService::expire`, `ReDraftService`, `MoraleService`, `ContendershipService`, `ShowResolver`.
- `NewsEventType::Welcome` (regenerated `enums.ts`).
- `/gm/docs`: the quiet-week card and the morale panel no longer mention a ticker.
- Tests: no event is recorded or documented as Ticker; the welcome, the MITB headline after the Rumble, the locker room story and the single re-draft headline.
