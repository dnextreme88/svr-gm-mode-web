<?php

/*
|--------------------------------------------------------------------------
| GM Mode tunables
|--------------------------------------------------------------------------
|
| Every odds value, threshold, duration and price used by the game lives
| here (CLAUDE.md). Values the spec marks TBD, "to confirm" or leaves
| unspecified are tagged ASSUMPTION so they're easy to find and retune.
| Section numbers refer to docs/20261017-spec.md.
|
*/

return [

    /*
    | §1 Setup
    */
    'starting_budget' => 15_000_000,
    'roster_cap' => 30,
    'initial_draft_max_picks' => 30,

    /*
    | §13 Calendar. A season is 52 weeks of Sundays on the 2005 calendar: it
    | starts the week after WrestleMania (Apr 3) and ends on WrestleMania
    | (Mar 27). Jan–Mar use 2005's Sundays too, so every PPV lands on its
    | listed date. Each PPV's season week is set below.
    |
    | A week starts on its Sunday: PPVs air that Sunday, then each brand's
    | weekly show airs on its night later that week (Raw Monday, SmackDown
    | Friday). Every season runs April 2005 to March 2006.
    */
    'calendar' => [
        'weeks_per_season' => 52,
        'season_start_reference' => '2005-04-03',
        'weekly_show_days' => [
            'raw' => 'Monday',
            'smackdown' => 'Friday',
        ],
        'ppv_weeks' => [
            'backlash' => 4, // Apr 24
            'judgment_day' => 8, // May 22
            'vengeance' => 13, // Jun 26
            'great_american_bash' => 17, // Jul 24
            'summerslam' => 21, // Aug 21
            'unforgiven' => 25, // Sep 18
            'no_mercy' => 28, // Oct 9
            'cyber_sunday' => 32, // Nov 6
            'survivor_series' => 35, // Nov 27
            'armageddon' => 38, // Dec 18
            'tribute_to_the_troops' => 39, // Dec 25 (Raw only)
            'new_years_revolution' => 41, // Jan 8, 2006
            'royal_rumble' => 44, // Jan 29, 2006
            'no_way_out' => 47, // Feb 19, 2006
            'wrestlemania' => 52, // Mar 26, 2006
        ],
    ],

    /*
    | §4 Card building
    */
    'card' => [
        'weekly_match_slots' => 6,
        'weekly_promo_slots' => 2,
        'ppv_match_slots' => 5,
        'ppv_promo_slots' => 0,
        'min_competitors' => 2,
        // House rule (2026-11-17): no upper limit on a slot's competitors
        // (it was 6) now that Tornado Tag Turmoil books 10. These categories
        // only ever fill their PPV's fixed main event (the 30-man Royal
        // Rumble, the Survivor Series 5 vs 5), never an ordinary slot.
        'fixed_only_families' => ['royal_rumble', 'survivor_series_elimination'],
        'wrestlemania_brand_slots' => 3,
        // ASSUMPTION: how often Randomize/Preset try to make a slot a title match (§24).
        'randomize_title_chance' => 0.35,
        // House rule: Randomize and Preset never pick a competitor the match
        // would leave above this fatigue (%), so they don't risk injuries.
        'randomize_max_fatigue' => 70,
        // House rule: a Money in the Bank cash-in (before or after a match)
        // is the one match that can go past a card's slots: 7 on a weekly
        // show, 6 on a PPV. The GM still books 6 / 5.
        'cash_in_extra_slots' => 1,
        // House rule (2026-11-15): Preset books up to this many of the
        // brand's active rivalries, each in a shape drawn by these weights
        // (a shape that can't be filled falls back to the next, then a 1v1).
        'rivalry_target' => 3,
        'rivalry_shapes' => [
            'singles' => 30,
            'triple_threat' => 20,
            'partners' => 20,
            'manager' => 15,
            'two_rivalries' => 15,
        ],
        // ASSUMPTION: Randomize (one slot) books a rivalry not on the card yet this often.
        'randomize_rivalry_chance' => 0.5,
        // House rule (2026-11-15): sides are gender-balanced. A match type
        // that can't be filled that way is re-rolled this many times.
        'gender_rerolls' => 6,
    ],

    /*
    | §2 Contracts (house rule, 2026-11-15). A superstar's salary is seeded
    | per roster type (`wrestlers.monthly_salary`: superstars $20,000, Divas
    | $12,000, Legends $45,000 a month). A contract costs that × its months,
    | paid in equal weekly cuts; nothing is charged up front. Releasing a
    | superstar early pays out the weeks left (weekly × weeks remaining).
    */
    'contracts' => [
        // Contract length in weeks => months it's priced as.
        'months' => [5 => 1, 12 => 3, 24 => 6, 36 => 9, 52 => 12],
        'draft_default_length' => 52,
        // "Use WWE roster" signs everyone for 6 months.
        'wwe_roster_length' => 24,
        'expiring_threshold_weeks' => 4,
    ],

    /*
    | §7 Financials (house rule, 2026-11-15). Each brand pays for every show
    | night it's on, a weekly rehab bill for its injured superstars, and
    | earns its gate (see attendance) plus a bonus for the week's bigger
    | crowd. Deficit enforcement kicks in after this many weeks below $0.
    */
    'finances' => [
        // Production cost per brand per night: a weekly show, a PPV night.
        'show_cost' => ['weekly' => 50_000, 'ppv' => 100_000],
        // Medical rehab per injured superstar per week.
        'medical' => ['minor' => 5_000, 'serious' => 10_000],
        // To the brand whose nights drew the bigger crowd that week (a tie pays nobody).
        'attendance_bonus' => 100_000,
        'deficit_weeks_limit' => 4,
    ],

    /*
    | Fan attendance (house rule, 2026-11-01). Once a show (or a PPV night,
    | all its cards together) airs, it gets a 0–100 draw score, turned into a
    | crowd within its range: min + (max - min) × score / 100. Every number
    | here is an ASSUMPTION to tune.
    */
    'attendance' => [
        'ranges' => [
            'weekly' => [5_000, 20_000],
            'brand_ppv' => [10_000, 30_000],
            // Royal Rumble, WrestleMania, SummerSlam, Survivor Series.
            'shared_ppv' => [20_000, 50_000],
        ],
        // Points per factor, in priority order (max 100 together).
        'points' => [
            // Match 1 booked, the Main Event booked, every match slot booked.
            'full_card' => ['match_one' => 3, 'main_event' => 3, 'every_slot' => 4],
            // What's in the Main Event and Match 1: a world title / any other
            // title on the line, a gimmick match, two rivals facing each other.
            'main_event' => ['world_title' => 10, 'other_title' => 6, 'gimmick' => 5, 'rivals' => 5],
            'match_one' => ['world_title' => 7, 'other_title' => 4, 'gimmick' => 4, 'rivals' => 4],
            // Each rivalry at hot_heat or more whose rivals meet on the card.
            'hot_rivalry' => 10,
            'hot_rivalry_max' => 20,
            // Legends booked (competing, managing or refereeing): one, two or more.
            'legends' => [1 => 8, 2 => 15],
            // Each promo on its own (house rule, 2026-11-08). Interference and
            // Slander: a success adds, a backfire takes away, a plain failure
            // is 0 (ASSUMPTION). Other promos with a success chance below 100%
            // (Advertising, Interview Space, Merchandise Sponsorship): a
            // success adds, a failure takes away. Promos that always succeed: 0.
            'rival_promos' => ['success' => 10, 'backfire' => -10],
            'other_promos' => ['success' => 6, 'failure' => -2],
            // The best win streak going into the week among those booked.
            'win_streak' => [5 => 5, 10 => 10],
            // House rule (2026-11-08): each match slot of a weekly show in the
            // same match category as that slot on the brand's weekly show the
            // week before costs this much, up to repeat_max.
            'repeat_slot' => 2,
            'repeat_max' => 12,
        ],
        'hot_heat' => 60,
        // The score moves by up to this many points either way (seeded).
        'noise' => 5,
        // Gate = attendance × ticket price. House rule (2026-11-15): the gate
        // is each brand's show income (a shared night's split between them).
        // Prices tuned 2026-11-15 so weekly shows about break even and PPVs carry
        // the profit (a season of careful booking nets a few million).
        'ticket_prices' => ['weekly' => 20, 'brand_ppv' => 35, 'shared_ppv' => 50],
        'drives_income' => true,
    ],

    /*
    | §6 Fatigue & injury. Fatigue is stored 0–100 in steps of 10.
    */
    'fatigue' => [
        'bar_size' => 10,
        'max' => 100,
        'safe_max' => 70,
        'serious_at' => 100,
        'rest_recovery_bars' => 3,
        // §4 to confirm: managing adds no fatigue.
        'manager_fatigue_bars' => 0,
    ],

    'injuries' => [
        // House rule (2026-10-25): injuries are judged on fatigue right after
        // the match. Ending at 80–100% always injures: this share is serious,
        // the rest minor. Going over 100% is always serious.
        'serious_share' => 0.20,
        // ASSUMPTION: escalation odds are TBD in the spec.
        'escalation_chance' => 0.20,
        'minor_max_weeks' => 5,
        'serious_min_weeks' => 5,
        'serious_max_weeks' => 52,
    ],

    /*
    | Story text (house rule, 2026-11-11): news summaries, story texts and
    | email bodies run 1 to max_paragraphs paragraphs of paragraph_min to
    | paragraph_max sentences each. Headlines, subjects and ticker lines are
    | exempt. Checked by `App\Support\Text\StoryText`.
    */
    'story_text' => [
        'paragraph_min' => 5,
        'paragraph_max' => 8,
        'max_paragraphs' => 3,
    ],

    /*
    | §20 / §21 Match outcomes (placeholder model).
    */
    'outcomes' => [
        'base_side_weight' => 1.0,
        // ASSUMPTION: specialty bonus amount is TBD.
        'specialty_bonus_weight' => 1.0,
        // ASSUMPTION: clean-finish chance is TBD.
        'clean_finish_chance' => 0.60,
        'finish_event_weights' => [
            'referee_attacked' => 1,
            'rival_interference' => 1,
            'foreign_object' => 1,
            'countout' => 1,
            'cheap_rollup' => 1,
        ],
        // Chance a clean finish uses the match's own win condition instead
        // of a pinfall/submission (only where the type has one).
        'match_specific_chance' => 0.85,
        'submission_chance' => 0.25,
        // ASSUMPTION: with rope breaks on, a wrestler can reach the ropes to
        // escape a hold, so clean submission finishes happen less often.
        'rope_break_submission_factor' => 0.6,
        // House rule (2026-10-25): a match the player decides with Book to Win
        // ends by DQ or count-out this often (where the type allows them).
        // A booked title match always ends decisively.
        'booked_non_decisive_chance' => 0.05,
    ],

    /*
    | Match type defaults by family (seeded into match_types).
    | Fatigue bars are house rules (2026-10-19, max 5 per match); variants
    | that differ from their family override `bars` in the data file.
    | ASSUMPTION: the spec gives no DQ flags.
    | Prices are house rules (2026-10-20); variants that cost something
    | different override `cost` in the data file.
    */
    'match_type_defaults' => [
        'single' => ['bars' => 1, 'cost' => 0, 'dq' => true, 'countout' => true],
        'tag_team' => ['bars' => 1, 'cost' => 0, 'dq' => true, 'countout' => true],
        'six_man_tag' => ['bars' => 2, 'cost' => 0, 'dq' => true, 'countout' => true],
        'handicap' => ['bars' => 1, 'cost' => 1_000, 'dq' => true, 'countout' => true],
        'royal_rumble' => ['bars' => 5, 'cost' => 25_000, 'dq' => false, 'countout' => false],
        'three_stages_of_hell' => ['bars' => 5, 'cost' => 25_000, 'dq' => true, 'countout' => true],
        'backstage_brawl' => ['bars' => 4, 'cost' => 16_000, 'dq' => false, 'countout' => false],
        'battle_royal' => ['bars' => 4, 'cost' => 25_000, 'dq' => false, 'countout' => false],
        'buried_alive' => ['bars' => 5, 'cost' => 25_000, 'dq' => false, 'countout' => false],
        'elimination_chamber' => ['bars' => 5, 'cost' => 50_000, 'dq' => false, 'countout' => false],
        'first_blood' => ['bars' => 3, 'cost' => 1_000, 'dq' => false, 'countout' => false],
        'fulfill_your_fantasy' => ['bars' => 1, 'cost' => 22_000, 'dq' => true, 'countout' => true],
        'hardcore' => ['bars' => 3, 'cost' => 19_000, 'dq' => false, 'countout' => false],
        'hell_in_a_cell' => ['bars' => 5, 'cost' => 45_000, 'dq' => false, 'countout' => false],
        // House rule (2026-11-07).
        'inferno' => ['bars' => 4, 'cost' => 45_000, 'dq' => false, 'countout' => false],
        // House rule (2026-11-08): count-outs happen (and count as falls).
        'iron_man' => ['bars' => 5, 'cost' => 37_000, 'dq' => true, 'countout' => true],
        'ladder' => ['bars' => 3, 'cost' => 12_000, 'dq' => false, 'countout' => false],
        'last_man_standing' => ['bars' => 4, 'cost' => 40_000, 'dq' => false, 'countout' => false],
        // House rule: five singles bouts; every entrant takes a flat 3 bars.
        'gauntlet' => ['bars' => 3, 'cost' => 5_000, 'dq' => true, 'countout' => true],
        'special_referee' => ['bars' => 2, 'cost' => 0, 'dq' => true, 'countout' => true],
        'steel_cage' => ['bars' => 4, 'cost' => 25_000, 'dq' => false, 'countout' => false],
        'submission' => ['bars' => 2, 'cost' => 1_000, 'dq' => true, 'countout' => false],
        'table' => ['bars' => 3, 'cost' => 5_000, 'dq' => false, 'countout' => false],
        'tlc' => ['bars' => 3, 'cost' => 8_000, 'dq' => false, 'countout' => false],
        'survivor_series_elimination' => ['bars' => 3, 'cost' => 0, 'dq' => true, 'countout' => true],
    ],
    'mitb_match' => ['bars' => 4, 'cost' => 50_000],

    /*
    | House rules on which match types allow what (seeded into match_types).
    | - Rope breaks and count-outs: off in these families.
    | - Ring outs (the fight can leave the ring): off in these families.
    | - Titles can't be defended in these families, or with these side shapes
    |   (3 vs 3: six active competitors; managers don't count).
    | - Stipulations can't be added in these families (the Gauntlet).
    */
    'match_rules' => [
        'no_rope_break_families' => ['hardcore', 'tlc', 'table', 'ladder', 'battle_royal', 'royal_rumble', 'hell_in_a_cell'],
        'no_ring_out_families' => ['steel_cage', 'battle_royal', 'royal_rumble', 'elimination_chamber', 'inferno'],
        'no_titles_families' => ['handicap', 'gauntlet'],
        'no_titles_sides' => [[3, 3]],
        // A Gauntlet takes only these stipulations (house rule): a #1
        // Contender's Match, whose Gauntlet winner becomes the contender.
        'family_stipulations' => ['gauntlet' => ['number_one_contender']],
        // Six-competitor variants of these families cost more fatigue.
        'six_competitor_bars' => ['hardcore' => 4, 'ladder' => 4, 'tlc' => 4, 'table' => 4],
    ],

    /*
    | §15 Morale thresholds per complaint: [content, unsatisfied, upset].
    */
    'morale' => [
        'losing_streak' => [4, 7, 9],
        'contract_extension' => [10, 15, 18],
        // 1 month = 4 weeks.
        'title_match' => [5, 8, 12],
        'inactivity' => [4, 7, 9],
        // ASSUMPTION: current champions don't raise title-match demands.
        'champions_exempt_from_title_demand' => true,
        // House rule (2026-11-20): appearing in one of these promos counts as
        // being used for the week (the Inactivity complaint), success or not.
        'promos_count_as_used' => ['interference_promotion', 'diva_bikini_contest'],
    ],

    /*
    | §8 Defection. ASSUMPTION: odds are TBD.
    */
    'defection' => [
        'chance' => [
            'unsatisfied' => 0.10,
            'upset' => 0.25,
        ],
        'blocked_sit_out_weeks' => 2,
    ],

    /*
    | Buried Alive (house rule, No Mercy exclusive). The loser can't be booked
    | for a month, takes no injury from the match, and their contract pauses
    | (no weeks come off it, no upkeep) while they're buried.
    */
    'buried_alive' => [
        'sit_out_weeks' => 4,
        'pause_upkeep' => true,
    ],

    /*
    | §1 Initial draft (house rules). Legends can't be drafted; they can be
    | signed from Free Agency afterwards. The rival GM rolls 1..roll_max on
    | each pick; a roll of focus_from or higher makes it go after its
    | brand's colour-coded class first (Raw: Divas, SmackDown:
    | Cruiserweights and Light Heavyweights), when any are affordable.
    */
    'draft' => [
        'legends_draftable' => false,
        'ai_roll_max' => 100,
        'ai_focus_from' => 61, // 61-100: 40% of rolls
    ],

    /*
    | §8 Trades.
    */
    'trades' => [
        'max_per_side' => 5,
        // The rival GM's answers are in `ai.trades`.
    ],

    /*
    | §16 Titles.
    */
    'titles' => [
        // §16 to confirm: only serious injuries vacate. Set to an integer
        // (e.g. 3) to vacate on any injury longer than that many weeks.
        'vacate_on_injury_over_weeks' => null,
        'vacate_on_serious_injury' => true,
        // House rule: when the rival's title is vacated while it has a #1
        // contender, the rival awards it to them (the player is asked).
        // ASSUMPTION.
        'ai_award_contender_on_vacancy' => true,
        // House rule (2026-11-07): the former champion(s) of a title lost in a
        // match are owed a rematch within this many weeks (Active Effect only).
        'rematch_clause_weeks' => 4,
    ],

    /*
    | Conditional match stories (house rule, 2026-11-07): things that happen
    | in a simulated match (never Book to Win) because of who's in it. The
    | helper (Hornswoggle, Undertaker, Kane) must be on that brand, not
    | seriously injured or sitting out, and not in the match. Wrestlers are
    | catalog slugs; factions are seeded faction names.
    */
    'stories' => [
        // Off only in tests that don't want them (tests/Pest.php).
        'enabled' => true,
        // Result-changing stories, tried in this order; one per match.
        'order' => ['undertaker_lights_out', 'kane_pyro', 'dudley_tables', 'hardy_ladder'],
        // The stories that change results never happen in these families.
        'excluded_families' => ['royal_rumble', 'elimination_chamber', 'steel_cage', 'hell_in_a_cell'],
        'shillelagh' => [
            'finlay' => 'fit-finlay',
            'helper' => 'hornswoggle',
            // When Finlay wins decisively.
            'chance' => 0.5,
            // A trigger in week 4 can happen again from week 12.
            'lockout_weeks' => 8,
        ],
        'lights_out' => [
            'helper' => 'undertaker',
            // ASSUMPTION: "heat 60" means 60 or more, going into the match.
            'min_heat' => 60,
            // The rival loses this often; else they win anyway (the failure story).
            'win_chance' => 0.8,
        ],
        'pyro' => [
            'helper' => 'kane',
            'min_heat' => 60,
            'win_chance' => 0.8,
        ],
        'tables' => [
            'faction' => 'Dudley Boyz',
            'members' => ['bubba-ray-dudley', 'd-von-dudley', 'spike-dudley'],
            // The table spot lands this often; then they win at `win_chance`
            // (a Tables match: always).
            'land_chance' => 0.8,
            'win_chance' => 0.8,
            'win_family' => 'table',
            // After a spot that lands.
            'cooldown_weeks' => 4,
        ],
        'ladder' => [
            'faction' => 'Team Xtreme',
            'members' => ['jeff-hardy', 'matt-hardy'],
            'lita' => 'lita',
            'land_chance' => 0.8,
            'win_chance' => 0.8,
            'win_family' => 'ladder',
            'cooldown_weeks' => 4,
        ],
    ],

    /*
    | §22 Stipulations.
    */
    'stipulations' => [
        'loser_leaves_resign_lockout_weeks' => 4,
        'last_chance_min_meetings' => 2,
    ],

    /*
    | §10 Money in the Bank.
    */
    'mitb' => [
        'expiry_weeks' => 52,
        'expiring_warning_weeks' => 4,
    ],

    /*
    | §13 / §23 PPV rules.
    */
    'ppv' => [
        'royal_rumble_entrants_per_brand' => 15,
        // ASSUMPTION: a brand short of 15 bookable wrestlers enters what it can.
        'royal_rumble_min_entrants_per_brand' => 10,
        // House rule (2026-11-17): 5 a side (it was 3).
        'survivor_series_team_size' => 5,
        // House rule (2026-11-17): Qualifying Matches. In the weeks before
        // Survivor Series (its 5 vs 5 team) and WrestleMania (its Money in
        // the Bank ladder) one match of both brands' weekly shows is a fixed
        // Qualifying Match; the winner takes one of the brand's spots.
        'qualifiers' => [
            // Which match it is, week by week through the run (6 is the
            // weekly show's main event), so it doesn't sit in the same slot
            // every week (fans dislike a repeated category in one slot).
            'slots' => [
                'survivor_series' => [1, 2, 3, 4, 6],
                'wrestlemania' => [1, 3, 6],
            ],
            // Weeks of qualifiers before each PPV (one winner a week each).
            'windows' => ['survivor_series' => 5, 'wrestlemania' => 3],
            // The locked match type: [family, variant].
            'types' => [
                'survivor_series' => ['ladder', 'Triple Threat'],
                'wrestlemania' => ['single', 'Triple Threat'],
            ],
            // The rival GM keeps its qualifiers healthy until the PPV: only
            // these categories, at its careful fatigue limit.
            'rival_safe_families' => ['single', 'tag_team'],
        ],
        // House rule (2026-11-15, raised 2026-11-17): the re-draft swaps
        // this many superstars each way (10 from Raw to Smackdown, 10 back).
        // No roster cap applies.
        'redraft_picks_per_brand' => 10,
        // House rule (2026-11-17): the immunity tag protects this many of the
        // brand's non-champions, and the manual draft pick replaces this
        // many of the series winner's first picks with its own choices.
        'redraft_immunity_count' => 3,
        'redraft_manual_picks' => 2,
        // House rule (2026-11-15): how a Royal Rumble plays out for its log
        // (entry numbers and eliminations). After each arrival someone is
        // thrown out at this chance, and always while the ring holds more
        // than ring_cap. ASSUMPTION: both numbers.
        'rumble' => [
            'elimination_chance' => 0.6,
            'ring_cap' => 6,
        ],
    ],

    /*
    | §5 Promos. Success rates are starting estimates (house rule).
    */
    'promos' => [
        // House rule (2026-10-25): every promo succeeds except these.
        // Merchandise Sponsorship's odds come with its rolled tier below.
        'success_rates' => [
            'new_superstar' => 1.00,
            'continue_rivalry' => 1.00,
            'start_rivalry' => 1.00,
            'inactive_superstar' => 1.00,
            // House rule (2026-11-15): Advertising always pays.
            'advertising' => 1.00,
            'merchandise_sponsorship' => 0.80,
            'main_event_hype' => 1.00,
            'ppv_hype' => 1.00,
            'interference_promotion' => 0.50,
            'slander_promotion' => 0.50,
            'title_match_hype' => 1.00,
            'comeback_reminder' => 1.00,
            'interview_space' => 0.90,
            'diva_bikini_contest' => 1.00,
            'call_out' => 0.80,
            // House rule (2026-11-20).
            'speed_up_injury' => 0.80,
        ],
        'amounts' => [
            'new_superstar' => -50_000,
            'continue_rivalry' => -2_000,
            'start_rivalry' => 0,
            'inactive_superstar' => -2_000,
            // Paid the week it airs.
            'advertising' => 200_000,
            'merchandise_sponsorship' => 0,
            'main_event_hype' => -50_000,
            'ppv_hype' => -10_000,
            'interference_promotion' => -50_000,
            'slander_promotion' => -50_000,
            'title_match_hype' => -10_000,
            'comeback_reminder' => -2_000,
            'interview_space' => -2_000,
            'diva_bikini_contest' => -10_000,
            'call_out' => -10_000,
            'speed_up_injury' => -15_000,
        ],
        // House rule: these promos tire their wrestlers (and can injure them),
        // rolled at the point in the show where the promo airs.
        'fatigue_bars' => [
            'interference_promotion' => 2,
            'start_rivalry' => 2,
            // House rule (2026-10-25): both Divas and both rivals take part.
            'diva_bikini_contest' => 2,
            'continue_rivalry' => 1,
        ],
        // House rule (2026-11-20): these promos still tire their wrestlers
        // but never injure them (no new injury, no minor one escalating).
        'injury_exempt' => ['continue_rivalry'],
        // House rule (2026-11-20): Speed-up Injury Process. A success heals
        // a minor injury outright, or cuts a serious one by 5 weeks; a
        // failure cuts either by 2 weeks.
        'speed_up_injury' => [
            'serious_success_weeks' => 5,
            'fail_weeks' => 2,
        ],
        // Extra end-of-week recovery a successful promo gives its target.
        'recovery_bars' => [
            'inactive_superstar' => 2,
        ],
        // Comeback Reminder: the wrestler has been out more than this many weeks.
        'comeback_reminder_min_weeks_out' => 3,
        // ASSUMPTION: "freshly signed/traded-in" window for New Superstar.
        'new_superstar_window_weeks' => 2,
        // House rule (2026-10-31): Slander and Interference each backfire
        // automatically on their 4th use within 5 weeks (this week and the 4
        // before it). Every use counts, two on one card included; the first
        // `max_uses` roll their normal success rate. Each promo counts on its own.
        'overuse' => [
            'window_weeks' => 5,
            'max_uses' => 3,
        ],
        // House rule (2026-11-15): Merchandise Sponsorship is a gamble. When
        // it airs it draws a success chance and, separately, a payout, each
        // with equal odds. A success pays the payout; a failure pays nothing.
        'merchandise_chances' => [0.80, 0.70, 0.50, 0.25],
        'merchandise_payouts' => [10_000, 25_000, 50_000, 100_000],
        // House rule (2026-11-15): Interference Promotion's result on top of
        // its cost: a success earns, a failure or backfire costs extra.
        'interference_payout' => ['success' => 100_000, 'failure' => -25_000],
    ],

    /*
    | Rivalries (§3, §19). ASSUMPTION: the spec doesn't define heat.
    */
    'rivalries' => [
        'auto_create_meetings' => 2,
        'auto_create_window_weeks' => 4,
        // House rule (2026-11-12): heat is settled once a week, after the
        // shows (RivalryService::settleWeek). The rivals share a match when
        // both compete in it, as opponents or partners, whoever wins.
        'start_heat' => 10,
        // A week the rivals share a match on a weekly show.
        'heat_per_meeting' => 10,
        // A PPV the rivals share a match at (on top of a weekly show meeting).
        'heat_per_ppv_meeting' => 20,
        // House rule (2026-11-11): a rival who runs in and costs their rival
        // the match (Rival Interference finish) heats it up too.
        'heat_per_interference' => 10,
        // House rule (2026-11-15): a successful Call-out promo heats it up.
        'heat_per_call_out' => 10,
        // A week with no shared match loses this much, unless a Continue
        // Rivalry promo for it aired (no heat either way); at 0 or below the
        // rivalry ends.
        'idle_decay' => 20,
        'heating_up_threshold' => 50,
        // Reaching this ends the rivalry the following week, with a headline.
        'max_heat' => 100,
        // House rule (2026-11-20): the roles that count as the rivals sharing
        // a match. A rival who manages or is the Special Referee in a match
        // the other is in keeps the rivalry going (a full meeting). Starting
        // a rivalry from meetings still counts competitors only.
        // ASSUMPTION: it counts as a meeting, not just a hold.
        'meeting_roles' => ['competitor', 'manager', 'referee'],
        // House rule (2026-11-20): the heat bar's colours on /rivalries:
        // yellow below the first, orange below the second, red from it.
        'heat_colors' => [50, 80],
    ],

    /*
    | §19 News thresholds.
    */
    /*
    | Factions (house rule): named tag teams and stables. Any 2+ members of an
    | active faction on one side are named as the faction. 2 or 3 male
    | wrestlers who win `formation_wins` times together as the whole winning
    | side form a new one: the player names theirs, the rival GM draws a name.
    */
    'factions' => [
        'min_members' => 2,
        'max_members' => 5,
        'formation_wins' => 3,
        'formation_max_size' => 3,
        'name_max_length' => 40,
        // ASSUMPTION: the rival GM's names for the factions it forms.
        'ai_names' => [
            'The Enforcers', 'The Dynasty', 'The Syndicate', 'Brute Force', 'The Outlaws',
            'The Wrecking Crew', 'The Regime', 'Thunder & Lightning', 'The Vanguard', 'The Hitmen',
            'The Renegades', 'The Iron Brigade', 'The Collective', 'Black Label', 'The Bruisers',
            'The Mavericks', 'The Elite Guard', 'Fire & Ice', 'The Crusaders', 'The Order',
        ],
    ],

    'news' => [
        // House rule (2026-11-17): a win streak makes the news three times,
        // at 10, 15 and 20 wins, always as a headline. A snapped streak
        // makes the news only when it had reached 10.
        'win_streaks' => [10, 15, 20],
        'win_streak_headline_at' => 10,
        'streak_snapped_min' => 10,
        'losing_streak_min' => 4,
        'reign_milestones' => [10, 25, 52],
        'ppv_upcoming_weeks' => [2, 1],
        // House rule (2026-10-25): a PPV's main event takes the WWE headline
        // that week. The interbrand card's (Survivor Series, WrestleMania)
        // outranks a brand PPV's; the Royal Rumble result sits at 90.
        'ppv_main_event_priority' => ['interbrand' => 95, 'brand' => 85],
        // House rule (2026-11-01): the week's biggest crowd. It takes the WWE
        // headline after a PPV main event and a Slander/Interference result.
        'attendance_priority' => 70,
        // House rule (2026-10-31): stipulation news. Winner Takes All and
        // #1 Contender's Match outrank the match's plain result.
        'stipulation_priority' => [
            'contender_earned_shot' => 75,
            'champion_survived_challenge' => 60,
            'number_one_contender' => 85,
            'winner_takes_all' => 100,
            'contender_awarded_title' => 95,
            'contendership_voided' => 30,
        ],
        // Which story a Winner Takes All / #1 Contender's Match gets, by weight.
        'winner_takes_all_variants' => ['celebrate' => 80, 'revenge' => 20],
        'number_one_contender_variants' => ['fired_up' => 1, 'staredown' => 1, 'fans' => 1],
        'stories_per_brand' => 2,
        // Deferred: overall ratings aren't designed yet, so upset_win is off.
        'upset_win_overall_margin' => null,
        // Every aired match is a story; headlines and stories are drawn from
        // the week's pool weighted by priority.
        'match_result_priority' => [
            'other' => 12,
            'main_event' => 28,
            'hyped_main_event' => 65,
            // House rule, headline precedence: PPV → title → main event →
            // gimmick. Each step outranks everything below it (a PPV match
            // beats any weekly match, a title match any non-title one, the
            // main event any non-title undercard match), and a gimmick match
            // (anything but a plain singles, tag, 6-man tag or handicap match;
            // Table tag variants are gimmicks) beats a plain one. A DQ or
            // count-out win is smaller news, never enough to flip a step.
            'ppv_bonus' => 60,
            'title_bonus' => 30,
            'gimmick_bonus' => 6,
            'not_decisive_penalty' => 6,
        ],
        'free_agent_signed_priority' => 18,
        'promo_result_priority' => 14,
        // Quiet weeks: where a brand's top champion tours, and how far back
        // the injury report looks (about 6 months).
        'tour_cities' => ['New York', 'Los Angeles', 'Chicago', 'Houston', 'Philadelphia', 'Boston', 'Detroit', 'Atlanta', 'Miami', 'Toronto', 'Las Vegas', 'Seattle', 'Denver', 'St. Louis', 'Pittsburgh', 'Phoenix'],
        'injury_report_weeks' => 26,
        // Interview Space: a streak this long can be what the wrestler talks about.
        'promo_story_streak_from' => 3,
        'contract_expiring_weeks' => 2,
        'contract_expiring_priority' => 16,
        // ASSUMPTION: "great fan feedback" is heat at or above
        // rivalries.heating_up_threshold until Popularity exists.
        'rivalry_milestone_weeks' => 10,
        // Factions (house rule). A faction's tag title win takes its brand's
        // headline like any title change (100).
        'faction_priority' => [
            'formed' => 70,
            'member_added' => 40,
            'title_won' => 100,
            'title_lost' => 45,
            'interbrand_win' => 60,
            'disbanded' => 55,
        ],
    ],

    /*
    | GM emails (JR/Tazz, the rival GM, Vince McMahon, the Trainer and the
    | superstars themselves).
    */
    'emails' => [
        'specialty_tip_every_weeks' => 26,
        // ASSUMPTION: how often the specialties tip names one of your specialists.
        'specialty_mention_chance' => 0.5,
        // House rule (2026-10-31): JR / Tazz remind the GM every N weeks
        // since they last ran the promo, proposed a trade or signed a free
        // agent; doing it restarts the clock.
        'interference_reminder_weeks' => 12,
        'slander_reminder_weeks' => 12,
        'trade_reminder_weeks' => 12,
        'free_agent_reminder_weeks' => 16,
        // ASSUMPTION: Vince's shared-PPV reminder lands 2 weeks out.
        'ppv_reminder_weeks_before' => 2,
        // House rule (2026-11-17): sent at the end of the week before the
        // Qualifying Matches start (Survivor Series's qualifiers run 5 weeks,
        // WrestleMania's 3), so it's in the inbox when the first is booked.
        'ppv_reminder_weeks_overrides' => ['survivor_series' => 6, 'wrestlemania' => 4],
        'low_cash_threshold' => 500_000,
        'low_cash_every_weeks' => 4,
        'contract_reminder_weeks' => 2,
        'injury_return_notice_weeks' => 2,
        // House rule (2026-11-11): JR / Tazz's booking tips. Varied matches
        // every 8 season weeks; Match 1 and the Main Event every 12, offset
        // to season weeks 6, 18, 30, 42 (ASSUMPTION) so they don't land
        // together with the other reminders.
        'variety_tip_weeks' => 8,
        'slot_tip_weeks' => 12,
        'slot_tip_offset' => 6,
        // House rule (2026-11-11): the rival GM writes a week before each
        // shared PPV, polite or arrogant at even odds.
        'rival_ppv_weeks_before' => 1,
        'rival_ppv_polite_chance' => 0.5,
    ],

    /*
    | §3 Email senders.
    */
    'email_senders' => [
        'raw' => 'JR',
        'smackdown' => 'Tazz',
        // ASSUMPTION: placeholder names for the rival brand's GM.
        'rival_gm' => [
            'raw' => 'Raw GM',
            'smackdown' => 'Smackdown GM',
        ],
        'vince' => 'Vince McMahon',
        'trainer' => 'Trainer',
    ],

    /*
    | Rival GM strategy (house rules, 2026-10-25). Only the brand no human
    | runs books this way; the player's Randomize and Preset are unchanged.
    | The low-cash mark is `emails.low_cash_threshold`.
    */
    'ai' => [
        'booking' => [
            // Each match rolls 95/5 for an intergender shape.
            'intergender_chance' => 0.05,
            // House rule (2026-11-08): on a weekly show, each open slot rolls 1-100;
            // 76+ (25%) books the same match category as that slot last week.
            'repeat_category_chance' => 0.25,
            // At least this many all-Diva matches per card, with 2+ bookable Divas.
            'min_diva_matches' => 1,
            // 85% careful (nobody ends the match above 70%), 15% aggressive (up to 110%).
            'careful_chance' => 0.85,
            'careful_max_fatigue' => 70,
            'aggressive_max_fatigue' => 110,
            // ASSUMPTION: aggressive slots prefer types costing at least this many bars.
            'aggressive_min_bars' => 3,
        ],
        'titles' => [
            // ASSUMPTION: the main event's chance of being a title match.
            'main_event_chance' => ['weekly' => 0.6, 'ppv' => 0.9],
            'other_slot_chance' => 0.35,
            // House rule (2026-11-15): the title match it always books on an
            // interbrand PPV is its world title this often, else another of
            // its titles at random.
            'interbrand_world_chance' => 0.80,
            // ASSUMPTION
            'champion_vs_contender_chance' => 0.1,
            // ASSUMPTION (house rule, 2026-11-01): a non-title match that fits a
            // title may become a #1 Contender's Match for it.
            'number_one_contender_chance' => 0.1,
            // ASSUMPTION: once per card, a Winner Takes All between the brand's
            // singles champions (a triple threat at `winner_takes_all_triple_chance`
            // when there are three).
            'winner_takes_all_chance' => ['weekly' => 0.08, 'ppv' => 0.2],
            'winner_takes_all_triple_chance' => 0.5,
            // House rule (2026-11-07): the rival's chance to book a Rematch
            // Clause on each of the weeks after the title changed hands, in
            // order, on its first card of the week. ASSUMPTION: one roll a week.
            'rematch_clause_chances' => [0.5, 0.75, 0.25, 0.5],
        ],
        'promos' => [
            'interference_every_weeks' => 4,
            // After each Slander, the gap to the next one (weeks => chance).
            'slander_gap_weeks' => [3 => 0.10, 5 => 0.30, 6 => 0.60],
            // PPV Hype for its own brand-only PPVs, rolled once per PPV.
            'ppv_hype_weeks_before' => [1 => 0.5, 2 => 0.5],
            'title_hype_weights' => ['world' => 60, 'second' => 30, 'other' => 10],
            // ASSUMPTION: relative odds of the situational promos that fit a week.
            'situational_weights' => [
                'title_match_hype' => 20,
                'new_superstar' => 15,
                'comeback_reminder' => 10,
                'inactive_superstar' => 10,
                'continue_rivalry' => 20,
                // House rule (2026-11-15). ASSUMPTION: as often as Continue Rivalry.
                'call_out' => 20,
                'main_event_hype' => 15,
                'interview_space' => 10,
                'diva_bikini_contest' => 10,
                // House rule (2026-11-20). ASSUMPTION: like Comeback Reminder.
                'speed_up_injury' => 10,
            ],
        ],
        'rivalries' => [
            'max_per_brand' => 7,
            'refill_wait_weeks' => 2,
            // House rule (2026-11-20): it tries to book every active rivalry
            // on every card (was 0.5); one it can't pair on its weekly show
            // gets a Continue Rivalry promo instead.
            'rivalry_match_chance' => 1.0,
            'continue_unpaired' => true,
            // House rule (2026-11-20): a rivalry this hot, with this many
            // matches together, is blown off at its next PPV: 60% a 1v1 on
            // that PPV, else a 1v1 on its next weekly show after it. The
            // rivalry ends once that match airs.
            'blowoff_min_heat' => 60,
            'blowoff_min_matches' => 4,
            'blowoff_ppv_chance' => 0.6,
        ],
        'roster' => [
            // ASSUMPTION: the four numbers below.
            'renew_at_weeks_left' => 4,
            'renew_length' => 52,
            // House rule (2026-11-13): it fills the whole roster, within budget.
            'target_size' => 30,
            'cash_reserve' => 500_000,
            // House rule (2026-11-13): while it has fewer than max_legends,
            // each signing rolls this chance to go for a Legend it can afford.
            'legend_chance' => 0.55,
            // House rule (2026-11-17): the rival GM never holds more than
            // this many Divas, in the draft or Free Agency, by the brand it
            // runs. Raw chases Divas as its focus class and could otherwise
            // fill its roster with them, leaving too few men for the Royal
            // Rumble.
            'max_divas' => ['raw' => 9, 'smackdown' => 6],
            'max_legends' => 3,
            // ASSUMPTION: money promos come first while the budget can't cover
            // the renewals due within this many weeks (on top of renew_at_weeks_left).
            'save_ahead_weeks' => 30,
            // House rule (2026-11-17): it judges each expiring contract on
            // how the superstar did during it (`AiContractJudge`), on the
            // contract's final week. A champion is always re-signed; a
            // losing record (more than losing_share of decided matches
            // lost) lets it expire; title_wins (per contract length in
            // weeks) re-signs title_wins_keep of the time; an open morale
            // complaint for more than complaint_share of the weeks lets
            // complaint_expire of them go; booked in busy_share of the weeks
            // or more re-signs busy_keep; booked in idle_share or fewer lets
            // idle_expire go; anyone else is re-signed default_keep of the
            // time. A re-sign is one of `lengths` (6 or 3 months), at even odds.
            'renewal' => [
                'decide_at_weeks_left' => 1,
                'lengths' => [24, 12],
                'losing_share' => 0.5,
                // House rule (2026-11-17): a losing record lets the contract
                // go this often (was always). Multi-man matches have more
                // losers than winners, so losing records are common.
                'losing_expire' => 0.8,
                'title_wins' => [5 => 1, 12 => 2, 24 => 3, 36 => 3, 52 => 4],
                'title_wins_keep' => 0.8,
                // ASSUMPTION: "complains a lot" is a complaint open more than half the time.
                'complaint_share' => 0.5,
                'complaint_expire' => 0.8,
                'busy_share' => 0.8,
                'busy_keep' => 0.8,
                'idle_share' => 1 / 3,
                'idle_expire' => 0.8,
                'default_keep' => 0.8,
            ],
        ],
        // House rule (2026-10-31): how the rival GM values each wrestler in a
        // trade (AiTradeJudge). There's no Popularity yet, so this is the whole
        // trade value model.
        'trades' => [
            'base_value' => 1,
            'legend_value' => 2,
            'focus_value' => 1,
            // Has any match specialty.
            'specialty_value' => 1,
            'minor_injury_value' => -1,
            // +win_streak_value for every win_streak_step wins in a row.
            'win_streak_step' => 5,
            'win_streak_value' => 1,
            // A flat penalty once the losing streak reaches loss_streak_from.
            'loss_streak_from' => 7,
            'loss_streak_value' => -1,
            // In an active tag team (not a stable) with a partner on the rival's roster.
            'tag_partner_value' => 1,
            // Every current member of an active tag team / stable on the same
            // side of the offer: per complete faction.
            'full_tag_team_value' => 1,
            'full_stable_value' => 2,
            // Reigns with their own brand's world title => points (the highest
            // band reached counts).
            'world_reign_bands' => [1 => 1, 5 => 2, 8 => 3],
            // Has held the same non-world title at least this many times.
            'repeat_title_reigns' => 2,
            'repeat_title_value' => 1,
            'fair_ratio' => 1.0,
            // A fair offer with even points; one where the rival comes out
            // ahead uses fair_ahead_accept (points ahead => chance, the
            // highest band reached counts). Anything else: unfair_accept.
            'fair_accept' => 0.75,
            'fair_ahead_accept' => [1 => 0.9, 2 => 1.0],
            'unfair_accept' => 0.25,
        ],
        // House rule (2026-11-13): stipulations it plans for (AiStipulationPlanner).
        'stipulations' => [
            // A roll every `every_weeks`: `chance` books one (retrying each card
            // until one is eligible), else it waits for the next roll.
            'loser_leaves' => ['every_weeks' => 26, 'chance' => 0.85],
            'last_chance' => ['every_weeks' => 17, 'chance' => 0.85],
            // A #1 Contender's Match on its weekly shows in the weeks before
            // its own brand-only PPVs, held for the PPV.
            'number_one_contender_ppv' => [
                'weeks_before' => 3,
                // Title tier => weight.
                'title_weights' => ['world' => 45, 'second' => 25, 'tag' => 15, 'special' => 15],
                // ASSUMPTION: how often each tier gets its preferred shape
                // (multi-man for second and special, 1v1 / 2v2 for world and tag).
                'shape_preference' => 0.7,
            ],
        ],
        // House rule (2026-11-13): each card it rolls to fix a complaint of
        // each of its superstars, by morale level.
        'morale' => [
            'fix_chance' => ['content' => 0.75, 'unsatisfied' => 0.75, 'upset' => 0.95],
            // House rule (2026-11-17): up to this many superstars waiting on a
            // title match share one against a free champion (3: a Fatal 4-Way).
            'title_group_max' => 3,
            // A losing streak is fixed beside a partner on a win streak this
            // long: a tag match, else managing them in a w/ Manager match.
            'hot_partner_streak' => 3,
        ],
        // ASSUMPTION
        'mitb' => [
            'cash_in_champion_fatigue' => 60,
            'cash_in_weeks_left' => 4,
        ],
        // ASSUMPTION: the draft stops chasing its focus class after this many picks.
        'draft' => [
            'max_focus_picks' => 12,
            // Once it has a member of a tag team or stable, each pick has
            // this % chance (1-100) to go for a teammate who's still free.
            'teammate_chance' => 25,
        ],
    ],
];
