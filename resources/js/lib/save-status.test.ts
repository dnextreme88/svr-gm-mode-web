import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    SAVED_FLASH_MS,
    saveVisit,
    slotSaveKey,
    useSaveStatus,
} from '@/lib/save-status';

describe('save status', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('keys card slots by kind, show and position', () => {
        expect(slotSaveKey('match', 4, 2)).toBe('match:4:2');
        expect(slotSaveKey('promo', 4, 2)).toBe('promo:4:2');
    });

    it('goes saving, then saved, then clears after the flash', () => {
        const { result } = renderHook(() => useSaveStatus('match:9:1'));
        const visit = saveVisit('match:9:1');
        expect(result.current).toBeNull();

        act(() => visit.onStart());
        expect(result.current).toBe('saving');

        act(() => visit.onSuccess());
        expect(result.current).toBe('saved');

        act(() => {
            vi.advanceTimersByTime(SAVED_FLASH_MS);
        });
        expect(result.current).toBeNull();
    });

    it('clears on error', () => {
        const { result } = renderHook(() => useSaveStatus('promo:9:1'));
        const visit = saveVisit('promo:9:1');

        act(() => visit.onStart());
        act(() => visit.onError());

        expect(result.current).toBeNull();
    });

    it('tracks each key on its own', () => {
        const { result } = renderHook(() => useSaveStatus('match:9:2'));

        act(() => saveVisit('match:9:3').onStart());

        expect(result.current).toBeNull();
    });
});
