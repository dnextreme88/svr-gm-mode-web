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
        // House rule (2026-10-28): each brand's shows earn a flat amount once
        // the week is done, until Popularity and ratings can drive income.
        'weekly_show_income' => 250_000,
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
        // House rule (2026-10-20): every wrestler recovers this many bars at
        // the end of each week, booked or not, like SvR 2006.
        'rest_recovery_bars' => 2,
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
        'iron_man' => ['bars' => 5, 'cost' => 37_000, 'dq' => true, 'countout' => false],
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
        'no_ring_out_families' => ['steel_cage', 'battle_royal', 'royal_rumble', 'elimination_chamber'],
        'no_titles_families' => ['handicap', 'gauntlet'],
        'no_titles_sides' => [[3, 3]],
        'no_stipulation_families' => ['gauntlet'],
        // Six-competitor variants of these families cost more fatigue.
        'six_competitor_bars' => ['hardcore' => 4, 'ladder' => 4, 'tlc' => 4, 'table' => 4],
    ],

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
        // House rule (2026-10-25): every promo succeeds except these.
        // Merchandise Sponsorship's odds come with its rolled tier below.
        'success_rates' => [
            'new_superstar' => 1.00,
            'continue_rivalry' => 1.00,
            'start_rivalry' => 1.00,
            'inactive_superstar' => 1.00,
            'advertising' => 0.80,
            'merchandise_sponsorship' => 0.80,
            'main_event_hype' => 1.00,
            'ppv_hype' => 1.00,
            'interference_promotion' => 0.50,
            'slander_promotion' => 0.50,
            'title_match_hype' => 1.00,
            'comeback_reminder' => 1.00,
            'interview_space' => 0.90,
            'diva_bikini_contest' => 1.00,
        ],
        'amounts' => [
            'new_superstar' => -50_000,
            'continue_rivalry' => -2_000,
            'start_rivalry' => 0,
            'inactive_superstar' => -2_000,
            'advertising' => 150_000,
            'merchandise_sponsorship' => 0,
            'main_event_hype' => -50_000,
            'ppv_hype' => -10_000,
            'interference_promotion' => -50_000,
            'slander_promotion' => -50_000,
            'title_match_hype' => -10_000,
            'comeback_reminder' => -2_000,
            'interview_space' => -2_000,
            'diva_bikini_contest' => -10_000,
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
        // Extra end-of-week recovery a successful promo gives its target.
        'recovery_bars' => [
            'inactive_superstar' => 2,
        ],
        // Comeback Reminder: the wrestler has been out more than this many weeks.
        'comeback_reminder_min_weeks_out' => 3,
        // ASSUMPTION: "freshly signed/traded-in" window for New Superstar.
        'new_superstar_window_weeks' => 2,
        'slander_overuse_window_weeks' => 4,
        // House rule (2026-10-25): Merchandise Sponsorship rolls one of these
        // tiers (equal odds) when it airs; the bigger the deal, the less
        // likely it lands. A success pays the tier's amount.
        'merchandise_tiers' => [
            ['amount' => 100_000, 'chance' => 0.80],
            ['amount' => 250_000, 'chance' => 0.70],
            ['amount' => 500_000, 'chance' => 0.50],
            ['amount' => 1_000_000, 'chance' => 0.25],
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
        'win_streaks' => [3, 5, 10],
        'win_streak_headline_at' => 10,
        'streak_snapped_min' => 5,
        'losing_streak_min' => 4,
        'reign_milestones' => [10, 25, 52],
        'ppv_upcoming_weeks' => [2, 1],
        // House rule (2026-10-25): a PPV's main event takes the WWE headline
        // that week. The interbrand card's (Survivor Series, WrestleMania)
        // outranks a brand PPV's; the Royal Rumble result sits at 90.
        'ppv_main_event_priority' => ['interbrand' => 95, 'brand' => 85],
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
        'interference_reminder_weeks' => 13,
        // ASSUMPTION: Vince's shared-PPV reminder lands 2 weeks out.
        'ppv_reminder_weeks_before' => 2,
        'low_cash_threshold' => 500_000,
        'low_cash_every_weeks' => 4,
        'contract_reminder_weeks' => 2,
        'injury_return_notice_weeks' => 2,
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
            // ASSUMPTION
            'champion_vs_contender_chance' => 0.1,
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
                'main_event_hype' => 15,
                'interview_space' => 10,
                'diva_bikini_contest' => 10,
            ],
        ],
        'rivalries' => [
            'max_per_brand' => 7,
            'refill_wait_weeks' => 2,
            'reset_minimum' => 4,
            // ASSUMPTION: each active rivalry's chance of a 1v1 on a card.
            'rivalry_match_chance' => 0.5,
        ],
        'roster' => [
            // ASSUMPTION: the four numbers below.
            'renew_at_weeks_left' => 4,
            'renew_length' => 52,
            'target_size' => 28,
            'cash_reserve' => 500_000,
            // ASSUMPTION: money promos come first while the budget can't cover
            // the renewals due within this many weeks (on top of renew_at_weeks_left).
            'save_ahead_weeks' => 30,
        ],
        // ASSUMPTION: there's no Popularity yet, so this is the whole trade value model.
        'trades' => [
            'legend_value' => 2,
            'champion_history_value' => 1,
            'focus_value' => 1,
            'fair_ratio' => 1.0,
            'fair_accept' => 0.9,
            'unfair_accept' => 0.05,
        ],
        // ASSUMPTION
        'mitb' => [
            'cash_in_champion_fatigue' => 60,
            'cash_in_weeks_left' => 4,
        ],
        // ASSUMPTION: the draft stops chasing its focus class after this many picks.
        'draft' => [
            'max_focus_picks' => 12,
        ],
    ],
];
