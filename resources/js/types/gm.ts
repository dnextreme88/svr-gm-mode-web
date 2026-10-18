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
    player_brand: Brand;
    rival_brand: Brand;
    stage: SaveStage;
    season: number;
    week: number;
    season_week: number;
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
    is_default_manager: boolean;
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
    title_championship_id: number | null;
    stipulation: import('./enums').Stipulation | null;
    stipulation_championship_id: number | null;
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
};

export type MatchOutcome = {
    position: number;
    match_type: string | null;
    winners: string[];
    losers: string[];
    managers: string[];
    method: import('./enums').MatchMethod;
    method_label: string;
    finish_event: import('./enums').FinishEvent | null;
    finish_label: string | null;
    summary: string;
    title: string | null;
    title_banner: 'new_champion' | 'and_still' | null;
    stipulation: import('./enums').Stipulation | null;
    stipulation_outcome: string | null;
    rating: number | null;
    fan_delta: number | null;
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
    briefcase: {
        id: number;
        holder: string;
        expires_week: number;
        cashed_in_here: boolean;
    } | null;
};
