import { describe, expect, it } from 'vitest';
import { Brand, BrandLabels, ContractLength, Morale } from '@/types/enums';

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
        expect(BrandLabels[Brand.Smackdown]).toBe('SmackDown!');
    });
});
