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

    // Tag Team (2 on 2)
    ['tag_team', 'Normal Tag', $tag, []],
    ['tag_team', 'Tornado Tag', $tag, []],
    ['tag_team', 'Tag vs Tag w/Manager', $tag, $noMgrVsMgr],
    ['tag_team', 'Tag w/Manager vs Tag w/Manager', $tag, $mgrVsMgr],
    // House rule: the old Elimination Tag category is split by team size.
    ['tag_team', 'Normal Tag Elimination', $tag, [], ['cost' => 2_000, 'is_elimination' => true, 'bars' => 2]],
    ['tag_team', 'Tornado Tag Elimination', $tag, [], ['cost' => 2_000, 'is_elimination' => true, 'bars' => 2]],
    ['tag_team', 'Table Tornado Tag Elimination', $tag, [], ['cost' => 4_000, 'is_elimination' => true, 'bars' => 3, 'dq' => false, 'countout' => false, 'rope_breaks' => false, 'win_condition' => 'Put through a table']],

    // 6-Man Tag. House rule: no titles in any 3 vs 3 match.
    ['six_man_tag', 'Normal', $sixTag, []],
    ['six_man_tag', 'Tornado', $sixTag, []],
    ['six_man_tag', 'Normal 6-Man Tag Elimination', $sixTag, [], ['cost' => 2_000, 'is_elimination' => true, 'bars' => 2]],
    ['six_man_tag', 'Tornado 6-Man Tag Elimination', $sixTag, [], ['cost' => 2_000, 'is_elimination' => true, 'bars' => 2]],
    ['six_man_tag', 'Table Tornado 6-Man Tag Elimination', $sixTag, [], ['cost' => 4_000, 'is_elimination' => true, 'bars' => 3, 'dq' => false, 'countout' => false, 'rope_breaks' => false, 'win_condition' => 'Put through a table']],
    // House rule: the side whose captain (first wrestler) is pinned, submitted or counted out loses.
    ['six_man_tag', 'Captain Fall Tornado 6-Man Tag Elimination', $sixTag, [], ['cost' => 4_000, 'is_elimination' => true, 'bars' => 3, 'captain_fall' => true]],

    // Handicap
    ['handicap', 'One on Two (Tornado)', [1, 2], []],
    ['handicap', 'One on Tag', [1, 2], []],
    ['handicap', 'One on Three (Tornado)', [1, 3], [], ['bars' => 2]],
    ['handicap', 'One on Trio', [1, 3], [], ['bars' => 2]],
    ['handicap', 'Two on Trio', [2, 3], [], ['bars' => 2]],

    // Royal Rumble — only the 30-man is bookable, and only on its PPV (house rule)
    ['royal_rumble', '10-Man', $rumble(10), [], ['gm_bookable' => false, 'titles_allowed' => false, 'is_elimination' => true, 'win_condition' => 'Last one in the ring']],
    ['royal_rumble', '15-Man', $rumble(15), [], ['gm_bookable' => false, 'titles_allowed' => false, 'is_elimination' => true, 'win_condition' => 'Last one in the ring']],
    ['royal_rumble', '20-Man', $rumble(20), [], ['gm_bookable' => false, 'titles_allowed' => false, 'is_elimination' => true, 'win_condition' => 'Last one in the ring']],
    ['royal_rumble', '25-Man', $rumble(25), [], ['gm_bookable' => false, 'titles_allowed' => false, 'is_elimination' => true, 'win_condition' => 'Last one in the ring']],
    // House rule: no Divas in the Royal Rumble or the Survivor Series main event.
    ['royal_rumble', '30-Man', $rumble(30), [], ['ppv_exclusive' => 'royal_rumble', 'divas_allowed' => false, 'titles_allowed' => false, 'is_elimination' => true, 'win_condition' => 'Last one in the ring']],

    // Main events / gimmick matches
    ['three_stages_of_hell', 'Single', $one, [], ['gm_bookable' => false]],

    ['backstage_brawl', 'Backstage Brawl', $one, []],
    ['backstage_brawl', 'Bar Brawl', $one, [], ['cost' => 31_000]],
    ['backstage_brawl', 'Parking Lot Brawl', $one, [], ['cost' => 28_000]],

    ['battle_royal', '4-Man', $f4w, []],
    ['battle_royal', '5-Man', [1, 1, 1, 1, 1], []],
    ['battle_royal', '6-Man', $six, []],
    ['battle_royal', '4-Man Over the Top Rope', $f4w, [], ['win_condition' => 'Over the top rope']],
    ['battle_royal', '5-Man Over the Top Rope', [1, 1, 1, 1, 1], [], ['win_condition' => 'Over the top rope']],
    ['battle_royal', '6-Man Over the Top Rope', $six, [], ['win_condition' => 'Over the top rope']],

    // House rule: bookable on any PPV card.
    // House rule: No Mercy exclusive (SmackDown). The loser is buried: out for a month.
    ['buried_alive', 'Single', $one, [], ['ppv_exclusive' => 'no_mercy', 'brand_restriction' => 'smackdown', 'win_condition' => 'Buried alive']],

    ['elimination_chamber', 'Normal 6-Man Chamber', $six, [], ['ppv_exclusive' => 'new_years_revolution', 'brand_restriction' => 'raw', 'is_elimination' => true]],
    ['elimination_chamber', 'Triple Threat Tornado Tag Chamber', $ttTag, [], ['ppv_exclusive' => 'new_years_revolution', 'brand_restriction' => 'raw', 'is_elimination' => true]],

    ['first_blood', 'Single', $one, [], ['win_condition' => 'First blood']],

    ['fulfill_your_fantasy', 'Single', $one, [], ['divas_only' => true, 'win_condition' => 'Bra & Panties']],

    ['hardcore', 'Single', $one, []],
    ['hardcore', 'Single No Manager vs. w/Manager', $one, $noMgrVsMgr],
    ['hardcore', 'Single w/Manager vs. w/Manager', $one, $mgrVsMgr],
    ['hardcore', 'Tornado Tag', $tag, []],
    ['hardcore', 'Tornado 6-Man Tag', $sixTag, [], ['cost' => 25_000]],
    ['hardcore', 'Triple Threat Tornado Tag', $ttTag, [], ['cost' => 25_000]],
    ['hardcore', 'Triple Threat', $tt, []],
    ['hardcore', 'Fatal 4-Way', $f4w, []],

    ['hell_in_a_cell', 'Single', $one, []],
    ['hell_in_a_cell', 'Tornado Tag', $tag, []],
    ['hell_in_a_cell', 'Triple Threat', $tt, []],
    ['hell_in_a_cell', 'Fatal 4-Way', $f4w, []],
    ['hell_in_a_cell', 'Armageddon Hell in a Cell', $six, [], ['cost' => 55_000]],

    ['iron_man', 'Single', $one, [], ['win_condition' => 'Most falls', 'timed' => true]],
    ['iron_man', 'Single No Manager vs. w/Manager', $one, $noMgrVsMgr, ['win_condition' => 'Most falls', 'timed' => true]],
    ['iron_man', 'Single w/Manager vs. w/Manager', $one, $mgrVsMgr, ['win_condition' => 'Most falls', 'timed' => true]],

    ['ladder', 'Single', $one, [], ['win_condition' => 'Briefcase retrieval']],
    ['ladder', 'Single No Manager vs. w/Manager', $one, $noMgrVsMgr, ['win_condition' => 'Briefcase retrieval']],
    ['ladder', 'Single w/Manager vs. w/Manager', $one, $mgrVsMgr, ['win_condition' => 'Briefcase retrieval']],
    ['ladder', 'Tornado Tag', $tag, [], ['win_condition' => 'Briefcase retrieval']],
    ['ladder', 'Triple Threat Tornado Tag', $ttTag, [], ['cost' => 16_000, 'win_condition' => 'Briefcase retrieval']],
    ['ladder', 'Triple Threat', $tt, [], ['win_condition' => 'Briefcase retrieval']],
    ['ladder', 'Fatal 4-Way', $f4w, [], ['win_condition' => 'Briefcase retrieval']],
    // House rule: WrestleMania only, 6-man, no titles, no Divas (§10).
    ['ladder', 'Money in the Bank', $six, [], ['ppv_exclusive' => 'wrestlemania', 'titles_allowed' => false, 'divas_allowed' => false, 'win_condition' => 'Briefcase retrieval', 'mitb' => true]],

    ['last_man_standing', 'Single', $one, [], ['win_condition' => '10-count']],
    ['last_man_standing', 'Single No Manager vs. w/Manager', $one, $noMgrVsMgr, ['win_condition' => '10-count']],
    ['last_man_standing', 'Single w/Manager vs. w/Manager', $one, $mgrVsMgr, ['win_condition' => '10-count']],

    // House rule (replaces Slobber Knocker): six entrants, five singles bouts.
    // Entrants 1 and 2 open; each winner faces the next entrant. No titles or stipulations.
    ['gauntlet', '6-Man Gauntlet', $six, []],

    ['special_referee', 'Single', $one, []],
    ['special_referee', 'Tag Match', $tag, []],
    ['special_referee', 'Iron Man', $one, [], ['cost' => 37_000, 'bars' => 5, 'timed' => true]],
    ['special_referee', 'Hell in a Cell', $one, [], ['cost' => 45_000, 'bars' => 5, 'dq' => false, 'countout' => false, 'rope_breaks' => false]],
    ['special_referee', 'Last Man Standing', $one, [], ['cost' => 40_000, 'bars' => 5, 'dq' => false, 'countout' => false]],

    ['steel_cage', 'Single', $one, [], ['win_condition' => 'Cage escape']],
    ['steel_cage', 'Tornado Tag', $tag, [], ['win_condition' => 'Cage escape']],
    ['steel_cage', 'Triple Threat', $tt, [], ['win_condition' => 'Cage escape']],
    ['steel_cage', 'Fatal 4-Way', $f4w, [], ['win_condition' => 'Cage escape']],
    ['steel_cage', 'Single Pin & Give Up', $one, []],
    ['steel_cage', 'Triple Threat Pin & Give Up', $tt, []],
    ['steel_cage', 'Fatal 4-Way Pin & Give Up', $f4w, []],

    ['submission', 'Single', $one, [], ['win_condition' => 'Submission']],
    ['submission', 'Ultimate Submission', $one, [], ['cost' => 2_000, 'win_condition' => 'Submission', 'timed' => true]],

    ['table', 'Single', $one, [], ['win_condition' => 'Put through a table']],
    ['table', 'Tornado Tag', $tag, [], ['win_condition' => 'Put through a table']],
    ['table', 'Triple Threat', $tt, [], ['win_condition' => 'Put through a table']],
    ['table', 'Fatal 4-Way', $f4w, [], ['win_condition' => 'Put through a table']],
    ['table', 'Tornado 6-Man Tag', $sixTag, [], ['cost' => 8_000, 'win_condition' => 'Put through a table']],
    ['table', 'Triple Threat Tornado Tag', $ttTag, [], ['cost' => 8_000, 'win_condition' => 'Put through a table']],

    ['tlc', 'Single', $one, [], []],
    ['tlc', 'Tornado Tag', $tag, [], []],
    ['tlc', 'Triple Threat', $tt, [], []],
    ['tlc', 'Fatal 4-Way', $f4w, [], []],
    // House rule: not in the 2006 game.
    ['tlc', 'Triple Threat Tornado Tag', $ttTag, [], ['cost' => 12_000]],

    // House rule: Survivor Series exclusive, always its main event, no titles.
    ['survivor_series_elimination', 'Traditional 3 vs 3', $sixTag, [], ['ppv_exclusive' => 'survivor_series', 'divas_allowed' => false, 'is_elimination' => true, 'titles_allowed' => false]],
];
