import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    ROLL_DURATION_MS,
    RollingNumber,
    rollFrame,
} from '@/components/gm/rolling-number';

describe('rollFrame', () => {
    it('keeps signs, $ and commas while the digits roll', () => {
        const frame = rollFrame('-$13,997,000', 0);

        expect(frame).toMatch(/^-\$\d{2},\d{3},\d{3}$/);
        expect(frame).not.toBe('-$13,997,000');
    });

    it('locks the digits from left to right, then lands on the value', () => {
        const midway = rollFrame('$1,003,000', ROLL_DURATION_MS * 0.7);

        expect(midway.slice(0, 4)).toBe('$1,0');
        expect(rollFrame('$1,003,000', ROLL_DURATION_MS)).toBe('$1,003,000');
    });
});

describe('RollingNumber', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('rolls, lands on the value and gives screen readers the value at once', () => {
        render(<RollingNumber value="$172,000" />);

        expect(screen.getByText('$172,000')).toHaveClass('sr-only');
        expect(screen.getByTestId('rolling-number')).not.toHaveTextContent(
            '$172,000',
        );

        act(() => {
            vi.advanceTimersByTime(ROLL_DURATION_MS + 100);
        });

        expect(screen.getByTestId('rolling-number')).toHaveTextContent(
            '$172,000',
        );
    });
});
