# Calendar schedule, booking order and simulate to a date

## Context

In a PPV week only the weekly show got booked: the week's Air button aired everything, and the PPV card was never visited. The calendar also had each week end on its Sunday, so Monday Raw aired before that week's PPV. The Schedule was a list of weeks with "Results" links that only covered the player's shows. Getting to a later date on a fresh career meant booking every week by hand.

## Rules changed (2026-10-21)

- **Weeks start on Sunday.** PPVs air Sunday, then Raw Monday and Smackdown Friday (`CalendarService::showDate` now looks forward from the Sunday). Shows air in that order: brand PPV cards, the interbrand card, then the weekly shows.
- **One continuous calendar, April 2005 → March 2006**, every season. The Jan–Mar dates no longer fold back onto 2005: New Year's Revolution is Jan 8, Royal Rumble Jan 29, No Way Out Feb 19 and WrestleMania Mar 26, 2006.
- **Booking order.** The player books a week's cards in date order: their brand's PPV card → the interbrand card (Rumble/Survivor Series entrants, or WrestleMania) → the weekly show.
    - "Continue to …" (confirmation) sets `shows.confirmed_at` and opens the next card. A confirmed card is read-only until it's unlocked (which reopens every later card too).
    - The weekly show's **Air week N** (confirmation) airs every card of the week. The server refuses to air until every earlier card is confirmed.
    - The results list the week's shows in air order (PPV first), with the week's fatigue.
- **Schedule calendar.** A Sunday-first month view (title + ▲/▼ month arrows, like the Windows calendar), opening on the current month. Both brands' shows are on it.
    - An aired show opens its results, the rival's included (`results/{week}?brand=&show=`). The results page opens on the clicked show and offers "Back to schedule".
    - This week's cards open for booking, labelled Book / Booked / After PPV.
    - One of the player's own later show nights (their weekly show, their brand's PPVs, shared PPVs) offers **Simulate to here**. The rival's weekly shows and exclusive PPVs don't.
- **Simulate to a date** (`WeekSimulator`, `POST simulate`, confirmation): for each week before the target, the computer books the player's cards like the rival GM does (Preset rules for matches, random eligible promos, random Rumble entrants, the rival's deficit choice when needed), confirms them and resolves the week normally. Results, fatigue, injuries, title changes and history, contracts, morale, defections, news and email all happen. It stops early (with the reason) if a week can't air, never goes past WrestleMania, and leaves the target week to book.

## Backend

- Migration `2026_10_21_000001_add_confirmed_at_to_shows`.
- `BookingOrder`: `steps`, `blocker`, `next`, `assertEditable`, `confirm`, `unlock`, `assertReadyToAir`, `confirmAll`.
    - Enforced in `CardController` (book, clear, cash-in), `CardToolsController` (promos) and `PpvController::storeEntrants`.
    - New routes `card.confirm`, `card.unlock` and `simulate`.
- `BookingOrderPresenter` feeds the card and entrants screens.
- `CalendarService::seasonMonths` replaces `seasonGrid`. `ScheduleController` decorates each show night with its state, `results_url`, `card_url` and `can_simulate`.
- `ResultsController` takes `?brand=` and `?show=`, lists aired shows only, in air order.

## Frontend

- `pages/gm/schedule.tsx`: the calendar, with the simulate confirmation.
- `components/gm/booking-steps.tsx`: the step tracker ("1 Backlash ✓ → 2 Monday Night Raw"), the blocked and confirmed banners, and the header action (Continue / Air week / Unlock).
- `AirWeekButton` now asks first and names every card that will air.
- `CardBuilder` takes `lockedByOrder`.

## `/gm/docs`: Schedule tab

- New tab after News & email:
    - **The season**: dates, Sunday-start weeks, the calendar.
    - **Booking a week**: order, Continue, Unlock, Air week.
    - **Simulate to a date**: own show nights only, what happens.
    - **Pay-per-views**: week, PPV, date, brand, read from `CalendarService` and the PPV catalog.

## Tests

- `tests/Feature/Season/BookingOrderTest.php`:
    - the weekly show is locked until the PPV is confirmed;
    - the week won't air early;
    - the last card can't be confirmed;
    - air and results order;
    - rival results;
    - calendar states;
    - simulation targets only on the player's own show nights, for both brands;
    - simulation books and airs the skipped weeks (contracts tick, news, email) and leaves the target week open;
    - simulation only goes forward and within the season.
- `CatalogSeedTest`: new dates and the month grid.
- Vitest: `schedule.test.tsx`, `booking-steps.test.tsx`.
