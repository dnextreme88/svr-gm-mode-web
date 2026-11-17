# Qualifying Matches for Survivor Series & Money in the Bank

**Executed 2026-11-17** on `feature/20261117-qualifying-matches` (branched off `feature/20261117-rival-renewals-and-checkpoints`).

## Why

The Survivor Series 5 vs 5 team and each brand's 3 Money in the Bank ladder spots were hand-picked on the PPV card. As in WWE, they're now earned on the weekly shows in the weeks before the PPV, which gives those weeks a storyline.

## Rules (house rule, 2026-11-17)

- **Survivor Series:** the 5 weeks before it (season weeks 30–34; first Raw Oct 24 and Smackdown Oct 28, 2005). Match 5 on both brands' weekly shows is a fixed **Ladder Triple Threat** Qualifying Match. Each winner takes one of the brand's 5 team spots.
- **Money in the Bank:** the 3 weeks before WrestleMania (weeks 49–51). Match 5 is a fixed **Normal Triple Threat**. Each winner takes one of the brand's 3 ladder spots. Champions can't enter.
- **The slot is fixed** (`FixedSlotKind::QualifyingMatch`). Its type is locked, and it can't be cleared, moved or randomized. It isn't a stipulation. It must be booked before the card airs.
- **Who can enter:** no Divas, and no one who already qualified for that PPV. Losers can try again.
- **Any win qualifies,** a DQ or count-out included.
- **Winners are locked onto the PPV card.** Only a serious injury, or leaving the brand by the PPV, gives the spot up. The player then picks the replacement on the PPV card, and the rival picks its own.
- **Rival GM:**
    - It picks entrants who stay at the careful 70%.
    - It keeps its qualifiers healthy until the PPV: they're only booked in Normal Matches or Tag Team types at 70% or less.
    - It books Continue Rivalry for a qualifier's rivalry, or else Inactive Superstar to rest a tired qualifier.
- **Vince's reminder emails** come 5 weeks before Survivor Series and 3 weeks before WrestleMania, and mention the qualifiers. The Royal Rumble and SummerSlam reminders stay at 2 weeks. The PPV buzz news mentions the qualifiers too.
- **Deferred (TODOS):** qualifiers for the WrestleMania interbrand 1v1 and 2v2.

## Implementation

- **Data:** `ppv_qualifiers` table and `PpvQualifier` model, listed in `CareerCheckpoint::TABLES`. Settings are in `config('gm.ppv.qualifiers')` (slot, windows, types, rival_safe_families) and `gm.emails.ppv_reminder_weeks_overrides`.
- **`QualifierService`:** window, setUp, options, setEntrants, aiEntrants, fillMissing, missing, record, seatable, protectedIds.
- **Wiring:**
    - `ShowScheduler` and `PrepareShows` set the slot up.
    - `MatchOutcomeHandler` records the winner.
    - `BookingOrder` refuses to air a card whose qualifier isn't booked.
    - `CardController` adds the qualifier to the `series` pickers and routes `PUT series/5` to it.
- **PPV cards:** `PpvCardService` seats qualifiers on the 5 vs 5 (`seatSurvivorQualifiers`, `lockedEntrants`, `entrantsNeeded`) and on the ladder (`seatMitbQualifiers`, `seriesOptions.locked`, `refreshSeries` replaces only spots that are gone).
- **Rival:** `AiBooker::fit` handles protected superstars, and `AiPromoPlanner::restQualifier` books their promos.
- **News:**
    - new `survivor_series_qualified` event; `mitb_qualified` is reused for Money in the Bank
    - `ppv_upcoming` variants that mention the qualifiers
- **UI:**
    - the card's "Qualifying Match · Survivor Series / Money in the Bank" label and locked ladder pickers
    - the entrants page's Qualified rows
    - an Active Effects line
    - a `/gm/docs` Qualifying Matches panel and the updated email row

## Also in this branch: news priorities

- **Brand tickers** show match results only. The WWE ticker and the crowd line are gone.
- **WWE headline:**
    - The week before a PPV, its "{ppv} is 1 week away" headline leads, after the PPV results and re-draft headlines.
    - The week's biggest crowd is now the last resort, used only when the WWE card has no other real news.
