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
- **Match types:** Fatal 5-Way in Normal Matches, Hardcore, Tables, TLC, Ladder and Steel Cage; Inferno (1v1, 4 bars, $45,000, thrown through the flames); Boiler Room Brawl ($25,000) and Interview Area Brawl ($15,000); One vs. Two, One vs. Three and Two vs. Trio; 6-Man Tag's Normal Tag and Tornado Tag; Battle Royals only won over the top rope (the Over the Top Rope variants are gone); no Steel Cage Pin & Give Up. John Cena and Eddie Guerrero become Backstage Brawl specialists. 98 match types, 46 specialties.
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
