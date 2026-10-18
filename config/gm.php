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
    | §13 Calendar. ASSUMPTION: a season is 52 weeks and starts the week
    | after WrestleMania. Week 1 maps to the reference Sunday below, so
    | month labels come from real 2005–06 dates the game mirrored.
    */
    'calendar' => [
        'weeks_per_season' => 52,
        'season_start_reference' => '2005-04-10',
        'ppv_weeks' => [
            'backlash' => 4,
            'judgment_day' => 7,
            'vengeance' => 12,
            'great_american_bash' => 16,
            'summerslam' => 20,
            'unforgiven' => 24,
            'no_mercy' => 27,
            'taboo_tuesday' => 30,
            'survivor_series' => 34,
            'armageddon' => 37,
            'new_years_revolution' => 40,
            'royal_rumble' => 43,
            'no_way_out' => 46,
            'wrestlemania' => 52,
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
        'max_competitors' => 6,
        'wrestlemania_brand_slots' => 3,
        // ASSUMPTION: how often Randomize/Preset try to make a slot a title match (§24).
        'randomize_title_chance' => 0.35,
    ],

    /*
    | §2 Contracts. ASSUMPTION: the spec gives no prices, only that shorter
    | deals cost less up front and more per week. Flat rates per length,
    | with a Legend multiplier. Draft picks sign the default length.
    */
    'contracts' => [
        'pricing' => [
            5 => ['upfront' => 10_000, 'weekly' => 20_000],
            12 => ['upfront' => 25_000, 'weekly' => 15_000],
            24 => ['upfront' => 50_000, 'weekly' => 12_000],
            36 => ['upfront' => 75_000, 'weekly' => 10_000],
            52 => ['upfront' => 100_000, 'weekly' => 8_000],
        ],
        'legend_multiplier' => 1.5,
        'draft_default_length' => 52,
        'expiring_threshold_weeks' => 4,
    ],

    /*
    | §7 Financials. ASSUMPTION: show income depends on the deferred rating
    | system, so it's a flat stub. Deficit enforcement kicks in after this
    | many consecutive weeks below $0.
    */
    'finances' => [
        'base_show_income' => 0,
        'deficit_weeks_limit' => 4,
    ],

    /*
    | §6 Fatigue & injury. Fatigue is stored 0–100 in steps of 10.
    */
    'fatigue' => [
        'bar_size' => 10,
        'max' => 100,
        'safe_max' => 70,
        'serious_at' => 100,
        // ASSUMPTION: rest recovery is TBD in the spec.
        'rest_recovery_bars' => 2,
        // §4 to confirm: managing adds no fatigue.
        'manager_fatigue_bars' => 0,
    ],

    'injuries' => [
        // ASSUMPTION: minor-zone odds are TBD in the spec.
        'minor_chance' => 0.15,
        // ASSUMPTION: escalation odds are TBD in the spec.
        'escalation_chance' => 0.20,
        'minor_max_weeks' => 4,
        'serious_min_weeks' => 5,
        'serious_max_weeks' => 52,
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
    ],

    /*
    | Match type defaults by family (seeded into match_types).
    | ASSUMPTION: the spec gives no fatigue costs, prices or DQ flags.
    | Prices stay inside the §4 $1,000–$50,000 range for gimmick matches.
    */
    'match_type_defaults' => [
        'single' => ['bars' => 1, 'cost' => 0, 'dq' => true, 'countout' => true],
        'tag_team' => ['bars' => 1, 'cost' => 0, 'dq' => true, 'countout' => true],
        'six_man_tag' => ['bars' => 1, 'cost' => 0, 'dq' => true, 'countout' => true],
        'elimination_tag' => ['bars' => 2, 'cost' => 0, 'dq' => true, 'countout' => true],
        'triple_threat' => ['bars' => 1, 'cost' => 0, 'dq' => true, 'countout' => true],
        'fatal_four_way' => ['bars' => 1, 'cost' => 0, 'dq' => true, 'countout' => true],
        'handicap' => ['bars' => 1, 'cost' => 0, 'dq' => true, 'countout' => true],
        'royal_rumble' => ['bars' => 2, 'cost' => 25_000, 'dq' => false, 'countout' => false],
        'three_stages_of_hell' => ['bars' => 3, 'cost' => 25_000, 'dq' => true, 'countout' => true],
        'backstage_brawl' => ['bars' => 2, 'cost' => 5_000, 'dq' => false, 'countout' => false],
        'battle_royal' => ['bars' => 1, 'cost' => 0, 'dq' => false, 'countout' => false],
        'buried_alive' => ['bars' => 3, 'cost' => 25_000, 'dq' => false, 'countout' => false],
        'elimination_chamber' => ['bars' => 3, 'cost' => 50_000, 'dq' => false, 'countout' => false],
        'first_blood' => ['bars' => 2, 'cost' => 5_000, 'dq' => false, 'countout' => false],
        'fulfill_your_fantasy' => ['bars' => 1, 'cost' => 2_000, 'dq' => true, 'countout' => true],
        'hardcore' => ['bars' => 2, 'cost' => 5_000, 'dq' => false, 'countout' => false],
        'hell_in_a_cell' => ['bars' => 3, 'cost' => 25_000, 'dq' => false, 'countout' => false],
        'iron_man' => ['bars' => 3, 'cost' => 10_000, 'dq' => true, 'countout' => false],
        'ladder' => ['bars' => 3, 'cost' => 15_000, 'dq' => false, 'countout' => false],
        'last_man_standing' => ['bars' => 3, 'cost' => 10_000, 'dq' => false, 'countout' => false],
        'slobber_knocker' => ['bars' => 2, 'cost' => 5_000, 'dq' => false, 'countout' => false],
        'special_referee' => ['bars' => 2, 'cost' => 5_000, 'dq' => true, 'countout' => true],
        'steel_cage' => ['bars' => 2, 'cost' => 10_000, 'dq' => false, 'countout' => false],
        'submission' => ['bars' => 2, 'cost' => 1_000, 'dq' => true, 'countout' => false],
        'table' => ['bars' => 2, 'cost' => 5_000, 'dq' => false, 'countout' => false],
        'tlc' => ['bars' => 3, 'cost' => 20_000, 'dq' => false, 'countout' => false],
        'survivor_series_elimination' => ['bars' => 2, 'cost' => 0, 'dq' => true, 'countout' => true],
    ],
    'mitb_match' => ['bars' => 3, 'cost' => 50_000],

    /*
    | §15 Morale thresholds per complaint: [content, unsatisfied, upset].
    */
    'morale' => [
        'losing_streak' => [3, 5, 7],
        'contract_extension' => [10, 15, 18],
        // 1 month = 4 weeks.
        'title_match' => [4, 8, 12],
        'inactivity' => [2, 4, 8],
        // ASSUMPTION: current champions don't raise title-match demands.
        'champions_exempt_from_title_demand' => true,
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
        'ai_focus_from' => 60,
    ],

    /*
    | §8 Trades.
    */
    'trades' => [
        'max_per_side' => 5,
        // ASSUMPTION: no AI trade logic in the spec.
        'ai_accept_chance' => 0.5,
    ],

    /*
    | §16 Titles.
    */
    'titles' => [
        // §16 to confirm: only serious injuries vacate. Set to an integer
        // (e.g. 3) to vacate on any injury longer than that many weeks.
        'vacate_on_injury_over_weeks' => null,
        'vacate_on_serious_injury' => true,
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
        'survivor_series_team_size' => 3,
        'redraft_max_picks' => 10,
    ],

    /*
    | §5 Promos. Success rates are starting estimates (house rule).
    */
    'promos' => [
        'success_rates' => [
            'new_superstar' => 0.75,
            'continue_rivalry' => 0.90,
            'start_rivalry' => 0.85,
            'inactive_superstar' => 0.90,
            'injury_return' => 0.80,
            'advertising' => 1.00,
            'merchandise_sponsorship' => 1.00,
            'main_event_hype' => 0.75,
            'ppv_hype' => 0.80,
            'interference_promotion' => 0.50,
            'slander_promotion' => 0.50,
        ],
        'amounts' => [
            'new_superstar' => -50_000,
            'continue_rivalry' => -2_000,
            'start_rivalry' => 0,
            'inactive_superstar' => -2_000,
            'injury_return' => -50_000,
            'advertising' => 150_000,
            'merchandise_sponsorship' => 0,
            'main_event_hype' => -50_000,
            'ppv_hype' => -10_000,
            'interference_promotion' => -50_000,
            'slander_promotion' => -50_000,
        ],
        // ASSUMPTION: "freshly signed/traded-in" window for New Superstar.
        'new_superstar_window_weeks' => 2,
        'slander_overuse_window_weeks' => 4,
        // ASSUMPTION: the spec says Merchandise starts low and scales with use.
        'merchandise' => [
            'base' => 10_000,
            'step' => 10_000,
            'cap' => 100_000,
            'reset_after_idle_weeks' => 2,
        ],
    ],

    /*
    | Rivalries (§3, §19). ASSUMPTION: the spec doesn't define heat.
    */
    'rivalries' => [
        'auto_create_meetings' => 2,
        'auto_create_window_weeks' => 4,
        'start_heat' => 10,
        'heat_per_meeting' => 10,
        'heat_per_promo' => 10,
        'idle_decay' => 5,
        'heating_up_threshold' => 50,
        'blowoff_min_heat' => 50,
        'max_heat' => 100,
    ],

    /*
    | §19 News thresholds.
    */
    'news' => [
        'win_streaks' => [3, 5, 10],
        'win_streak_headline_at' => 10,
        'streak_snapped_min' => 5,
        'losing_streak_min' => 4,
        'reign_milestones' => [10, 25, 52],
        'ppv_upcoming_weeks' => [2, 1],
        'stories_per_brand' => 2,
        // Deferred: overall ratings aren't designed yet, so upset_win is off.
        'upset_win_overall_margin' => null,
    ],

    /*
    | §3 Email senders.
    */
    'email_senders' => [
        'raw' => 'JR',
        'smackdown' => 'Tazz',
    ],
];
