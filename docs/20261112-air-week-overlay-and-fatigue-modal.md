# Air Week Overlay, Fatigue Modal, One-off Buttons, Draft Room & PPV Week Flow

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

### PPV week, one night at a time

- **PPV night fatigue:** after Air Backlash, the fatigue card said "Nobody wrestled or cut a promo on this show". The week's fatigue is logged (`RecoverFatigue`, `FatigueLog`) only once the week is over, so a PPV night had no rows.
    - `ResultsController` now builds each superstar's fatigue so far from the airing's `fatigue_start` and their current fatigue, with `end` null.
    - `FatigueProgress` then shows two stages (Going in, After the show), any injury beside the fatigue, and "Everyone recovers at the end of the week, once the weekly show has aired."
- **No card switcher:** the list of the week's cards above the card ("1 Backlash → 2 Monday Night Raw") is gone. Continue moves between a night's cards.
- **Weekly show redirect:** on a PPV week, the weekly show's card redirects to the PPV night's card still to book until the PPV has aired (`CardController::show`). The flow:
    1. book the PPV;
    2. air it;
    3. Continue opens the weekly show;
    4. air it;
    5. the News.
- **Night toggle:** while the weekly show aired, the results listed the PPV's matches above it. A PPV week's results now have a toggle ("Backlash" / "Monday Night Raw") that shows one night at a time. The list, the outcome card's arrows and its count stay within that night. It starts on the night of the match open and follows the card as it moves on. Weeks without a PPV have no toggle.
- **The rival's PPV:** a week with the rival's brand-only PPV (Backlash for a SmackDown player) was still split into a PPV night. The player got an empty "That's a wrap" with Continue to their weekly show. Only a PPV the player has a card on now splits the week (`WeekResolver::begin`). The rival's PPV airs, simulated, with the weekly shows, and the player goes straight to their weekly show.
- **Interbrand cards:** the slots an interbrand card gives the rival (`owner_brand`: SummerSlam's Matches 2 and 4, WrestleMania's rival brand slots) simulate on their own when their turn comes (`WeekResolver::proceed`, `isRivalSlot`). The player decides their own slots and the shared ones: WrestleMania's interbrand series and main event, the Royal Rumble, Survivor Series.
- **On air:** while a night airs on the results screen (`airing`, or `continue_to` after a PPV night), the GM header and menu bar are hidden. Results of past weeks keep them.

### Header until the News (2026-11-13)

Once the week's last match was in, closing the fatigue modal brought the header back, because the week was no longer airing. `ResultsController` now sends `wrapping_up`: the latest resolved week, opened without `?show` or `?match`. `GmLayout` keeps the header and menu hidden for it, so they return only on the News. Results opened from the Schedule or a title's history still show them.

### Night toggle placement and Simulate all (2026-11-13)

- The night toggle moved above both columns, so the list and the outcome card start level.
- **Simulate all** shows only while the night on screen still has matches to decide.
    - It used to show while you looked at the aired PPV night (SummerSlam), which read as re-running that card.
    - The server never re-aired it: `simulateAll` only airs cards that haven't aired.

### Chyron flash (2026-11-13)

The page title's kicker ("Week 13 · Weekly show") flashed a square box for a frame, like the slanted buttons did. Chyrons cut their slant with a `clip-path`, inside the title's clip-path wipe-in. `.gm-chyron` and `.gm-chyron-both` now paint the shape on `::before`: a solid fill plus a diagonal hard-stop gradient for each slanted edge. The colour comes from `--gm-chyron-bg`; the default is the brand, and gold, translucent gold and white tints are set per use. This covers the kicker, the match labels, the news ribbon, the week chip and the outcome tags.

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
- `BookingOrderTest`:
    - a PPV night's fatigue so far, with `end` null;
    - the weekly card redirects to the PPV until it has aired, then opens.
- `BookingOrderTest`: a rival's brand-only PPV airs with the weekly shows in one step.
- `SummerSlamMainEventTest`: on SummerSlam the rival's Match 2 simulates on its own and the player's Match 3 is next.
- `booking-steps.test.tsx`: no card switcher.
- `gm-layout.test.tsx`: the header and menu are hidden while airing, after a PPV night and once the week is in (until the News), and kept on past results.
- `LiveResultsTest`: `wrapping_up` once the week is in, but not from the Schedule.
- `results.test.tsx`: two-stage fatigue before recovery; the night toggle (starts on the airing weekly show, switches to the PPV, absent without a PPV).
- `results.test.tsx`:
    - the modal opens when the last match is in, with the card pending;
    - Got it fills the card;
    - aired results show the card with no modal.
