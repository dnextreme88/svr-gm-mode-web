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
