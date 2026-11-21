import { describe, expect, it } from 'vitest';
import { pickCounts, sortPool } from '@/pages/gm/draft';

const rows = [
    { name: 'Rey Mysterio', weight_class: 'cruiserweight' as const },
    { name: 'Big Show', weight_class: 'super_heavyweight' as const },
    { name: 'Edge', weight_class: 'heavyweight' as const },
    { name: 'Batista', weight_class: 'heavyweight' as const },
];

describe('draft pool sorting (2026-11-21)', () => {
    it('sorts by name both ways', () => {
        expect(
            sortPool(rows, { key: 'name', dir: 'asc' }).map((r) => r.name),
        ).toEqual(['Batista', 'Big Show', 'Edge', 'Rey Mysterio']);
        expect(
            sortPool(rows, { key: 'name', dir: 'desc' }).map((r) => r.name),
        ).toEqual(['Rey Mysterio', 'Edge', 'Big Show', 'Batista']);
    });

    it('sorts by weight class, lightest first, then by name', () => {
        expect(
            sortPool(rows, { key: 'class', dir: 'asc' }).map((r) => r.name),
        ).toEqual(['Rey Mysterio', 'Batista', 'Edge', 'Big Show']);
    });

    it('keeps the server order with no sort', () => {
        expect(sortPool(rows, null)).toBe(rows);
    });
});

describe('brand card counts', () => {
    it('counts superstars, Divas and specialists', () => {
        const pick = (
            roster_type: 'superstar' | 'diva',
            specialist: boolean,
        ) => ({
            pick_number: 1,
            brand: 'raw' as const,
            save_wrestler_id: 1,
            name: 'X',
            roster_type,
            weight_class: null,
            specialist,
            pick_type: 'manual' as const,
        });

        expect(
            pickCounts([
                pick('superstar', true),
                pick('superstar', false),
                pick('diva', false),
            ]),
        ).toEqual({ superstars: 2, divas: 1, specialists: 1 });
    });
});
