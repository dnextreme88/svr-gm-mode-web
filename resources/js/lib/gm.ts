import type { Brand, InjuryZone } from '@/types/enums';
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

/** Compact money for tight chyrons, e.g. $14.2M or $150K. */
export function formatCompactMoney(amount: number): string {
    const sign = amount < 0 ? '−' : '';
    const abs = Math.abs(amount);

    if (abs >= 1_000_000) {
        return `${sign}$${(abs / 1_000_000).toFixed(abs >= 10_000_000 ? 1 : 2)}M`;
    }

    if (abs >= 1_000) {
        return `${sign}$${Math.round(abs / 1_000)}K`;
    }

    return `${sign}$${abs}`;
}

export function brandName(brand: Brand): string {
    return brand === 'raw' ? 'Raw' : 'SmackDown!';
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
