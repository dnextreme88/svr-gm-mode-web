<?php

/*
| Full match type list (§10). Each row: [family, variant, sides, manager_sides, extras].
| Fatigue cost, price and DQ/count-out flags come from config('gm.match_type_defaults')
| unless overridden in extras.
|
| ASSUMPTIONS (§10 says these may not be bookable in GM Mode): 3 Stages of Hell,
| Special Referee and the 10–25-man Royal Rumbles are seeded but not GM-bookable.
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
    // Single Match
    ['single', 'No Manager', $one, []],
    ['single', 'No Manager vs. w/Manager', $one, $noMgrVsMgr],
    ['single', 'w/Manager vs. w/Manager', $one, $mgrVsMgr],

    // Tag Team (2 on 2)
    ['tag_team', 'Normal Tag', $tag, []],
    ['tag_team', 'Tornado Tag', $tag, []],
    ['tag_team', 'Tag vs Tag w/Manager', $tag, $noMgrVsMgr],
    ['tag_team', 'Tag w/Manager vs Tag w/Manager', $tag, $mgrVsMgr],

    // 6-Man Tag
    ['six_man_tag', 'Normal', $sixTag, []],
    ['six_man_tag', 'Tornado', $sixTag, []],

    // Elimination Tag
    ['elimination_tag', 'Normal Tag', $tag, [], ['is_elimination' => true]],
    ['elimination_tag', 'Tornado Tag', $tag, [], ['is_elimination' => true]],
    ['elimination_tag', 'Normal 6-Man Tag', $sixTag, [], ['is_elimination' => true]],
    ['elimination_tag', 'Tornado 6-Man Tag', $sixTag, [], ['is_elimination' => true]],
    ['elimination_tag', 'Table Tornado Tag', $tag, [], ['is_elimination' => true, 'dq' => false, 'countout' => false, 'win_condition' => 'Put through a table']],
    ['elimination_tag', 'Table Tornado 6-Man Tag', $sixTag, [], ['is_elimination' => true, 'dq' => false, 'countout' => false, 'win_condition' => 'Put through a table']],
    ['elimination_tag', 'Captain Fall Tornado 6-Man Tag', $sixTag, [], ['is_elimination' => true]],

    ['triple_threat', 'Normal Triple Threat', $tt, []],
    ['fatal_four_way', 'Normal Fatal 4-Way', $f4w, []],

    // Handicap
    ['handicap', 'One on Two (Tornado)', [1, 2], []],
    ['handicap', 'One on Tag', [1, 2], []],
    ['handicap', 'One on Three (Tornado)', [1, 3], []],
    ['handicap', 'One on Trio', [1, 3], []],
    ['handicap', 'Two on Trio', [2, 3], []],

    // Royal Rumble — only the 30-man is bookable, and only on its PPV (house rule)
    ['royal_rumble', '10-Man', $rumble(10), [], ['gm_bookable' => false, 'titles_allowed' => false, 'win_condition' => 'Last one in the ring']],
    ['royal_rumble', '15-Man', $rumble(15), [], ['gm_bookable' => false, 'titles_allowed' => false, 'win_condition' => 'Last one in the ring']],
    ['royal_rumble', '20-Man', $rumble(20), [], ['gm_bookable' => false, 'titles_allowed' => false, 'win_condition' => 'Last one in the ring']],
    ['royal_rumble', '25-Man', $rumble(25), [], ['gm_bookable' => false, 'titles_allowed' => false, 'win_condition' => 'Last one in the ring']],
    ['royal_rumble', '30-Man', $rumble(30), [], ['ppv_exclusive' => 'royal_rumble', 'titles_allowed' => false, 'win_condition' => 'Last one in the ring']],

    // Main events / gimmick matches
    ['three_stages_of_hell', 'Single', $one, [], ['gm_bookable' => false]],

    ['backstage_brawl', 'Backstage Brawl', $one, []],
    ['backstage_brawl', 'Bar Brawl', $one, []],
    ['backstage_brawl', 'Parking Lot Brawl', $one, []],

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
    ['elimination_chamber', 'Tornado 6-Man Tag Chamber', $ttTag, [], ['ppv_exclusive' => 'new_years_revolution', 'brand_restriction' => 'raw', 'is_elimination' => true]],

    ['first_blood', 'Single', $one, [], ['win_condition' => 'First blood']],

    ['fulfill_your_fantasy', 'Single', $one, [], ['divas_only' => true, 'win_condition' => 'Bra & Panties']],

    ['hardcore', 'Single', $one, []],
    ['hardcore', 'Single No Manager vs. w/Manager', $one, $noMgrVsMgr],
    ['hardcore', 'Single w/Manager vs. w/Manager', $one, $mgrVsMgr],
    ['hardcore', 'Tornado Tag', $tag, []],
    ['hardcore', 'Tornado 6-Man Tag', $sixTag, []],
    ['hardcore', 'Triple Threat Tornado Tag', $ttTag, []],
    ['hardcore', 'Triple Threat', $tt, []],
    ['hardcore', 'Fatal 4-Way', $f4w, []],

    ['hell_in_a_cell', 'Single', $one, []],
    ['hell_in_a_cell', 'Tornado Tag', $tag, []],
    ['hell_in_a_cell', 'Triple Threat', $tt, []],
    ['hell_in_a_cell', 'Fatal 4-Way', $f4w, []],
    ['hell_in_a_cell', 'Armageddon HIAC (6-Man)', $six, []],

    ['iron_man', 'Single', $one, [], ['win_condition' => 'Most falls']],
    ['iron_man', 'Single No Manager vs. w/Manager', $one, $noMgrVsMgr, ['win_condition' => 'Most falls']],
    ['iron_man', 'Single w/Manager vs. w/Manager', $one, $mgrVsMgr, ['win_condition' => 'Most falls']],

    ['ladder', 'Single', $one, [], ['win_condition' => 'Ladder retrieval']],
    ['ladder', 'Single No Manager vs. w/Manager', $one, $noMgrVsMgr, ['win_condition' => 'Ladder retrieval']],
    ['ladder', 'Single w/Manager vs. w/Manager', $one, $mgrVsMgr, ['win_condition' => 'Ladder retrieval']],
    ['ladder', 'Tornado Tag', $tag, [], ['win_condition' => 'Ladder retrieval']],
    ['ladder', 'Triple Threat Tornado Tag', $ttTag, [], ['win_condition' => 'Ladder retrieval']],
    ['ladder', 'Triple Threat', $tt, [], ['win_condition' => 'Ladder retrieval']],
    ['ladder', 'Fatal 4-Way', $f4w, [], ['win_condition' => 'Ladder retrieval']],
    // House rule: WrestleMania only, 6-man, no titles, no Divas (§10).
    ['ladder', 'Money in the Bank', $six, [], ['ppv_exclusive' => 'wrestlemania', 'titles_allowed' => false, 'divas_allowed' => false, 'win_condition' => 'Briefcase retrieval', 'mitb' => true]],

    ['last_man_standing', 'Single', $one, [], ['win_condition' => '10-count']],
    ['last_man_standing', 'Single No Manager vs. w/Manager', $one, $noMgrVsMgr, ['win_condition' => '10-count']],
    ['last_man_standing', 'Single w/Manager vs. w/Manager', $one, $mgrVsMgr, ['win_condition' => '10-count']],

    // ASSUMPTION: "No Limit" is booked as a 1-on-1 gauntlet within the 2–6 limit.
    ['slobber_knocker', 'No Limit', $one, [], ['win_condition' => 'Most opponents beaten']],

    ['special_referee', 'Single', $one, [], ['gm_bookable' => false]],
    ['special_referee', 'Tag Match', $tag, [], ['gm_bookable' => false]],
    ['special_referee', 'Iron Man', $one, [], ['gm_bookable' => false]],
    ['special_referee', 'Hell in a Cell', $one, [], ['gm_bookable' => false, 'dq' => false, 'countout' => false]],
    ['special_referee', 'Last Man Standing', $one, [], ['gm_bookable' => false, 'dq' => false, 'countout' => false]],

    ['steel_cage', 'Single', $one, [], ['win_condition' => 'Cage escape']],
    ['steel_cage', 'Tornado Tag', $tag, [], ['win_condition' => 'Cage escape']],
    ['steel_cage', 'Triple Threat', $tt, [], ['win_condition' => 'Cage escape']],
    ['steel_cage', 'Fatal 4-Way', $f4w, [], ['win_condition' => 'Cage escape']],
    ['steel_cage', 'Single Pin & Give Up', $one, []],
    ['steel_cage', 'Triple Threat Pin & Give Up', $tt, []],
    ['steel_cage', 'Fatal 4-Way Pin & Give Up', $f4w, []],

    ['submission', 'Single', $one, [], ['win_condition' => 'Submission']],
    ['submission', 'Ultimate Submission', $one, [], ['win_condition' => 'Submission']],

    ['table', 'Single', $one, [], ['win_condition' => 'Put through a table']],
    ['table', 'Tornado Tag', $tag, [], ['win_condition' => 'Put through a table']],
    ['table', 'Triple Threat', $tt, [], ['win_condition' => 'Put through a table']],
    ['table', 'Fatal 4-Way', $f4w, [], ['win_condition' => 'Put through a table']],
    ['table', 'Tornado 6-Man Tag', $sixTag, [], ['win_condition' => 'Put through a table']],
    ['table', 'Triple Threat Tornado Tag', $ttTag, [], ['win_condition' => 'Put through a table']],

    ['tlc', 'Single', $one, [], ['win_condition' => 'Ladder retrieval']],
    ['tlc', 'Tornado Tag', $tag, [], ['win_condition' => 'Ladder retrieval']],
    ['tlc', 'Triple Threat', $tt, [], ['win_condition' => 'Ladder retrieval']],
    ['tlc', 'Fatal 4-Way', $f4w, [], ['win_condition' => 'Ladder retrieval']],
    // House rule: not in the 2006 game.
    ['tlc', 'Triple Threat Tornado Tag', $ttTag, [], ['win_condition' => 'Ladder retrieval']],

    // House rule: Survivor Series exclusive, always its main event, no titles.
    ['survivor_series_elimination', 'Traditional 3 vs 3', $sixTag, [], ['ppv_exclusive' => 'survivor_series', 'is_elimination' => true, 'titles_allowed' => false]],
];
