import { beforeEach, describe, expect, it } from 'vitest';
import {
    LOADING_OVERLAY_DELAY_MS,
    LOADING_OVERLAY_REPEAT_MS,
    loadingOverlayDelay,
} from '@/components/gm/loading-overlay';

describe('loadingOverlayDelay (UI audit, 2026-11-12)', () => {
    beforeEach(() => window.localStorage.clear());

    it('pauses in full the first time, and briefly after that', () => {
        expect(loadingOverlayDelay('season-start')).toBe(
            LOADING_OVERLAY_DELAY_MS,
        );
        expect(loadingOverlayDelay('season-start')).toBe(
            LOADING_OVERLAY_REPEAT_MS,
        );
        // Each overlay counts on its own.
        expect(loadingOverlayDelay('draft-concluded')).toBe(
            LOADING_OVERLAY_DELAY_MS,
        );
    });
});
