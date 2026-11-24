# Winning Odds: Weight Class Rule, Manager & Faction Bonuses

**Executed:** 2026-11-21

## Why

The weight class modifier gave every side points for each class it was above the lightest side whenever every side was one class. In a Special Referee Fatal 5-Way (Kane and Undertaker Super Heavyweights, JBL and Batista Heavyweights, Dolph Ziggler a Light Heavyweight) four of five sides got a bonus, which isn't a weight advantage at all. A manager in a side's corner also gave no edge.

## Rules

- **Weight class:** every side must be a single class, exactly one side must be the heaviest, and the other sides must be either all the same class or all different classes. Only that heaviest side gets +5 (`gm.outcomes.winning_odds.weight_class_step`) for every class it is above the lightest side.
    - Applies: Heavyweight vs 3 Cruiserweights (+10); Super Heavyweights vs Heavyweights (+5); Super Heavyweights vs Heavyweights vs Light Heavyweights (+10); Super Heavyweights vs 2 Heavyweight teams (+5); Heavyweight vs 5 Cruiserweights (+10).
    - Doesn't apply: Heavyweight, 2 Cruiserweights and a Light Heavyweight; any team mixing classes; 2 Heavyweights, 2 Light Heavyweights and 2 Cruiserweights; two sides tied at the heaviest class.
- **Manager:** +10 (`manager`) for a side with a manager. Both sides managed cancel out.
- **Faction:** +10 (`faction`) once per side for 2+ members of an active faction (tag team or stable) teaming on it (`FactionNamer::factionsOn`). Members on opposite sides, or a disbanded faction, don't count.

## Changes

- `WinningOdds::weightBonuses` rewritten; manager ("Manager: {name}") and faction ("Faction: {name}") factors added in `WinningOdds::forSlot`.
- `config/gm.php`: `manager` and `faction` added.
- Tests in `tests/Feature/Matches/WinningOddsTest.php` for every example above and the manager and faction bonuses.
- `CLAUDE.md` and `/gm/docs` (Winning odds) updated.
