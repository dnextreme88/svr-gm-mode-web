# Longer stories, new emails, PPV logos, rivalry tuning and strategic re-draft tags

## Context

News summaries were mostly 1–3 sentences and emails 2–3 short paragraphs. The story-text rule (3+ sentences, paragraphs of 3–5) only covered new text, and only conditional stories were tested. To make the news and the inbox read like stories, the rule tightens and now covers every news summary, story text and email body, and all old text was rewritten to fit. The same branch adds booking-tip emails, rival GM emails before the shared PPVs, PPV logos, a header attendance stat, rivalry tuning and smarter re-draft tags for the rival GM.

## Story-text rule

- **1–3 paragraphs of 5–8 sentences each** (`config('gm.story_text')`), for news summaries (`NewsTemplates::SUMMARIES`, `PromoStories`, `FallbackStories`), conditional stories (`ConditionalStoryTexts`) and every email body (`EmailTexts`). Headlines, subjects and ticker lines are exempt.
- `App\Support\Text\StoryText` splits paragraphs on a blank line and counts sentences (ignoring `vs.`, `Jr.` and the like, and decimals).
- `tests/Feature/Text/StoryTextRuleTest.php` renders every text with sample values, including summaries with and without their sentence fragments (`{crowd}`, `{others}`, `{budget}`, `{rivalry}`) and the emails that join two pieces.
- Length varies by weight: short notices 1 paragraph of 5–6 sentences, most stories 1–2 paragraphs, big moments 2, and 3 only for the season's tentpoles.
- Rendering:
    - news cards use `whitespace-pre-line`;
    - the story pop-up splits paragraphs;
    - `/gm/docs` keeps the breaks.
- A conditional story added to a news summary is now its own paragraph.
- Every email body moved into `EmailTexts`: Vince's PPV and re-draft emails, the specialty tip and its specialist mention, and the free agent names. The Trainer's report and outlook, and a morale complaint and its level, are separate paragraphs.

## New emails

- **Varied matches** (JR / Tazz, every 8 season weeks): why the fans want a different match category in each slot week to week. When this week's card repeated last week's, a second paragraph says how many slots did.
- **Match 1 and the Main Event** (every 12 weeks from season week 6 _(assumption)_): why the opener and the main event draw, in the commentator's voice.
- **Rival GM, 1 week before each shared PPV**: exactly two variants per situation, polite or arrogant at even odds (`gm.emails.rival_ppv_polite_chance`).
    - SummerSlam: worded by who leads the crowd race (ahead / behind / even, from the rival's side).
    - Survivor Series: its team should win and make it the best brand in WWE.
    - Royal Rumble: its superstars will throw the player's out and take the WrestleMania main event.
    - WrestleMania: it will win the interbrand series and take both franchise tags.
- New `EmailTemplate` cases: `BookingTip`, `RivalPpvTaunt`.

## Rival GM

- **Re-draft tags:** when the rival wins the interbrand series, it hand-picks the player's most valuable eligible non-champion and protects its own most valuable one, valued like a trade (`AiTradeJudge::value`). Ties go to the seeded RNG. Both tags were random before.
- **Rivalries:** no forced reset. The rival used to start at least 4 rivalries whenever it had none, 2 of them by booking fresh pairs. Now it starts the season with none and starts one a week through Start a Rivalry, after the usual 2-week wait.

## Rivalry tuning

- An idle week costs 20 heat (was 5); at 0 or below the rivalry ends.
- The PPV blow-off needs 70+ heat (was 50).
- A Rival Interference finish adds 10 heat to the rivalry between the interferer and the superstar they cost the match (`RivalryService::recordInterference`).
- Active Effects lists every rivalry on the brand with its heat.

## UI

- **PPV logos:** 15 logos in `resources/images/ppvs/{PpvKey}.webp`, trimmed of transparent margins and scaled to fit 384×133 like the brand logos. `<PpvBug>` draws them in:
    - the schedule's PPV cells, in a gold frame;
    - the `/gm/docs` PPV table;
    - the Reports attendance chart's week axis, where the four shared PPVs replace the "Wk N" labels.
- **Header:** the Roster stat is replaced by **Attendance**: the crowd at the player brand's latest aired show.
- **`/gm/docs`:**
    - every email row expands to show every body it can have;
    - every news event shows its other summary variants;
    - the rival rivalry and heat rules are updated.

## TODOs added

- Rework the Save career button.
- Win odds design: specialty, win streak, weight class, minor injury, already booked that week, and a win-chance indicator on the outcome card.

## Verification

- Pest: the new rule test, booking tip and rival PPV email tests, rivalry heat tests, re-draft tag tests, and the updated wording tests.
- Vitest: Active Effects, header, schedule, attendance chart, assets and docs tests.
