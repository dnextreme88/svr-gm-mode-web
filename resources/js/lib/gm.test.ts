import { describe, expect, it } from 'vitest';
import { Brand, BrandLabels, ContractLength, Morale } from '@/types/enums';
import { sortRoster } from '@/lib/gm';

describe('generated enum types', () => {
    it('mirrors the PHP backed enum values', () => {
        expect(Object.values(Brand)).toEqual(['raw', 'smackdown']);
        expect(Object.values(Morale)).toEqual([
            'happy',
            'content',
            'unsatisfied',
            'upset',
        ]);
        expect(Object.values(ContractLength)).toEqual([5, 12, 24, 36, 52]);
    });

    it('exposes labels keyed by value', () => {
        expect(BrandLabels[Brand.Smackdown]).toBe('Smackdown');
    });
});

describe('sortRoster', () => {
    const row = (
        name: string,
        fatigue: number,
        morale: 'happy' | 'content' | 'unsatisfied' | 'upset',
        wins: number,
        losses: number,
        weeks: number | null,
    ) => ({
        name,
        fatigue,
        morale,
        wins,
        losses,
        contract: weeks === null ? null : { weeks_remaining: weeks },
    });
    const rows = [
        row('Edge', 40, 'content', 3, 2, 12),
        row('Batista', 10, 'upset', 3, 1, null),
        row('Kane', 70, 'happy', 1, 0, 2),
    ];
    const order = (sort: Parameters<typeof sortRoster>[1]) =>
        sortRoster(rows, sort).map((r) => r.name);

    it('keeps the given order without a sort', () => {
        expect(order(null)).toEqual(['Edge', 'Batista', 'Kane']);
    });

    it('sorts by name, fatigue and morale both ways', () => {
        expect(order({ key: 'name', dir: 'asc' })).toEqual([
            'Batista',
            'Edge',
            'Kane',
        ]);
        expect(order({ key: 'fatigue', dir: 'desc' })).toEqual([
            'Kane',
            'Edge',
            'Batista',
        ]);
        expect(order({ key: 'morale', dir: 'asc' })).toEqual([
            'Kane',
            'Edge',
            'Batista',
        ]);
    });

    it('sorts the record by wins, then fewest losses', () => {
        expect(order({ key: 'record', dir: 'asc' })).toEqual([
            'Batista',
            'Edge',
            'Kane',
        ]);
    });

    it('puts wrestlers without a contract last either way', () => {
        expect(order({ key: 'contract', dir: 'asc' })).toEqual([
            'Kane',
            'Edge',
            'Batista',
        ]);
        expect(order({ key: 'contract', dir: 'desc' })).toEqual([
            'Edge',
            'Kane',
            'Batista',
        ]);
    });
});
