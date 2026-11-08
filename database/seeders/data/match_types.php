<?php

/*
| Full match type list (§10). Each row: [family, variant, sides, manager_sides, extras].
| Fatigue cost, price and DQ/count-out flags come from config('gm.match_type_defaults')
| unless overridden in extras (`bars`, `cost`, `dq`, ...). Rope breaks, ring outs and the no-titles rules come
| from config('gm.match_rules').
|
| ASSUMPTIONS (§10 says these may not be bookable in GM Mode): 3 Stages of Hell and
| the 10–25-man Royal Rumbles are seeded but not GM-bookable. Special Referee is
| bookable with a non-competing referee picked from the roster.
*/

$one = [1, 1];
$noMgrVsMgr = [1];
$mgrVsMgr = [0, 1];
$tt = [1, 1, 1];
$f4w = [1, 1, 1, 1];
$f5w = [1, 1, 1, 1, 1];
$six = [1, 1, 1, 1, 1, 1];
$tag = [2, 2];
$sixTag = [3, 3];
$ttTag = [2, 2, 2];

$rumble = fn (int $n): array => array_fill(0, $n, 1);

return [
    // Normal Matches (house rule: Single, Triple Threat and Fatal 4-Way in one category).
    // House rule: DQs and count-outs are off in Triple Threat and Fatal 4-Way.
    ['single', 'No Manager', $one, []],
    ['single', 'No Manager vs. w/Manager', $one, $noMgrVsMgr],
    ['single', 'w/Manager vs. w/Manager', $one, $mgrVsMgr],
    ['single', 'Normal Triple Threat', $tt, [], ['cost' => 1_000, 'dq' => false, 'countout' => false]],
    ['single', 'Normal Fatal 4-Way', $f4w, [], ['cost' => 1_000, 'dq' => false, 'countout' => false]],
    // House rule (2026-11-08): not generally in the SVR GM Mode games
    ['single', 'Normal Fatal 5-Way', $f5w, [], ['cost' => 3_000, 'dq' => false, 'countout' => false, 'bars' => 2]],
    ['single', 'Six-Pack Challenge', $six, [], ['cost' => 5_000, 'dq' => false, 'countout' => false, 'bars' => 2]],

    // Tag Team (2 on 2)
    ['tag_team', 'Normal Tag', $tag, []],
    ['tag_team', 'Tornado Tag', $tag, []],
    // House rule: the old Elimination Tag category is split by team size.
    ['tag_team', 'Normal Tag Elimination', $tag, [], ['cost' => 2_000, 'is_elimination' => true, 'bars' => 2]],
    ['tag_team', 'Tornado Tag Elimination', $tag, [], ['cost' => 2_000, 'is_elimination' => true, 'bars' => 2]],
    ['tag_team', 'Tag vs Tag w/Manager', $tag, $noMgrVsMgr],
    ['tag_team', 'Tag w/Manager vs Tag w/Manager', $tag, $mgrVsMgr],

    // 6-Man Tag. House rule: no titles in any 3 vs 3 match.
    ['six_man_tag', 'Normal Tag', $sixTag, []],
    ['six_man_tag', 'Tornado Tag', $sixTag, []],
    ['six_man_tag', 'Normal 6-Man Tag Elimination', $sixTag, [], ['cost' => 2_000, 'is_elimination' => true, 'bars' => 2]],
    ['six_man_tag', 'Tornado 6-Man Tag Elimination', $sixTag, [], ['cost' => 2_000, 'is_elimination' => true, 'bars' => 2]],
    // House rule (2026-11-08): Tag Team's Tornado Tag Elimination with three teams.
    ['six_man_tag', 'Triple Threat Tornado Tag Elimination', $ttTag, [], ['cost' => 2_000, 'is_elimination' => true, 'bars' => 2]],
    // House rule: the side whose captain (first wrestler) is pinned, submitted or counted out loses.
    ['six_man_tag', 'Captain Fall Tornado 6-Man Tag Elimination', $sixTag, [], ['cost' => 4_000, 'is_elimination' => true, 'bars' => 3, 'captain_fall' => true]],

    // Handicap
    ['handicap', 'One vs. Two', [1, 2], []],
    ['handicap', 'One vs. Three', [1, 3], [], ['bars' => 2]],
    ['handicap', 'Two vs. Three', [2, 3], [], ['cost' => 1_000, 'bars' => 2]],

    // Backstage brawl
    ['backstage_brawl', 'Backstage Brawl', $one, ['cost' => 16_000]],
    ['backstage_brawl', 'Bar Brawl', $one, [], ['cost' => 31_000]],
    ['backstage_brawl', 'Boiler Room Brawl', $one, [], ['cost' => 25_000]],
    ['backstage_brawl', 'Interview Area Brawl', $one, [], ['cost' => 15_000]],
    ['backstage_brawl', 'Parking Lot Brawl', $one, [], ['cost' => 28_000]],

    ['battle_royal', '4-Man', $f4w, [], ['win_condition' => 'Over the top rope']],
    ['battle_royal', '5-Man', $f5w, [], ['win_condition' => 'Over the top rope']],
    ['battle_royal', '6-Man', $six, [], ['win_condition' => 'Over the top rope']],

    // House rule: No Mercy exclusive (SmackDown). The loser is buried: out for a month.
    ['buried_alive', 'Single', $one, [], ['ppv_exclusive' => 'no_mercy', 'brand_restriction' => 'smackdown', 'win_condition' => 'Buried alive']],

    ['elimination_chamber', 'Normal 6-Man Chamber', $six, [], ['ppv_exclusive' => 'new_years_revolution', 'brand_restriction' => 'raw', 'is_elimination' => true]],
    ['elimination_chamber', 'Triple Threat Tornado Tag Chamber', $ttTag, [], ['ppv_exclusive' => 'new_years_revolution', 'brand_restriction' => 'raw', 'is_elimination' => true]],

    ['first_blood', 'Single', $one, [], ['win_condition' => 'First blood']],

    ['fulfill_your_fantasy', 'Single', $one, [], ['divas_only' => true, 'win_condition' => 'Bra & Panties']],

    // House rule (replaces Slobber Knocker): six entrants, five singles bouts.
    // Entrants 1 and 2 open; each winner faces the next entrant. No titles; the only stipulation is a #1 Contender's Match.
    // House rule (2026-11-08): 4 and 5 entrants too (3 and 4 bouts).
    ['gauntlet', '4-Man Gauntlet', $f4w, []],
    ['gauntlet', '5-Man Gauntlet', $f5w, []],
    ['gauntlet', '6-Man Gauntlet', $six, []],

    ['hardcore', 'Single', $one, []],
    ['hardcore', 'Single No Manager vs. w/Manager', $one, $noMgrVsMgr],
    ['hardcore', 'Single w/Manager vs. w/Manager', $one, $mgrVsMgr],
    ['hardcore', 'Tornado Tag', $tag, []],
    ['hardcore', 'Tornado 6-Man Tag', $sixTag, [], ['cost' => 25_000]],
    ['hardcore', 'Triple Threat Tornado Tag', $ttTag, [], ['cost' => 25_000]],
    ['hardcore', 'Triple Threat', $tt, []],
    ['hardcore', 'Fatal 4-Way', $f4w, []],
    ['hardcore', 'Fatal 5-Way', $f5w, []],

    ['hell_in_a_cell', 'Single', $one, []],
    ['hell_in_a_cell', 'Tornado Tag', $tag, []],
    ['hell_in_a_cell', 'Triple Threat', $tt, []],
    ['hell_in_a_cell', 'Fatal 4-Way', $f4w, []],
    // House rule (2026-11-08): priced like the Fatal 4-Way.
    ['hell_in_a_cell', 'Fatal 5-Way', $f5w, []],
    ['hell_in_a_cell', 'Armageddon Hell in a Cell', $six, [], ['cost' => 55_000]],

    // House rule (2026-11-07): won by throwing the opponent through the flames.
    ['inferno', 'Single', $one, [], ['win_condition' => 'Thrown through the flames']],

    // House rule (2026-11-08): ends on the score when time runs out; every
    // fall counts (pinfall, submission, DQ, count-out). No manager variants.
    ['iron_man', 'Single', $one, [], ['win_condition' => 'Most falls', 'timed' => true]],

    ['ladder', 'Single', $one, [], ['win_condition' => 'Briefcase retrieval']],
    ['ladder', 'Single No Manager vs. w/Manager', $one, $noMgrVsMgr, ['win_condition' => 'Briefcase retrieval']],
    ['ladder', 'Single w/Manager vs. w/Manager', $one, $mgrVsMgr, ['win_condition' => 'Briefcase retrieval']],
    ['ladder', 'Tornado Tag', $tag, [], ['win_condition' => 'Briefcase retrieval']],
    ['ladder', 'Triple Threat Tornado Tag', $ttTag, [], ['cost' => 16_000, 'win_condition' => 'Briefcase retrieval']],
    ['ladder', 'Triple Threat', $tt, [], ['win_condition' => 'Briefcase retrieval']],
    ['ladder', 'Fatal 4-Way', $f4w, [], ['win_condition' => 'Briefcase retrieval']],
    ['ladder', 'Fatal 5-Way', $f5w, [], ['win_condition' => 'Briefcase retrieval']],
    // House rule (2026-11-08): priced like its Triple Threat Tornado Tag, as in Tables.
    ['ladder', 'Tornado 6-Man Tag', $sixTag, [], ['cost' => 16_000, 'win_condition' => 'Briefcase retrieval']],
    // House rule: WrestleMania only, 6-man, no titles, no Divas (§10).
    ['ladder', 'Money in the Bank', $six, [], ['ppv_exclusive' => 'wrestlemania', 'titles_allowed' => false, 'divas_allowed' => false, 'win_condition' => 'Briefcase retrieval', 'mitb' => true]],

    ['last_man_standing', 'Single', $one, [], ['win_condition' => '10-count']],

    ['special_referee', 'Single', $one, []],
    ['special_referee', 'Tag Match', $tag, []],
    ['special_referee', 'Iron Man', $one, [], ['cost' => 37_000, 'bars' => 5, 'timed' => true, 'win_condition' => 'Most falls', 'dq' => true, 'countout' => true]],
    ['special_referee', 'Hell in a Cell', $one, [], ['cost' => 45_000, 'bars' => 5, 'dq' => false, 'countout' => false, 'rope_breaks' => false]],
    ['special_referee', 'Last Man Standing', $one, [], ['cost' => 40_000, 'bars' => 5, 'dq' => false, 'countout' => false]],

    ['steel_cage', 'Single', $one, [], ['win_condition' => 'Cage escape']],
    ['steel_cage', 'Tornado Tag', $tag, [], ['win_condition' => 'Cage escape']],
    ['steel_cage', 'Triple Threat', $tt, [], ['win_condition' => 'Cage escape']],
    ['steel_cage', 'Fatal 4-Way', $f4w, [], ['win_condition' => 'Cage escape']],
    ['steel_cage', 'Fatal 5-Way', $f5w, [], ['win_condition' => 'Cage escape']],

    ['submission', 'Single', $one, [], ['win_condition' => 'Submission']],
    // House rule (2026-11-08): the category's default price and fatigue.
    ['submission', 'Triple Threat', $tt, [], ['win_condition' => 'Submission']],
    ['submission', 'Fatal 4-Way', $f4w, [], ['win_condition' => 'Submission']],
    // House rule (2026-11-08): ends on the score when time runs out; only submissions count.
    ['submission', 'Ultimate Submission', $one, [], ['cost' => 2_000, 'win_condition' => 'Submission', 'timed' => true]],

    ['table', 'Single', $one, [], ['win_condition' => 'Put through a table']],
    ['table', 'Tornado Tag', $tag, [], ['win_condition' => 'Put through a table']],
    ['table', 'Tornado Tag Elimination', $tag, [], ['cost' => 4_000, 'is_elimination' => true, 'bars' => 3, 'dq' => false, 'countout' => false, 'rope_breaks' => false, 'win_condition' => 'Put through a table']],
    ['table', 'Tornado 6-Man Tag Elimination', $sixTag, [], ['cost' => 4_000, 'is_elimination' => true, 'bars' => 3, 'dq' => false, 'countout' => false, 'rope_breaks' => false, 'win_condition' => 'Put through a table']],
    ['table', 'Triple Threat', $tt, [], ['win_condition' => 'Put through a table']],
    ['table', 'Fatal 4-Way', $f4w, [], ['win_condition' => 'Put through a table']],
    ['table', 'Fatal 5-Way', $f5w, [], ['win_condition' => 'Put through a table']],
    ['table', 'Tornado 6-Man Tag', $sixTag, [], ['cost' => 8_000, 'win_condition' => 'Put through a table']],
    ['table', 'Triple Threat Tornado Tag', $ttTag, [], ['cost' => 8_000, 'win_condition' => 'Put through a table']],
    // House rule (2026-11-08): the Table elimination tags moved here from Tag
    // Team and 6-Man Tag (they were "Table Tornado … Elimination").

    ['tlc', 'Single', $one, [], []],
    ['tlc', 'Tornado Tag', $tag, [], []],
    // House rule (2026-11-08): priced like its Triple Threat Tornado Tag, as in Tables.
    ['tlc', 'Tornado 6-Man Tag', $sixTag, [], ['cost' => 12_000]],
    // House rule: not in the 2006 game.
    ['tlc', 'Triple Threat Tornado Tag', $ttTag, [], ['cost' => 12_000]],
    ['tlc', 'Triple Threat', $tt, [], []],
    ['tlc', 'Fatal 4-Way', $f4w, [], []],
    ['tlc', 'Fatal 5-Way', $f5w, [], []],

    ['three_stages_of_hell', 'Single', $one, [], ['gm_bookable' => false]],

    // Royal Rumble — only the 30-man is bookable, and only on its PPV (house rule)
    ['royal_rumble', '10-Man', $rumble(10), [], ['gm_bookable' => false, 'titles_allowed' => false, 'is_elimination' => true, 'win_condition' => 'Last one in the ring']],
    ['royal_rumble', '15-Man', $rumble(15), [], ['gm_bookable' => false, 'titles_allowed' => false, 'is_elimination' => true, 'win_condition' => 'Last one in the ring']],
    ['royal_rumble', '20-Man', $rumble(20), [], ['gm_bookable' => false, 'titles_allowed' => false, 'is_elimination' => true, 'win_condition' => 'Last one in the ring']],
    ['royal_rumble', '25-Man', $rumble(25), [], ['gm_bookable' => false, 'titles_allowed' => false, 'is_elimination' => true, 'win_condition' => 'Last one in the ring']],
    // House rule: no Divas in the Royal Rumble or the Survivor Series main event.
    ['royal_rumble', '30-Man', $rumble(30), [], ['ppv_exclusive' => 'royal_rumble', 'divas_allowed' => false, 'titles_allowed' => false, 'is_elimination' => true, 'win_condition' => 'Last one in the ring']],

    // House rule: Survivor Series exclusive, always its main event, no titles.
    ['survivor_series_elimination', 'Traditional 3 vs 3', $sixTag, [], ['ppv_exclusive' => 'survivor_series', 'divas_allowed' => false, 'is_elimination' => true, 'titles_allowed' => false]],
];
