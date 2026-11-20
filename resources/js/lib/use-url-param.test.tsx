import { act, renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { useSortUrl, useUrlParam } from '@/lib/use-url-param';

describe('useUrlParam (UI audit, 2026-11-20)', () => {
    it('reads the query string and writes back without a history entry', () => {
        window.history.replaceState({ page: 'inertia' }, '', '/x?tab=morale');
        const length = window.history.length;
        const { result } = renderHook(() => useUrlParam('tab', 'matches'));

        expect(result.current[0]).toBe('morale');

        act(() => result.current[1]('promos'));
        expect(result.current[0]).toBe('promos');
        expect(window.location.search).toBe('?tab=promos');
        expect(window.history.length).toBe(length);
        // Inertia's history state is kept.
        expect(window.history.state).toEqual({ page: 'inertia' });

        // The fallback is left out of the URL.
        act(() => result.current[1]('matches'));
        expect(window.location.search).toBe('');
    });

    it('keeps a sort as key:dir and ignores unknown columns', () => {
        window.history.replaceState(null, '', '/x?sort=bogus:asc');
        const { result } = renderHook(() =>
            useSortUrl(['name', 'fatigue'] as const),
        );

        expect(result.current[0]).toBeNull();

        act(() => result.current[1]({ key: 'fatigue', dir: 'desc' }));
        expect(window.location.search).toBe('?sort=fatigue%3Adesc');
        expect(result.current[0]).toEqual({ key: 'fatigue', dir: 'desc' });
    });
});
