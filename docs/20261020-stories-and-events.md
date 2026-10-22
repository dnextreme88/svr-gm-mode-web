# Stories and events: the News page and GM emails

## Context

The News page rarely had anything in the Raw and SmackDown! story cards ("no story this week"), and Newer/Older looked broken. Ordinary match results, most signings and promo outcomes made no brand news. The composer moved a brand's top story up to its headline, so a brand needed 3+ stories before its story cards filled. The archive only listed weeks that had news, so there was usually one page. The inbox only got reactive notices: no welcome, no tips, and a declined trade sent nothing.

The News page should read like a weekly news report: what happened, why, and what's coming. The inbox should sound like SvR 2006, with mail from JR/Tazz, the rival GM, Vince McMahon, the Trainer and the superstars.

## News

### One pool per week, drawn at random

- Everything newsworthy in the week goes into one pool. `NewsComposer::compose()` draws from it with `$save->rng('news.page', $week)`, weighted by priority, so a week lays out the same way on every reload.
- **Headlines:** Raw and SmackDown! each pick from their own Headline-tier events, falling back to their stories (marked `promoted`). The **WWE** (neutral) card only uses cross-brand (`NewsBrand::Both`) events; a Slander or Interference result always takes it.
- **Stories:** 2 random picks per brand from what's left of that brand's events.
- **Ticker:** everything not picked, plus Ticker-tier events, by priority.
- A plain `match_result` is dropped when another event covers the same match (shared `slot:{id}` dedupe key: title change, retained, Champion vs Contender, Last Chance, Loser Leaves, Royal Rumble, rivalry blow-off, interference finish, streak snapped).

### Summaries

- Each card shows its headline plus a short narrative summary (`NewsTemplates::SUMMARIES`, rendered at compose time from the stored payload). Outcome variants (`slander_result.succeeds` / `.fails` / `.backfires`, `last_chance_result.won` / `.lost`, Interference) tell different stories.
- The raw payload table (Brand / Outcome / Rival Brand) is gone. Story summaries are clamped to 2 lines and expand on click.

### New events

| Event | Tier | Where |
| --- | --- | --- |
| `match_result` | Story | Every aired match (`ShowResolver`), under the slot's brand; shared-PPV slots with no owner are cross-brand |
| `main_event_hype_result` | Headline | The main event (the last booked match) after a successful Main Event Hype promo; replaces that match's `match_result` once all the show's promos have resolved |
| `promo_result` | Story | Every promo except Slander, Interference (neutral headlines) and New Superstar (records `debut`) |
| `contract_expiring` | Story | A contract at `gm.news.contract_expiring_weeks` (2) |
| `rivalry_milestone` | Headline | A rivalry active for `gm.news.rivalry_milestone_weeks` (10) weeks with heat ≥ `gm.rivalries.heating_up_threshold`, once |

`free_agent_signed` moves from Ticker to Story.

### Navigation and layout

- The carousel covers every played week from week 1 (quiet weeks show the "no headline/story this week" cards). `NewsComposer::pages()` loads the save's events in one query.
- Headline order is Raw, WWE, SmackDown!.
- The brand ribbon's text was cut off by its slanted edge: `px-2` beat `.gm-chyron`'s right padding. It's now `pl-2` (and `pl-3` on page titles).

## Email

`EmailService::send()` takes an optional sender. Senders live in `config('gm.email_senders')`: JR, Tazz, `rival_gm` per brand, Vince McMahon and the Trainer. Superstar emails use the wrestler's name. Timings live in `config('gm.emails')`.

| Email | From | When |
| --- | --- | --- |
| Welcome | JR/Tazz | Season start (title assignment, and after the re-draft), player's brand |
| Superstar specialties | JR/Tazz | Season weeks 1 and 27; sometimes names one of your specialists (`specialty_mention_chance`) |
| Interference reminder | JR/Tazz | 13 weeks without airing an Interference promo; once per unused stretch |
| Low on cash | JR/Tazz | Budget ≤ $500,000, at most once every 4 weeks |
| Trade answer | Rival GM | Every offer, accepted or declined, with one superstar per line |
| Slander reaction | Rival GM | After your Slander: furious on success, laughing on failure |
| Shared-PPV reminder | Vince McMahon | 2 weeks before SummerSlam, Survivor Series, Royal Rumble and WrestleMania |
| Re-draft reminder | Vince McMahon | After WrestleMania |
| Injury report | Trainer | Every injury or escalation: who, what, severity, weeks out |
| Morale complaint | Superstar | Only at Unsatisfied or Upset (Content no longer emails) |
| Contract reminder | Superstar | 2 weeks before their contract runs out |
| Return notice | Superstar | 2 weeks before a serious injury heals |

- New `SendReminders` week step (after `SendContractEmails`) sends the scheduled ones.
- The inbox keeps line breaks in email bodies (`whitespace-pre-line`).

## `/gm/docs`: News & email tab

- New tab after Promos. Two cards explain how the news works and how the page is laid out.
- **News events** table, grouped (Matches, Titles, Stipulations, Pay-per-views, Rivalries, Roster and contracts, Promos, Injuries and morale, Brand battle). Each row shows the event, which card it lands on (Raw / SmackDown!, WWE, or either), how it plays (Headline / Story / Ticker), and its headline and summary templates with the placeholders highlighted.
    - Group, card and tier live in `App\Services\News\NewsCatalog`; a test fails if a news event type is missing from it. The templates come from `NewsTemplates`.
    - Upset Win, Ratings War and Ratings Streak are listed as "Not in play yet" (they wait on ratings and Popularity).
- **Emails** table: every GM email, who sends it and when, built in `DocsController::emails()` with thresholds read from config.

## Assumptions

- Rival GM sender names are placeholders (`Raw GM`, `SmackDown! GM`).
- The specialties tip names a specialist 50% of the time.
- Vince's shared-PPV reminder comes 2 weeks out.
- "Great fan feedback" for the rivalry milestone is heat ≥ the heating-up threshold until Popularity exists.
- A declined trade email leaves out "The roster change is effective immediately."

## Deferred (TODO)

- Superstars retiring from the Free Agency pool (would feed news and email).
- SummerSlam's main event going to the show with the most fans (needs Popularity).

## Fixes along the way

- `NewsTemplates::render()` had backspace bytes where `\b` was meant, so "1 weeks" was never fixed to "1 week".
- `MoraleService.php` had a stray Latin-1 byte and wasn't valid UTF-8.
