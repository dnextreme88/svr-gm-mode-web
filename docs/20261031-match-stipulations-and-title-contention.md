# Match Stipulations & Title Contention

Executed **2026-10-31**. Branch `feature/20261031-match-stipulations-and-title-contention`, stacked on `feature/20261027-factions`.

## Context

Champion vs. Contender only offers the first title a champion holds. The card picker uses `.find` in `stipulationOptions` (`resources/js/lib/titles.ts:139`), and the rival takes the first title in `AiBooker::championVsContender` (`app/Services/Ai/AiBooker.php:441`). The backend already validates and resolves any title through `stipulation_championship_id`.

This work does five things:

- Champion vs. Contender gets a title choice and a stricter rule: the contender side holds no title at all.
- The stipulation cycler becomes a dropdown, with a title modal for Champion vs. Contender and #1 Contender's Match.
- Two new stipulations: **Winner Takes All** and **#1 Contender's Match**.
- New news for each stipulation, and #1 contendership voids with GM notices and a vacancy award/forfeit prompt.
- Randomize, Preset and the rival respect all of it.

**Branch:** `feature/20261031-match-stipulations-and-title-contention`, cut from `feature/20261027-factions` (stacked; its PR targets that branch). Assign and request review from `dnextreme88`. **Plan doc:** `docs/20261031-match-stipulations-and-title-contention.md`, plus a `docs/README.md` entry. Copy this plan into it at the start.

## Rules (decided)

**Champion vs. Contender**

- The match is 1v1 for a singles title or 2v2 for a tag title, with no title on the line.
- One side is exactly the chosen title's holders.
- **The other side holds no title of any kind.** Daivari (World Heavyweight + Cruiserweight) vs Eugene (United States) can't be booked.
- The contender must be eligible for the title and not locked out.
- The title has no pending #1 contendership.
- **One title per match:** the modal picks exactly one. A champion with two titles gets both as choices; Daivari vs Eugene offers World Heavyweight and Cruiserweight, since Eugene is a Light Heavyweight.
- Not offered while a title is on the line (unchanged).
- A contender's decisive win earns the existing next-show shot. A serious injury still delays it.

**Winner Takes All**

- 1v1 or 1v1v1 only, with no title on the line.
- Every participant holds at least one singles title of the brand.
- The Women's title is never contested, so Divas can't be in it.
- **Contested titles:** every singles title the participants hold, excluding tag titles and the Women's title.
- A contested title can't be on the line, or contested, anywhere else on the card.
- A participant locked out of another participant's title (Last Chance) blocks it.
- **Decisive win:** the winner takes every contested title they don't already hold (`TitleService::changeHands` per title).
- **DQ or count-out:** nobody's title changes, and each champion's defense counts.
- SmackDown can host a 1v1v1 (World Heavyweight, United States, Cruiserweight). Raw is effectively 1v1 (WWE vs Intercontinental).

**#1 Contender's Match**

- Any non-title match where nobody in it holds the chosen title.
    - Singles title: every side is 1 wrestler (1v1 up to a 6-man Battle Royal).
    - **The Gauntlet** takes this stipulation for a singles title; it's the only stipulation a Gauntlet can have. The Gauntlet winner becomes the #1 contender. `no_stipulation_families` becomes a per-family allow-list (`gm.match_rules.family_stipulations` => `['gauntlet' => ['number_one_contender']]`); `MatchType::allowsStipulation(Stipulation)` replaces `allowsStipulations()`, and the frontend gets `allowed_stipulations` per type.
    - Tag title: 2v2 or 2v2v2.
    - Women's title: all Divas.
- Every participant is eligible for the title and not Last-Chance-locked out of the current reign.
- The title is held, has no pending #1 contendership and no due Champion vs. Contender shot, and has no other #1 Contender's match on the card.
- **Any win** makes the winning side the #1 contender(s). The win can be a DQ, a count-out or the type's own condition, since no title is on the line.
- **No deadline.** Until it's used, any title match for that title must be the champion(s) vs **exactly** the contenders. Nobody else can be added, so no 2v2v2. Champion vs. Contender for that title isn't offered.
- **Fulfilled** when that title match airs (any result).
- **Voided** when a contender leaves the brand (release, trade, defection, contract expiry, Loser Leaves) or is seriously injured. Each void writes a GM notice (a modal) and a ticker story.
- **Champion vacates the title while a contendership is pending** (serious injury, or any other vacancy, _(assumption: all vacancies, so it's consistent)_):
    - **Player's brand:** a prompt modal offers **Award the title** (to the contender(s), as Awarded) or **Forfeit** (the contendership ends). A guard stops the next week airing until it's answered.
    - **Rival's brand:** awards automatically (`gm.titles.ai_award_contender_on_vacancy`, _(assumption)_).

**Unchanged:** Loser Leaves Show and the Last Chance rules. Last Chance lockouts keep lapsing when the reign ends (`LastChanceService::isLockedOut`).

**"Can't book" in the participant pickers.** Today the server already refuses a title match with a Last Chance loser as challenger (`TitleBookingRules.php:85`), and the title cycler skips such a title. But the wrestler pickers don't know, so the loser can be picked after the title is set and the slot fails on Save.

- **The change:** while a title is on the line in the slot, the competitor pickers grey out anyone who can't challenge for it, with a grey right-aligned **Can't book** note, in the same spot as the gold **Booked** tag.
- **Who gets greyed:**
    - the Last Chance losers locked out of the current reign (`TitleContext.lockouts`);
    - everyone but the contenders when the title has a pending #1 contendership;
    - challengers the title doesn't allow (a Diva for a men's title, a non-Cruiserweight for the Cruiserweight title).
- The champions themselves stay pickable.
- **How:** `CardBuilder` gets `lockouts` and `contenderships` next to `championships`. `competitorOptions` (`card-builder.tsx`) sets `disabled` and `note: "Can't book"`, and `PickerOption` gets a `tone` so the note renders grey (`text-ink-faint`).

## Data (one migration)

- `match_slots.contested_title_ids` json nullable: Winner Takes All titles, computed and stored at booking.
- `match_results.titles_won` json nullable: `[{championship_id, reign_id}]` for Winner Takes All results.
- `title_shots`:
    - add `kind` string: `champion_vs_contender` | `number_one_contender`, defaulting to the former;
    - make `due_week` nullable;
    - add `void_reason` string nullable.
- `TitleShotStatus`: add `Forfeited`.
- `title_vacancy_prompts`: `save_id`, `championship_id`, `title_shot_id`, `week_number`, `status` (pending / awarded / forfeited).
- `gm_notices`: `save_id`, `kind`, `payload` json, `week_number`, `seen_at`.

**Enums** (then `php artisan gm:types`):

- `Stipulation`: add `WinnerTakesAll` ("Winner Takes All") and `NumberOneContender` ("#1 Contender's Match").
- New: `TitleShotKind`, `TitleVacancyPromptStatus`, `GmNoticeKind` (`contendership_voided`).
- `NewsEventType`: add `ChampionSurvivedChallenge`, `WinnerTakesAll`, `NumberOneContender`, `ContendershipVoided`, `ContenderAwardedTitle`.

## Backend

**`TitleBookingRules`** (`app/Services/Titles/TitleBookingRules.php`): `validateStipulation`'s `match` gets the two new cases.

- **Stricter `validateChampionVsContender`:** the contender side holds no title (`TitleService::reignsHeldBy`), and the title has no pending #1 contendership.
- **New `validateWinnerTakesAll`** and **`validateNumberOneContender`**, per the rules above.
- **`validateTitleMatch`:**
    - a pending #1 contendership means the challengers must equal the contenders exactly;
    - "each title once per card" also covers `contested_title_ids`.
- `CardService::book` stores `contested_title_ids` (computed by a new `WinnerTakesAll::contested(Booking)` helper).

**`MatchOutcomeHandler::resolveStipulation`** (`app/Services/Matches/MatchOutcomeHandler.php:176`):

- **Champion vs. Contender:**
    - contender wins: shot created as now; `ContenderEarnedShot` text rewritten to "excited and determined";
    - champion wins (DQ or count-out wins included): new `ChampionSurvivedChallenge` ("dodged a bullet, feeling lucky").
- **Winner Takes All:** on a decisive win, `changeHands` for each contested title plus the title-record checks, then `titles_won` is saved. One `WinnerTakesAll` event replaces the per-title `TitleChange` news. Its variant is drawn with `Rng::weightedPick(config('gm.news.winner_takes_all_variants'))` = `celebrate` 80 / `revenge` 20. Outcome text: "{W} wins the {titles}".
- **#1 Contender:** `TitleShotService::createNumberOne()` (kind number_one, `due_week` null, `champion_ids` = current holders). Records a `NumberOneContender` event with variant `fired_up` / `staredown` / `fans`, weighted equally from config. Outcome text: "{W} become(s) #1 contender for the {title}".
- Pronouns follow the existing title-change convention (he/she/they).

**`TitleShotService`** (`app/Services/Titles/TitleShotService.php`):

- `due()` and obligations: `champion_vs_contender` kind only.
- New:
    - `pendingNumberOne(Save, Championship)`
    - `voidContendership(TitleShot, reason)`, which writes the notice and the ticker news
    - `voidForWrestler(SaveWrestler, reason)`
- `fulfil()` covers both kinds.
- **Void hooks:** where a wrestler leaves the brand (`ContractService::release` and expiry, the trade apply, defection transfer, Loser Leaves) and where a serious injury starts or a minor one escalates (`InjuryService`).

**Vacancy:** `TitleService::vacate` (`:178-181`) keeps voiding Champion vs. Contender shots. For a pending #1 contendership:

- **Player's brand:** creates a `title_vacancy_prompt`.
- **Rival's brand:** awards the title (`TitleService::award` plus a tag team via `ChampionshipAssignment::findOrCreateTeam`) and records `ContenderAwardedTitle`.

**New endpoints and guard:**

- `POST title-prompts/{prompt}/award` and `/forfeit` (a `TitlePromptController`).
- `POST notices/seen`.
- Shared via `ShareGameState` / `GamePresenter`, like `faction_prompt`.
- `TitleVacancyGuard` in `WeekResolver::GUARDS` blocks airing while a prompt is pending. Simulate to here stops on it and shows the prompt.

**Randomize, Preset and the rival:**

- `TitleMatchPlanner::plan` (`app/Services/Card/TitleMatchPlanner.php:42`):
    - skips titles with a due Champion vs. Contender shot;
    - for a title with a pending #1 contendership, only offers it as champions vs exactly the contenders (if they're in the pool and fit), else skips it;
    - lockouts are unchanged (already respected).
- Single-slot Randomize (`CardToolsController::randomize`) proposes the due Champion vs. Contender shot first when the card doesn't have it yet, with the title-shot note. Preset already does this.
- Randomize and Preset still never add stipulations.
- **Rival:** `championVsContender` picks at random among eligible titles and follows the new rules. It doesn't book Winner Takes All or #1 Contender yet (noted in the TODOs). It follows #1 exclusivity through `plan()` and `TitleBookingRules`.

**Presenters:**

- `TitleContextPresenter` adds:
    - `contenderships`: `[{championship_id, contender_ids, contenders}]`
    - `held_titles` per wrestler.
- `CardPresenter`: `contested_titles` (booking, pending, aired), `titles_won`, stipulation title name.

**Config** (`config/gm.php`):

- `news.stipulation_priority`:
    - `contender_earned_shot` 75
    - `champion_survived_challenge` 60
    - `number_one_contender` 85
    - `winner_takes_all` 100
    - `contender_awarded_title` 95
    - `contendership_voided` 30
- `news.winner_takes_all_variants`
- `news.number_one_contender_variants`
- `titles.ai_award_contender_on_vacancy`

Headline tier for Winner Takes All, #1 Contender, contender-won and awarded. Story tier for the champion-survived event and ticker for voided. This way they beat that match's plain result: the existing "result gives way to bigger news from the same match" rule applies, since subjects include the slot's wrestlers.

**News text:** `NewsTemplates` headline and summary per variant key (`winner_takes_all.celebrate` / `.revenge`, `number_one_contender.fired_up` / `.staredown` / `.fans`, the champion-survived event), plus `NewsCatalog` rows. The drift test makes these mandatory.

## Frontend

**Shared picker look:** pull the trigger, panel and row styling out of `WrestlerPicker` (`components/gm/wrestler-picker.tsx:66-153`) into a small `components/gm/picker.tsx` (`PickerButton`, `PickerList`, `PickerRow`). Both pickers use it, so the stipulation dropdown matches exactly.

**`StipulationPicker`** (`components/gm/title-tools.tsx:105`):

- **No options:** a read-only "No stipulation eligible" chip.
- **Otherwise:** a dropdown. The trigger reads "Select stipulation" by default; the first row is "No stipulation". Rows show each available stipulation.
- **Winner Takes All row:** shows its contested titles.
- **Champion vs. Contender and #1 Contender's Match:** open `StipulationTitleModal`, a new component built on `ui/dialog` with `ConfirmDialog` styling:
    - a radio list of eligible titles (belt chip and holder);
    - Confirm sets `stipulation` + `stipulationChampionshipId`; Cancel keeps the previous choice.
- **Trigger label once chosen:** "Champion vs. Contender: World Heavyweight".

**`lib/titles.ts`:**

- `stipulationOptions` mirrors the rules above and returns `{ value, label, titles?: TitleOption[], contested?: TitleOption[] }`.
- `titleCandidates` applies #1 exclusivity and keeps the title on a slot with exactly the contenders.
- `TitleContext` type gains `contenderships` and `held_titles`.

**Card page:** the "Required title match" alert also lists #1 contenders who are still waiting ("Hardys await a WWE Tag Team title match"). Every Winner Takes All participant, and the Champion vs. Contender champion, gets the gold **Champion** tag.

**Results and outcome card:** Winner Takes All shows its contested title chips and what the winner won. Stipulation outcomes use the new texts.

**Title shot reminder modal:** when the contender won a Champion vs. Contender match, the card the shot is due on (the next show or PPV, wherever `TitleMatchPlanner::obligations` puts it) opens with a modal. A champion win creates no shot, so no modal.

- **Text:** "Kane earned a WWE Championship match by winning a Champion vs. Contender match. It has to be on this card before the week airs."
- **Book it now:** proposes the singles or tag title match into the first empty slot (or the last slot when the card is full) through the Randomize proposal flow, so it can be reviewed and confirmed.
- **Later:** closes it for this visit. It shows again on the next visit until the match is booked. The existing alert and `TitleShotGuard` stay.
- **Data:** the card page already has the shots in `title_context.title_shots` (due, `booked` flag).
- **Component:** `components/gm/title-shot-reminder.tsx`.
- The rival's shots never show it.

**Modals:**

- `TitleVacancyModal`: Award / Forfeit, then a confirmation, in the `faction-prompt-modal.tsx` pattern.
- `NoticeModal`: lists unseen notices with an OK button. Both are mounted in `gm-layout.tsx` beside `FactionPromptModal`.

## Docs

- **CLAUDE.md:** the Stipulations section gets all of the rules above, the void/vacancy rules, and the picker behaviour. TODO: the rival booking Winner Takes All / #1 Contender.
- **`/gm/docs`:** `stipulationRules` entries for the 2 new stipulations and the updated Champion vs. Contender. The News & email tab picks up the new events through `NewsCatalog`. Ask before finishing, per CLAUDE.md.
- **Plan doc:** filled in as the work lands.

## Tests

**Pest** (new `tests/Feature/Titles/StipulationTitlesTest.php` plus updates):

- **Champion vs. Contender:**
    - Daivari (World Heavyweight + Cruiserweight) vs Eugene, non-title First Blood: both titles bookable, one at a time.
    - The same with the World Heavyweight title on the line: no Champion vs. Contender.
    - Eugene holding the US title: refused.
    - Contender win: shot plus the "determined" news. Champion win: the "lucky" news.
    - Rival random title choice.
- **Winner Takes All:**
    - Allowed: 1v1 and 1v1v1 with all-champion participants.
    - Refused: a non-champion, a Diva or the Women's title, 2v2, a contested title already on the card.
    - A decisive win moves every contested title.
    - A DQ moves none.
    - News is 80/20 over many seeds.
- **#1 Contender:**
    - Allowed shapes: 1v1, 6-man Battle Royal, Gauntlet (the winner becomes contender; no other stipulation allowed on a Gauntlet), 2v2, 2v2v2, all-Diva Women's.
    - Refused: the champion in the match, a pending contendership.
    - Any win creates the contendership.
    - After that: a title match with anyone else is refused, and a 2v2v2 is refused. The exact match fulfils it.
    - Voids: release, trade, defection, contract expiry, Loser Leaves, serious injury. Each writes a notice.
    - Vacancy with the player: a prompt that blocks airing, then award or forfeit.
    - Vacancy with the rival: an auto-award.
    - News variants roughly equal.
- **Randomize / Preset** (`CardRandomizerTest`):
    - Never books a locked-out challenger: Jeff Hardy retains the World Heavyweight title vs Vito in Last Chance, so no proposal has Vito challenging; after Jeff loses the title, Vito can be proposed.
    - Never books a #1 title with other challengers.
    - Single-slot Randomize books a due shot.
    - Never adds stipulations.
- **Updates:** `StipulationTest`, `RivalBookingTest`, the `NewsCatalog` drift test, the `gm:types` drift test.

**Vitest:**

- `lib/titles.test.ts`: the scenarios above. Replace the three arrow tests in `title-tools.test.tsx` with dropdown and modal tests.
- `card-builder.test.tsx`:
    - With Jeff Hardy's World Heavyweight title on the line, Vito (Last Chance loser) is greyed with "Can't book" and the champion isn't.
    - Once the title is removed, Vito can be picked again.
    - With a pending #1 contendership, only the contenders can be picked.
- Title shot reminder:
    - Shows on the card with an unbooked due shot.
    - Hidden once it's booked, and when there's no shot (a champion win).
    - "Book it now" opens the proposal; "Later" closes it.
- Results: Winner Takes All chips.
- `TitleVacancyModal` and `NoticeModal`.
- Docs: the new stipulation rules.

## Verification

- `composer ci:check`: Pint, PHPStan 7, `vp check`, `tsc`, Pest and Vitest.
- `php artisan gm:types`, `wayfinder:generate --with-form`, `migrate:fresh --seed`.
- **Browser** (preview `laravel`), demo career scripted in the scratchpad:
    1. Give Daivari two SmackDown titles; book Daivari vs Eugene with Champion vs. Contender and pick each title in the modal.
    2. Book and air a Winner Takes All 1v1v1; the results show the titles moving, and the news shows the event.
    3. Air a #1 Contender's Match; the next card only allows that title match with the winners.
    4. Seriously injure the champion; the vacancy prompt appears and Award works.
    5. Check the dropdown at 375 / 700 / 1100 px.

## What landed

- **As planned.** Notes from building it:
    - `TitleContention` (`app/Services/Titles`) answers who holds what; `ContendershipService` creates, voids, awards and forfeits #1 contenderships; `PlannedFatigue` stays as before.
    - The `#1` void reason list gained `redrafted` (the WrestleMania re-draft moves wrestlers between brands).
    - `TitleService.php` had one stray Latin-1 `§` byte (from before this branch); it's UTF-8 now.
    - "Book it now" on the title shot reminder books the owed match straight away (Randomize proposes the owed shot first for that slot) rather than opening the review modal.
    - The rival's prompts are settled in the `SettleTitleVacancies` week step, after `TitleUpkeep`.
- **Tests:** `tests/Feature/Titles/StipulationTitlesTest.php` (17: the Daivari/Eugene Champion vs. Contender cases, Winner Takes All moves and DQs and its 80/20 story, #1 Contender shapes, exclusivity, fulfilment, voids with notices, the vacancy prompt and rival auto-award, the Jeff Hardy/Vito Last Chance lockout in Randomize). Vitest: `title-tools.test.tsx` (dropdown and title modal), `lib/titles.test.ts` (#1 Contender shapes, Winner Takes All, exclusivity), `card-builder.test.tsx` (Can't book), `title-modals.test.tsx` (reminder, vacancy, notices).

## Also in this branch

- **Schedule animation:** the month arrows slide the calendar (the month name and the day grid): Next up from below, Previous down from above, never on first load (`animate-gm-slide-from-below` / `-above` in `gm.css`; reduced motion turns it off).
- **Schedule hovers:** a clickable show hovers in its brand's colour, Raw red and Smackdown blue (`hoverFor` in `schedule.tsx`); PPVs stay gold. The rival's show this week is no longer highlighted, only the player's own cards.
- **Gauntlet's place:** the Gauntlet rows moved to right after Fulfill Your Fantasy in `match_types.php`, so the match type picker and `/gm/docs` list it there.
- **Roster changes:**
    - Gerald Brisco is a Light Heavyweight.
    - Jerry Lawler is a Light Heavyweight Legend (he was a Heavyweight free-agent Superstar).
    - Mike Knox, Miss Jackie and Superstar Billy Graham are gone from the roster.
    - New Hardcore specialists: Hardcore Holly, Rhino, Bubba Ray Dudley and D-Von Dudley (who keep Table), and Bam Bam Bigelow.
    - The Blue Meanie specialises in First Blood and Hardcore.
    - 160 wrestlers (37 Legends, 48 free-agent Superstars, 15 free-agent Divas); 44 specialties across 33 wrestlers.
