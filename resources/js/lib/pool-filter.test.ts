import { describe, expect, it } from 'vitest';
import { emptyPoolFilter, filterPool } from '@/lib/gm';
import type { MatchFamily, RosterType, WeightClass } from '@/types/enums';

const row = (
    name: string,
    roster_type: RosterType,
    weight_class: WeightClass,
    specialties: MatchFamily[] = [],
) => ({ name, roster_type, weight_class, specialties });

const pool = [
    row('Rey Mysterio', 'superstar', 'cruiserweight'),
    row('Chris Benoit', 'superstar', 'light_heavyweight', ['submission']),
    row('Edge', 'superstar', 'heavyweight', ['tlc', 'ladder']),
    row('Trish Stratus', 'diva', 'featherweight'),
];

const names = (rows: typeof pool) => rows.map((r) => r.name);

describe('filterPool', () => {
    it('keeps everyone with the empty filter', () => {
        expect(filterPool(pool, emptyPoolFilter)).toHaveLength(4);
    });

    it('filters to wrestlers with match specialties', () => {
        expect(
            names(filterPool(pool, { ...emptyPoolFilter, specialists: true })),
        ).toEqual(['Chris Benoit', 'Edge']);
    });

    it('combines type, toggles and a case-insensitive search', () => {
        expect(
            names(
                filterPool(pool, {
                    type: 'superstar',
                    specialists: true,
                    search: ' BEN',
                }),
            ),
        ).toEqual(['Chris Benoit']);
        expect(
            names(filterPool(pool, { ...emptyPoolFilter, type: 'diva' })),
        ).toEqual(['Trish Stratus']);
    });
});
