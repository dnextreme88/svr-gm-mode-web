import { describe, expect, it } from 'vitest';
import { buildMentionMatcher, splitMentions } from '@/lib/mentions';

const matcher = buildMentionMatcher([
    { name: 'Edge', brand: 'raw' },
    { name: 'Matt Hardy', brand: 'smackdown' },
    { name: 'Hardy', brand: 'raw' },
    { name: 'Mick Foley', brand: null },
    { name: 'D-Von Dudley', brand: 'smackdown' },
]);

describe('splitMentions', () => {
    it('wraps each roster name with its brand', () => {
        expect(splitMentions('Edge challenged Matt Hardy.', matcher)).toEqual([
            { name: 'Edge', brand: 'raw' },
            ' challenged ',
            { name: 'Matt Hardy', brand: 'smackdown' },
            '.',
        ]);
    });

    it('prefers the longest name and ignores names inside other words', () => {
        expect(splitMentions('Edged out by Matt Hardy', matcher)).toEqual([
            'Edged out by ',
            { name: 'Matt Hardy', brand: 'smackdown' },
        ]);
    });

    it('keeps free agents unbranded and escapes regex characters', () => {
        expect(splitMentions('Mick Foley and D-Von Dudley', matcher)).toEqual([
            { name: 'Mick Foley', brand: null },
            ' and ',
            { name: 'D-Von Dudley', brand: 'smackdown' },
        ]);
    });

    it('returns the text untouched when there are no names', () => {
        expect(splitMentions('Budget update', buildMentionMatcher([]))).toEqual(
            ['Budget update'],
        );
    });
});
