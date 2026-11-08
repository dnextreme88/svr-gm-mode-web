# 2004 & 2006–2007 roster, factions, faction pages, conditional stories, Rematch Clause and match type changes

## Context

We compared the 2006 and 2007 WWE rosters on thesmackdownhotel.com ([2006](https://www.thesmackdownhotel.com/roster/?promotion=wwe&date=2006), [2007](https://www.thesmackdownhotel.com/roster/?promotion=wwe&date=2007), their Extras tabs included) with the seeded roster, kept only the superstars who weren't seeded yet, and dropped the ones the user chose not to add. This change:

1. seeds those 38 debuts as free agents (and one Legend);
2. adds 11 tag teams and a stable, and Tony Mamaluke to the Full Blooded Italians;
3. gives every faction its own match history page;
4. adds five conditional match stories, with their own news text and a pop-up on the live results screen;
5. adds the **Rematch Clause** Active Effect, which the rival GM also books;
6. reworks some match types (Inferno, Fatal 5-Way, new brawls, renames, removals) and two specialties;
7. lets the player change titles after the draft, and confirm Stop drafting with a summary.

## Rules changed (2026-11-07)

- **Roster:** the brands stay the Nov 14, 2005 rosters, so every debut is a free agent (ECW isn't a brand in the game). Weight classes follow the billed weight when each first appeared (the new CLAUDE.md weight-class rule). CM Punk (218) and Cody Rhodes (220) are Light Heavyweights by choice.
- **Factions:** seeded and active from week 1 like the others; their real dates are approximate.
- **Faction page:** `GET factions/{saveFaction}`. The matches where 2+ members teamed while it was together, both brands, newest first, in the Reports table.
- **Conditional stories** (`gm.stories`): Hornswoggle's shillelagh (story only, 50% when Finlay wins, 8-week lockout); Undertaker's lights-out and Kane's pyro (the rival's match, 60+ heat, a Continue Rivalry that week, the two kept apart; the rival loses 80%, once per rivalry once it works); the Dudleys' tables and Team Xtreme's ladder (lands 80%, then wins 80% or always in a Tables / Ladder match; 4-week cooldown after it lands). Simulated matches only, never a DQ, not in the Royal Rumble, Elimination Chamber, Steel Cage or Hell in a Cell. The story goes into the match's news summary and a pop-up.
- **Rematch Clause:** a title lost in a match gives the former champion(s) a rematch against the new one(s) within 4 weeks. An Active Effect only. Any 1v1/2v2 title match between them resolves it; it ends early if someone leaves the brand, is seriously injured, or the title moves on. The rival rolls 25/50/75/85% over its 4 weeks.
- **Match types:** Fatal 5-Way in Normal Matches, Hardcore, Tables, TLC, Ladder and Steel Cage; Inferno (1v1, 4 bars, $45,000, thrown through the flames); Boiler Room Brawl ($25,000) and Interview Area Brawl ($15,000); One vs. Two, One vs. Three and Two vs. Three; 6-Man Tag's Normal Tag and Tornado Tag; Battle Royals only won over the top rope (the Over the Top Rope variants are gone); no Steel Cage Pin & Give Up. John Cena and Eddie Guerrero become Backstage Brawl specialists. 98 match types, 46 specialties.
- **Titles after the draft:** any decided title can be changed until the season starts (`titles/assign/{championship}/undo`), with a Review your champions panel once all are decided.
- **Stop drafting:** a review modal (roster count, divisions, budget left, picks, what's next).
- **Story text rule:** text that tells a story or explains a headline is 3+ sentences, at most 3 paragraphs of 3–5 sentences.

## The 2006–2007 debuts

| Year | Superstar | Class | Billed lb |
| --- | --- | --- | --- |
| 2006 | Festus _(Imposter Kane in 2006)_ | Heavyweight | 290 |
| 2006 | Dave Taylor | Heavyweight | 256 |
| 2006 | Gymini Jake | Super Heavyweight | 304 |
| 2006 | Gymini Jesse | Super Heavyweight | 304 |
| 2006 | Hornswoggle _(Little Bastard in 2006)_ | Cruiserweight | 142 |
| 2006 | Idol Stevens | Heavyweight | 247 |
| 2006 | James Curtis _(KC James in 2006)_ | Light Heavyweight | 235 |
| 2006 | The Miz | Light Heavyweight | 221 |
| 2006 | Balls Mahoney | Super Heavyweight | 305 |
| 2006 | CM Punk | Light Heavyweight _(by choice)_ | 218 |
| 2006 | Elijah Burke | Light Heavyweight | 230 |
| 2006 | Justin Credible | Light Heavyweight | 225 |
| 2006 | Kevin Thorn | Heavyweight | 270 |
| 2006 | Rodney Mack | Heavyweight | 240 |
| 2006 | The Sandman | Heavyweight | 240 |
| 2006 | Sylvester Terkay | Super Heavyweight | 320 |
| 2006 | Test | Heavyweight | 285 |
| 2006 | Tony Mamaluke | Cruiserweight | 219 |
| 2006 | Vladimir Kozlov | Super Heavyweight | 302 |
| 2007 | Cody Rhodes | Light Heavyweight _(by choice)_ | 220 |
| 2007 | Drew McIntyre | Heavyweight | 265 |
| 2007 | Santino Marella | Light Heavyweight | 233 |
| 2007 | Curt Hawkins | Light Heavyweight | 223 |
| 2007 | Jesse | Cruiserweight | 220 |
| 2007 | Zack Ryder | Light Heavyweight | 230 |
| 2007 | Colin Delaney | Cruiserweight | 172 |
| 2007 | Kofi Kingston | Cruiserweight | 212 |
| 2007 | Marcus Cor Von | Heavyweight | 265 |

**Divas (Featherweight):** Beth Phoenix, Kristal, Layla, Ariel, Francine, Kelly Kelly, Trinity, Brooke Adams, Jazz.

**Legend:** Bob Backlund, Light Heavyweight, 234.

Weights are billed weights from Wikipedia (WWE bios, the WWE Encyclopedia) and thesmackdownhotel.com. Gymini Jake and Jesse (304) only have fan-database weights.

## Left out

- **Already seeded:** Jeff Hardy, JTG, Robbie and Rory McAllister, Shad (Shad Gaspard), Umaga, The Great Khali, MVP (Montel Vontavious Porter), Sabu, Faarooq (Ron Simmons), Cherry, Chuck Palumbo, Deuce, Domino.
- **Not added (by choice):** Antonio, Romeo, Armando Alejandro Estrada, the Spirit Squad (Johnny, Kenny Dykstra, Mikey, Mitch; Nicky is Dolph Ziggler), Mama Benjamin, Chad Dick, James Dick, Gunner Scott, Brad Armstrong, Christopher W. Anderson, Danny Doring, Matt Striker, The Preacher, Henry O. Godwinn, Johnny Parisi, Roadkill, Tully Blanchard, DH Smith, Krissy Vaine, Ranjin Singh.
- **Renamed (already in under another name):** Animal Warrior = Road Warrior Animal, King Booker = Booker T, Queen Sharmell = Sharmell, Little Guido = Nunzio, Finlay = Fit Finlay, Ro-Z = Rosey, Big Daddy V = Viscera, U-Gene = Eugene, John Morrison = Johnny Nitro, Sylvain Grenier = Sylvan, Armando Estrada = Armando Alejandro Estrada, Brett & Brian Major = Curt Hawkins & Zack Ryder.
- **Non-wrestling staff:** Paul Heyman, Paul Bearer, Donald Trump, Rebecca DiPietro, Jack Korpela, the new producers (Greg Gagne, Mike Rotunda, Lou D'Angeli, Barry Windham), referees, announcers and commentators.

## Assumptions

- "Heat 60" for the lights-out and pyro means 60 or more, going into the match.
- The rival GM rolls for a Rematch Clause once a week (the same roll on every card that week).
- The "never a DQ, referee down or distracted" rule applies to all four stories that change results, not only Undertaker's.

## 2004 additions (2026-11-07)

From the [2004 roster](https://www.thesmackdownhotel.com/roster/?promotion=wwe&date=2004), compared the same way.

| Superstar         | Class             | Billed lb |
| ----------------- | ----------------- | --------- |
| Goldberg          | Heavyweight       | 285       |
| Grandmaster Sexay | Cruiserweight     | 213       |
| Scott Steiner     | Heavyweight       | 276       |
| A-Train           | Super Heavyweight | 331       |
| Billy Gunn        | Heavyweight       | 260       |
| Brock Lesnar      | Heavyweight       | 286       |
| Chavo Classic     | Light Heavyweight | 229       |
| Rico              | Light Heavyweight | 230       |
| Rikishi           | Ultra Heavyweight | 425       |
| Sean O'Haire      | Heavyweight       | 270       |
| Ultimo Dragon     | Cruiserweight     | 185       |

**Divas (free agents):** Gail Kim, Jacqueline, Nidia, Sable, Shaniqua, The Fabulous Moolah.

**Factions:** Billy & Chuck (Billy Gunn, Chuck Palumbo, Rico), Too Cool (Scotty 2 Hotty, Grandmaster Sexay, Rikishi), Suzuki & Duprée (Kenzo Suzuki, René Duprée and Hiroko), The Fabulous Moolah & Mae Young (tag teams); Thuggin' and Buggin' Enterprises (Mark Henry, Rodney Mack, Jazz; stable); Team Angle adds Luther Reigns and Mark Jindrak (5 members, still a stable).

**Not added (by choice):** Bill DeMott, Chris Kanyon, Ernest Miller, Jesús, Johnny Stamboli, Sakoda, Zach Gowen, A-1, Bobby Roode, Daniel Puder, Mikal Adryan; the Divas who were mostly managers or interviewers (Amy Weber, Carmella DeCesare, Lauren Jones, Rochelle Loewen, Rue DeBona, Terri Runnels); the teams Edge & Chris Benoit, Charlie Haas & Hardcore Holly, The Jersey Shore Crew, Paul London & Billy Kidman, Rey Mysterio & Rob Van Dam, Rob Van Dam & Booker T, The Disciples of Synn, Kyo Dai, Charlie Haas & Rico, Molly Holly & Gail Kim.

**Totals:** 215 wrestlers: Raw 30, SmackDown 30, 87 free-agent superstars, 30 free-agent Divas, 38 Legends.

## Match type follow-up (2026-11-08)

- **New:** Ladder and TLC add a Tornado 6-Man Tag (3v3), priced like their Triple Threat Tornado Tags ($16,000 and $12,000), as in Tables. They cost 4 bars (six-competitor fatigue).
- **Moved to Table:** Tornado Tag Elimination (2v2, was Tag Team's "Table Tornado Tag Elimination") and Tornado 6-Man Tag Elimination (3v3, was 6-Man Tag's "Table Tornado 6-Man Tag Elimination"). Same rules: 3 bars, $4,000, no DQ, count-out or rope breaks, won through a table.
- The rival's intergender rules still keep the Table 6-man elimination single-gender; they now match it by category and name, since the plain 6-Man Tag elimination shares the name.
- **Totals:** 100 match types.

## Fan attendance, timed matches and more match types (2026-11-08)

### Repeat matches

- A match slot on a brand's weekly show is a **repeat** when that slot on its weekly show the week before was in the same match category (`match_types.family`, any variant). Every category counts, Normal Matches included.
- Only last week counts. PPVs and interbrand cards never do, and week 1 has none.
- It costs **2 attendance points per slot, up to 12** (`gm.attendance.points.repeat_slot`, `repeat_max`), as a negative `repeats` factor (`RepeatMatches`, `AttendanceService::facts`).
- The Air week confirmation lists the repeated slots under "Fans have seen these before" (the card's `repeats` prop). It's a warning only: the week still airs.
- **Rival GM:** on a weekly show, each slot it fills itself rolls 1–100 on `ai.repeat.{show}.{position}`. On 76+ (25%, `gm.ai.booking.repeat_category_chance`) it books last week's category in that slot. When nothing in that category fits, it books as usual.

### Promos, one by one

The share-based promo factor is gone. Each aired promo now scores on its own:

| Promo | Success | Failure | Backfire |
| --- | --- | --- | --- |
| Interference, Slander | +10 | 0 _(assumption)_ | −10 |
| Advertising, Interview Space, Merchandise Sponsorship (success chance below 100%) | +6 | −2 | — |
| Promos that always succeed (fizzled ones included) | 0 | 0 | — |

The positive factors can now reach 110 before the clamp to 0–100. The worked example still scores 67.

### Timed matches

- **Which:** the Iron Man, Special Referee Iron Man and Ultimate Submission. They end on a score when time runs out: the winner scores 1–10 and the loser 0 to one less, so never a tie.
- **Iron Man:** every fall counts (pinfall, submission, DQ, count-out). It never ends on a DQ or count-out. The family now allows count-outs.
- **Ultimate Submission:** only submissions count, and it's always won by submission.
- **Special Referee Iron Man:** gets the "Most falls" win condition.
- **Storage:** `match_results.winner_score` / `loser_score` (migration `2026_11_08_000001`).
- **Finish text:** "{W} scores the deciding fall on {L} as time runs out, winning 3–1."
- **News:** "…in an Iron Man match with a 3-1 score".
- **Results:** "Most falls · 3–1".

### Match types (103)

- **Added:**
    - Six-Pack Challenge (Normal Matches, $5,000, 2 bars, no DQ or count-out); the Normal Fatal 5-Way is $3,000 and 2 bars;
    - Hell in a Cell Fatal 5-Way ($45,000);
    - 4-Man and 5-Man Gauntlets (3 and 4 bouts);
    - Submission Triple Threat and Fatal 4-Way ($1,000, 2 bars, _assumption_);
    - Triple Threat Tornado Tag Elimination (6-Man Tag, 2v2v2, $2,000, 2 bars).
- **Removed:** Iron Man's and Last Man Standing's two manager variants.

### Reports chart

- Each brand's line now runs through its PPV nights: a brand PPV on its brand's line, a shared PPV on both. The PPV is still drawn as a gold diamond on top. Before this, PPV weeks broke the line, and early in a season a brand with one weekly show had no line at all, so the chart read as dots.
- A brand with a single night still shows its marker alone.

### Draft and title assignment

- **Champions progress bar:** the title assignment page has the card's progress bar above the title steps, the review included: "N/5 champions assigned" (one per title, two for the tag titles). A vacant title stays in the total, unfilled.
- **End of the draft:** when the draft ends on both brands, the draft page shows the same spinner overlay as starting the season ("Draft picks are finished. You may now assign your brand's champions.") before the champions open. A pick, Stop drafting or Skip rest of draft that ends the draft redirects back to the draft once, with `draft_concluded` flashed; the rival's last animated pick shows it straight away.
- **Overlay:** both overlays are `<LoadingOverlay>` (`components/gm/loading-overlay.tsx`) and wait `LOADING_OVERLAY_DELAY_MS` (2.5 seconds) before moving on.
- **Title page tweaks:** only "Deciding…" pulses on the title steps, the roster pool is 1 column up to md, 2 at md and 3 from lg (with no scrollbar from lg), and Change lives on the review only.

### Match type names (2026-11-08, 106 types)

- **Normal Matches:** No Manager → Single, No Manager vs. w/Manager → Single vs. w/ Manager, w/Manager vs. w/Manager → Single w/ Manager vs. w/ Manager; the Triple Threat, Fatal 4-Way and Fatal 5-Way drop their "Normal" prefix.
- **Tag Team:** Tag vs Tag w/Manager → Normal Tag vs. w/ Manager and Tag w/Manager vs Tag w/Manager → Normal Tag w/ Manager vs. w/ Manager, both moved right after Tornado Tag.
- **Battle Royal:** 4-, 5- and 6-Man Over the Top Rope.
- **Hardcore and Ladder:** "w/Manager" is written "w/ Manager". Ladder's Tornado 6-Man Tag stays right after Tornado Tag.
- **Special Referee:** Tag Match → Normal Tag. New Triple Threat, Fatal 4-Way and Fatal 5-Way: the Normal Matches sides and prices ($1,000, $1,000, $3,000), no DQ or count-out, and the family's 2 bars _(assumption: fatigue wasn't specified)_.
- **Table:** Tornado Tag Elimination, Tornado 6-Man Tag, Tornado 6-Man Tag Elimination, then Triple Threat Tornado Tag.
- Code that looked up `'single', 'No Manager'` (PPV cards, title match planning, MITB cash-ins) now looks up `'Single'`. Apply with `php artisan migrate:fresh --seed`.
