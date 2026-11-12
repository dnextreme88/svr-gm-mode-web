# Air Week Overlay, Fatigue Modal, One-off Buttons & Draft Room

## Context

Two gaps on the way from the card to the results:

- After confirming **Air week**, the card stayed clickable while the request ran, so a match could be changed mid-air.
- The live results screen filled the **Fatigue this week** card as soon as the last match was decided, so it was easy to miss.

## Changes (house rule, 2026-11-12)

### Air week overlay

- Confirming **Air week N** or **Air {PPV}** puts up the same `<LoadingOverlay>` as **Start the season** ("Airing week 4. Please wait...", "Airing Backlash. Please wait...").
- The overlay covers the page, so nothing on the card can be clicked.
- The request goes out after `loadingOverlayDelay('air-week')`: 2.5 seconds the first time a player sees it, then 0.8 seconds (`LOADING_OVERLAY_DELAY_MS`, `LOADING_OVERLAY_REPEAT_MS`).
- File: `components/gm/air-week-button.tsx`.

### Fatigue modal

- When the last match of a night is decided on the results screen (`airing` goes from true to false), a **Fatigue this week** modal opens. This applies to the PPV night and to the weekly shows.
- It holds only the card's content: the three stages (Going in, After the show, After recovery), the superstars' rows and **Replay**.
- It lists everyone who worked the night, once each (the fatigue logs are the week's).
- It waits for any conditional story pop-ups.
- **Got it** closes it.
- Until then the card shows "Your superstars' fatigue shows here once you close the fatigue report". Once the modal closes, the card fills and animates as before, Replay included.
- Results opened after the night aired show the card directly, with no modal.
- `FatigueProgress` gains `framed` (default true; false drops the panel for the modal). `ConfirmDialog` gains `wide`.
- Files: `pages/gm/results.tsx`, `components/gm/fatigue-progress.tsx`, `components/gm/confirm-dialog.tsx`.

Nothing was removed: the card, its Replay and the live results flow are unchanged.

### One-off buttons on the new game page

The button audit had missed `/gm`. A new rule: major buttons pressed only once in a playthrough that move on to another page show the same loading overlay and pause as **Start the season**.

- **Start the draft:** the who-picks-first reel plays first, as before, while the career is created. Once it lands, "Setting up the draft. Please wait..." covers the page for the pause, then the draft room opens.
- **Play with WWE Roster:** "Signing the WWE rosters. Please wait...". The overlay stays up until the champions page opens.
- Both use `loadingOverlayDelay('new-game')`. The submit button is disabled and `aria-busy`. A failed request takes the overlay down and shows the error under the save name.
- The page's other buttons already had loading states: Delete and Discard use `useBusy` in their confirmation. Resume and the saved careers are plain links.
- File: `pages/gm/saves/index.tsx`.

### Champions page title steps

- Each step starts with its belt (`TitleBeltImage`), in a fixed-width box so the names line up.
- The title being decided swaps its gold border for the Schedule's current-week border (`gm-current-week`): the brand-colour glow and the sweeping arc. The style is reused, not copied.
- File: `pages/gm/titles/assign.tsx`.

### Draft room

- **Brand cards:** the four stat cards (On the clock, Your picks, Budget, the rival's picks) became two equal brand cards, Raw left and Smackdown right (`BrandDraftCard`). Each card shows:
    - the brand's logo;
    - picks and the running budget on one row (budget green, red below 0, as in the header);
    - the animated turn line ("Your pick" / "Rival GM drafting…"), only on the brand drafting now, which glows.
- **Budgets:** `DraftController::show` now sends both brands' budgets (`budgets`), so the rival's shows from the start.
- **Pick order:** "Pick N · Order: …" moved under the Build your roster headline.
- **Even heights:** from `xl` the pool and the Draft Picks column share one height (`xl:h-[72vh]`), and each list scrolls inside it. All picks fills the column; By show splits it evenly between the two rosters. Before, the picks list ended a little short, and By show ran longer once the rosters filled.
- **Random:**
    - Sits just before Skip animation and uses the Preset card's look: a ghost button with the dice, which rolls while it works, and "Drafting…".
    - On the player's turn, `POST draft/random` (`DraftController::random`, `DraftService::randomPick`) draws one superstar or Diva the player can afford from the pool. It uses the seeded RNG (stream `draft.random.{brand}.{n}`) and records the pick with `pick_type` random. Legends can't be drafted.
- **New game reel order:** **Start the draft** plays the who-picks-first reel first, then the "Setting up the draft" overlay, then the draft room.

## Verification

- `booking-steps.test.tsx`: Air week and Air {PPV} show the overlay, then post after the pause.
- `saves/index.test.tsx`: Start the draft plays the reel, then the overlay, then opens the draft; Play with WWE Roster shows the overlay, then the next screen.
- `assign.test.tsx`: only the title being decided has the animated border.
- `draft.test.tsx`:
    - the brand cards (Raw first, picks, budgets, turn line on the brand drafting);
    - the order line;
    - Random (busy while it works, disabled on the rival's turn).
- `DraftTest`:
    - the Random button drafts an affordable, draftable superstar as a random pick;
    - the same seed draws the same pick;
    - Random is refused when it isn't the player's turn.
- `results.test.tsx`:
    - the modal opens when the last match is in, with the card pending;
    - Got it fills the card;
    - aired results show the card with no modal.
