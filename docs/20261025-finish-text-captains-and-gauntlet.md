# Finish Text, Captain Tags & the Gauntlet

Executed **2026-10-25**.

## Why

The live results screen had a few gaps:

- Captain Fall matches didn't show who the captains were.
- The match up next showed a "Manual book to win" button. It now reads **Pending Result**.
- Finish summaries ignored the win condition. "Shelton Benjamin wins by put through a table." didn't say who went through it, and a Foreign Object finish in a Table match said "…makes the cover" with a **Pinfall** badge.
- Slobber Knocker was still a pending design. The user's design replaces it with the **Gauntlet**.

## What changed

### Captain tag and button label

- `CardPresenter::outcome()` / `pending()` send `captains` (the first wrestler on each side in a Captain Fall match). `SideNames` and `BookToWinCard` tag them **(Cpt)** in gold, like **(C)**.
- The results list's up-next button reads **Pending Result**.

### Finish text (`App\Services\Matches\FinishText`)

- Every seeded win condition has a clean sentence and a closing clause for weapon and run-in finishes. Templates use `{W}`, `{L}`, `{he}/{him}/{his}` and `[singular|plural]` verbs. A test fails if a seeded condition has no text.
- The finish names one wrestler. With several opponents the losing side is drawn at random (as before). In a tag match one losing member is drawn last, so the earlier rolls stay put. In Captain Fall it's the captain.
- Battle Royals and elimination matches: "{W} last eliminates {L} via pinfall/submission".
- Buried Alive: "…He is nowhere to be seen (out for N weeks)", with N from `gm.buried_alive.sit_out_weeks` (4).
- A Foreign Object finish draws a `App\Enums\Weapon`: steel chair, sledgehammer, ring bell or steel steps. In a type without DQs, a weapon shot or a run-in ends the type's way, and the method is the win condition (`MatchSpecific`, or `Submission`), not a pinfall.
- **Captain Fall** is a non-gimmick match won by eliminating the other captain by pinfall, submission, DQ or count-out. The text always names both captains, e.g. "{W}'s team wins because {captain}, the captain of the opposing team, shoves the referee and is disqualified."

### Gauntlet (replaces Slobber Knocker)

- `MatchFamily::Gauntlet` with one variant, `6-Man Gauntlet`. It costs $5,000 and a flat 3 bars, has DQ and count-out on, and no titles.
- New `match_rules.no_stipulation_families` (`gauntlet`), enforced by `TitleBookingRules::validateStipulation`. The stipulation cycler shows nothing (`stipulations_allowed` on the type payload).
- `MatchResolver::resolveGauntlet` runs five singles bouts (Normal Matches specialty bonus). Entrants 1 and 2 open, and each winner meets the next entrant. Bouts are stored in the new `gauntlet_bouts` table. The slot's `match_results` row holds the overall result: the winner gets 1 win, the other five get 1 loss each. Rivalry meetings are recorded per bout.
- Book to Win: `POST decide` takes `bouts` (the side booked to win each bout, in order). `WeekResolver::assertGauntletPicks` checks each pick is in its bout, and that only simulated bouts follow a simulated one. The card books one bout at a time, with **Back** and **Simulate the rest**. The outcome card lists all five bouts.

### Injured-win headline

- A decisive win where a winner went in with a minor injury reads "Handicapped and determined, {winner} defeats {loser} in a hard-hitting match via {via} despite suffering a minor injury. Remember, an injury is not a hindrance to winning the match!" (`match_result.injured`).
- `ShowResolver` records it when the match airs and checks again when the show closes (`confirmInjuredWins`). If the injury turned serious in a later match on the same show, the story is rewritten to the normal decisive text (`NewsRecorder::rewrite`). DQ and count-out wins keep their own text.

### Docs

- CLAUDE.md: Captain Fall, Gauntlet, finish text, Pending Result, no stipulations in a Gauntlet. The Slobber Knocker TODO is removed.
- `/gm/docs`: Gauntlet and How finishes read panels on Match types, the Captain fall win condition, the Stipulations intro, and Results & titles (Pending Result, (Cpt), Gauntlet booking).

## Notes

- The weapon and team-victim draws add RNG steps, so later rolls in a show differ from before for the same seed.
- Catalog change: run `php artisan migrate:fresh --seed` (wipes saves).
