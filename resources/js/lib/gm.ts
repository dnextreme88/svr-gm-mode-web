import type {
    Brand,
    InjuryZone,
    MatchFamily,
    Morale,
    RosterType,
    WeightClass,
} from '@/types/enums';
import type { FatigueConfig } from '@/types/gm';

const money = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 0,
});

export function formatMoney(amount: number): string {
    return money.format(amount);
}

export function formatSignedMoney(amount: number): string {
    return `${amount > 0 ? '+' : amount < 0 ? '−' : ''}${money.format(Math.abs(amount))}`;
}

export function brandName(brand: Brand): string {
    return brand === 'raw' ? 'Raw' : 'Smackdown';
}

export function otherBrand(brand: Brand): Brand {
    return brand === 'raw' ? 'smackdown' : 'raw';
}

/**
 * Display-only mirror of the server's fatigue zones (§6), used to preview
 * where a booking would leave a wrestler. The server stays authoritative.
 */
export function fatigueZone(
    fatigue: number,
    config: FatigueConfig,
): InjuryZone {
    if (fatigue >= config.serious_at) {
        return 'serious_risk';
    }

    if (fatigue > config.safe_max) {
        return 'minor_risk';
    }

    return 'safe';
}

export function capFatigue(fatigue: number, config: FatigueConfig): number {
    return Math.max(0, Math.min(config.max, fatigue));
}

export const zoneLabel: Record<InjuryZone, string> = {
    safe: 'Safe',
    minor_risk: 'Minor risk',
    serious_risk: 'Serious risk',
};

export function pluralize(count: number, word: string): string {
    return `${count} ${word}${count === 1 ? '' : 's'}`;
}

export function prefersReducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true
    );
}

/**
 * Per-browser UI preferences (e.g. Skip animation). Storage can be missing
 * or blocked, so every access is guarded and falls back to the default.
 */
export function readPreference<T extends string>(
    key: string,
    fallback: T,
    allowed: readonly T[],
): T {
    try {
        const value = window.localStorage.getItem(key);

        return value !== null && (allowed as readonly string[]).includes(value)
            ? (value as T)
            : fallback;
    } catch {
        return fallback;
    }
}

export function writePreference(key: string, value: string): void {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Storage unavailable: the preference just won't persist.
    }
}

/** Cruiserweights and Light Heavyweights (the blue names). */
export function isCruiser(weightClass: WeightClass | null): boolean {
    return (
        weightClass === 'cruiserweight' || weightClass === 'light_heavyweight'
    );
}

export type PoolFilter = {
    type: RosterType | 'all';
    cruisers: boolean;
    specialists: boolean;
    search: string;
};

export const emptyPoolFilter: PoolFilter = {
    type: 'all',
    cruisers: false,
    specialists: false,
    search: '',
};

type Filterable = {
    name: string;
    roster_type: RosterType;
    weight_class: WeightClass;
    specialties: MatchFamily[];
};

export function filterPool<T extends Filterable>(
    rows: T[],
    filter: PoolFilter,
): T[] {
    const search = filter.search.trim().toLowerCase();

    return rows.filter(
        (w) =>
            (filter.type === 'all' || w.roster_type === filter.type) &&
            (!filter.cruisers || isCruiser(w.weight_class)) &&
            (!filter.specialists || w.specialties.length > 0) &&
            w.name.toLowerCase().includes(search),
    );
}

export type RosterSortKey =
    | 'name'
    | 'fatigue'
    | 'morale'
    | 'record'
    | 'contract';
export type RosterSort = { key: RosterSortKey; dir: 'asc' | 'desc' } | null;

type Sortable = {
    name: string;
    fatigue: number;
    morale: Morale;
    wins: number;
    losses: number;
    contract: { weeks_remaining: number } | null;
};

const moraleRank: Record<Morale, number> = {
    happy: 0,
    content: 1,
    unsatisfied: 2,
    upset: 3,
};

/**
 * Roster table sort. Ascending is A–Z, freshest first, happiest first, most
 * wins first (then fewest losses) and fewest contract weeks left first;
 * wrestlers without a contract always go last. Ties keep the given order.
 */
export function sortRoster<T extends Sortable>(
    rows: T[],
    sort: RosterSort,
): T[] {
    if (sort === null) {
        return rows;
    }

    const flip = sort.dir === 'asc' ? 1 : -1;
    const compare: Record<RosterSortKey, (a: T, b: T) => number> = {
        name: (a, b) => a.name.localeCompare(b.name),
        fatigue: (a, b) => a.fatigue - b.fatigue,
        morale: (a, b) => moraleRank[a.morale] - moraleRank[b.morale],
        record: (a, b) => b.wins - a.wins || a.losses - b.losses,
        contract: (a, b) =>
            (a.contract?.weeks_remaining ?? 0) -
            (b.contract?.weeks_remaining ?? 0),
    };

    return [...rows].sort((a, b) => {
        if (sort.key === 'contract' && (!a.contract || !b.contract)) {
            return Number(!a.contract) - Number(!b.contract);
        }

        return compare[sort.key](a, b) * flip;
    });
}
