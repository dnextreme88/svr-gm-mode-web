import type {
    Brand,
    InjurySeverity,
    InjuryZone,
    MatchFamily,
    Morale,
    RosterType,
    SaveStage,
    WeightClass,
} from './enums';

export type FatigueConfig = {
    bar_size: number;
    safe_max: number;
    serious_at: number;
    max: number;
};

/** The broadcast header shared on every page under a save. */
export type GameShell = {
    id: number;
    name: string;
    saved: boolean;
    player_brand: Brand;
    rival_brand: Brand;
    stage: SaveStage;
    season: number;
    week: number;
    season_week: number;
    /** The week is airing live: its matches are being decided on the results screen. */
    airing: boolean;
    /** This week's card to book (or its live results while it airs); the header's week chip links here. */
    current_card_url: string | null;
    month: string;
    date: string;
    budget: number;
    rival_budget: number;
    roster_count: number;
    rival_roster_count: number;
    roster_cap: number;
    unread_emails: number;
    next_ppv: { name: string; week: number; weeks_away: number } | null;
    fatigue_config: FatigueConfig;
    deficit: {
        weeks: number;
        limit: number;
        choice: string | null;
        blocked: boolean;
    };
    /** The player's next team waiting to be named as a faction (house rule). */
    faction_prompt: FactionPrompt | null;
    /** House rule: a vacated title with a #1 contender waiting. */
    title_vacancy_prompt?: TitleVacancyPrompt | null;
    /** One-off notices for the GM, shown until seen. */
    notices?: GmNotice[];
};

/** 2 or 3 of the player's superstars who won 3 times together (house rule). */
export type FactionPrompt = {
    id: number;
    wins: number;
    show: string | null;
    default_type: import('./enums').FactionType;
    name_max_length: number;
    members: Array<{
        id: number;
        name: string;
        roster_type: RosterType;
        weight_class: WeightClass;
    }>;
};

/** A faction teaming in a match: its name and its members on that side. */
export type MatchFaction = {
    name: string;
    members: string[];
};

export type InjuryInfo = {
    label: string;
    severity: InjurySeverity;
    weeks_left: number;
};

export type ContractInfo = {
    length_weeks: number;
    weeks_remaining: number;
    weekly_cost: number;
    starts_week: number;
};

export type WrestlerRow = {
    id: number;
    name: string;
    slug: string;
    roster_type: RosterType;
    weight_class: WeightClass;
    brand: Brand | null;
    morale: Morale;
    fatigue: number;
    fatigue_zone: InjuryZone;
    wins: number;
    losses: number;
    win_streak: number;
    loss_streak: number;
    popularity: number | null;
    specialties: MatchFamily[];
    injury: InjuryInfo | null;
    sitting_out: boolean;
    unbookable_until_week: number | null;
    contract: ContractInfo | null;
    titles: string[];
};

export type ContractQuote = {
    length_weeks: number;
    label: string;
    upfront: number;
    weekly: number;
    total: number;
};

export type GamePageProps = {
    game: GameShell;
};

export type CardShow = {
    id: number;
    name: string;
    show_type: import('./enums').ShowType;
    brand: Brand | null;
    status: import('./enums').ShowStatus;
    week_number: number;
    ppv_event_id: number | null;
    match_slot_count: number;
    promo_slot_count: number;
};

export type CardSlot = {
    id: number;
    position: number;
    match_type_id: number | null;
    sides: Array<Array<number | null>>;
    managers: Record<string, number>;
    referee_id?: number | null;
    title_championship_id: number | null;
    stipulation: import('./enums').Stipulation | null;
    stipulation_championship_id: number | null;
    /** House rule: a Winner Takes All match's contested titles. */
    contested_title_ids?: number[];
    cost: number;
    fixed_kind: import('./enums').FixedSlotKind | null;
    owner_brand: Brand | null;
    locked?: boolean;
    names?: Record<string, string>;
};

export type MatchTypeOption = {
    id: number;
    name: string;
    family: MatchFamily;
    variant: string;
    sides: number[];
    manager_sides: number[];
    fatigue_cost_bars: number;
    cost: number;
    divas_only: boolean;
    divas_allowed: boolean;
    titles_allowed: boolean;
    win_condition: string | null;
    ppv_only: boolean;
    /** The first wrestler on each side is that side's captain. */
    captain_fall: boolean;
    /** Needs a non-competing special referee from the roster (§10). */
    special_referee?: boolean;
    /** House rule: the stipulations this type takes (a Gauntlet only takes #1 Contender's Match). */
    allowed_stipulations?: import('./enums').Stipulation[];
};

export type RosterEntry = WrestlerRow & {
    bookable: boolean;
    blocked_reason: string | null;
};

export type BookingPayload = {
    match_type_id: number;
    sides: number[][];
    managers: Record<number, number | null>;
    title_championship_id?: number | null;
    stipulation?: string | null;
    stipulation_championship_id?: number | null;
    referee_id?: number | null;
};

export type MatchOutcome = {
    id: number;
    pending?: false;
    position: number;
    match_type: string | null;
    winners: string[];
    /** The winning side in plain text, factions named as such. */
    winners_label: string;
    losers: string[];
    /** Factions teaming in this match (house rule). */
    factions?: MatchFaction[];
    /** Each losing side's names, for the (C) tag. */
    loser_sides: string[][];
    /** The winning side's manager, for "Snitsky w/ Eugene". */
    winner_manager?: string | null;
    /** Each losing side's manager, in the order of `loser_sides`. */
    loser_side_managers?: Array<string | null>;
    /** The player decided the winner with Book to Win. */
    booked: boolean;
    /** Who held the title on the line going into the match. */
    champions: string[];
    /** Each side's captain in a Captain Fall match, for the (Cpt) tag. */
    captains: string[];
    managers: string[];
    referee?: string | null;
    method: import('./enums').MatchMethod;
    method_label: string;
    finish_event: import('./enums').FinishEvent | null;
    finish_label: string | null;
    summary: string;
    title: string | null;
    title_banner: 'new_champion' | 'and_still' | null;
    stipulation: import('./enums').Stipulation | null;
    stipulation_outcome: string | null;
    /** The title a Champion vs. Contender or #1 Contender's Match is about. */
    stipulation_title?: string | null;
    /** Winner Takes All: the titles on the line, and what the winner took. */
    contested_titles?: string[];
    titles_won?: string[];
    /** A Gauntlet's bouts, in order. */
    bouts?: GauntletBout[];
    rating: number | null;
    fan_delta: number | null;
};

/** A match still to be decided on the live results screen. */
export type GauntletBout = {
    round: number;
    winner: string;
    loser: string;
    method_label: string;
    booked: boolean;
    summary: string;
};

export type PendingMatch = {
    id: number;
    pending: true;
    /** Up next: the only match that can be decided now. */
    is_next: boolean;
    position: number;
    match_type: string | null;
    win_condition: string | null;
    sides: Array<{
        side: number;
        members: Array<{
            id: number;
            name: string;
            roster_type: import('./enums').RosterType;
            weight_class: import('./enums').WeightClass;
        }>;
        /** The side's manager in a w/Manager match. */
        manager?: string | null;
    }>;
    managers: string[];
    referee: string | null;
    title: string | null;
    stipulation: import('./enums').Stipulation | null;
    stipulation_title?: string | null;
    contested_titles?: string[];
    /** Who holds the title on the line. */
    champions: string[];
    /** Each side's captain in a Captain Fall match, for the (Cpt) tag. */
    captains: string[];
    /** Factions teaming in this match (house rule). */
    factions?: MatchFaction[];
    /** A Gauntlet: the sides are the entrants in order, decided bout by bout. */
    gauntlet?: boolean;
};

/** A promo on the results screen, placed before the match it aired in front of. */
export type ResultPromo = {
    position: number;
    before_position: number;
    type: string | null;
    status: 'succeeded' | 'failed' | 'fizzled' | 'backfired' | null;
    targets: Array<{
        id: number;
        name: string;
        roster_type: import('./enums').RosterType;
        weight_class: import('./enums').WeightClass;
    }>;
};

export type TitleOption = {
    id: number;
    name: string;
    is_tag: boolean;
    eligibility: import('./enums').ChampionshipEligibility;
    holder_ids: number[];
    holder_name: string | null;
};

export type TitleContext = {
    championships: TitleOption[];
    lockouts: Record<string, number[]>;
    last_chance: Array<{
        championship_id: number;
        reign_id: number;
        champion_ids: number[];
        contender_ids: number[];
    }>;
    title_shots: Array<{
        id: number;
        championship_id: number;
        championship: string;
        contender_ids: number[];
        contenders: string;
        booked: boolean;
    }>;
    /** House rule: #1 contenders waiting for their title match. */
    contenderships?: Array<{
        championship_id: number;
        championship: string;
        contender_ids: number[];
        contenders: string;
    }>;
    /** Titles with an earned Champion vs. Contender shot still to come. */
    earned_shot_title_ids?: number[];
    /** Everyone holding a title, on either brand. */
    title_holder_ids?: number[];
    briefcase: {
        id: number;
        holder: string;
        expires_week: number;
        cashed_in_here: boolean;
    } | null;
};

export type TitleVacancyPrompt = {
    id: number;
    title: string;
    contenders: string;
    contender_count: number;
};

export type GmNotice = {
    id: number;
    kind: import('./enums').GmNoticeKind;
    week: number;
    title: string;
    contenders: string;
    reason: string;
};
