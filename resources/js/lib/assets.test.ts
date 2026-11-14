import { describe, expect, it } from 'vitest';
import { brandLogo, ppvLogo, titleImage, titleImageFor } from '@/lib/assets';
import { ChampionshipKeyLabels, PpvKey } from '@/types/enums';
import type { ChampionshipKey } from '@/types/enums';

describe('assets', () => {
    it('has a belt for every championship, found by its name', () => {
        for (const [key, name] of Object.entries(ChampionshipKeyLabels)) {
            expect(titleImageFor(name)).toBe(
                titleImage[key as ChampionshipKey],
            );
            expect(titleImageFor(name)).toBeTruthy();
        }
    });

    it('has no belt for an unknown title', () => {
        expect(titleImageFor('Hardcore Championship')).toBeNull();
        expect(titleImageFor('')).toBeNull();
    });

    it('has a logo for both brands', () => {
        expect(brandLogo.raw).toBeTruthy();
        expect(brandLogo.smackdown).toBeTruthy();
        expect(brandLogo.raw).not.toBe(brandLogo.smackdown);
    });

    it('has a logo for every PPV', () => {
        const logos = Object.values(PpvKey).map((key) => ppvLogo[key]);

        logos.forEach((logo) => expect(logo).toBeTruthy());
        expect(new Set(logos).size).toBe(logos.length);
    });
});
