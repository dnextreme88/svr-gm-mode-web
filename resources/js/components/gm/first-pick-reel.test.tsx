import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    FirstPickReel,
    REEL_FLASH_MS,
    REEL_SPIN_MS,
} from '@/components/gm/first-pick-reel';

describe('FirstPickReel', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('spins for the full time, lands on the result, then finishes', () => {
        const onDone = vi.fn();
        render(<FirstPickReel result="smackdown" onDone={onDone} />);

        act(() => {
            vi.advanceTimersByTime(REEL_SPIN_MS - 500);
        });
        expect(screen.getByText('Spinning…')).toBeInTheDocument();

        act(() => {
            vi.advanceTimersByTime(1000);
        });
        expect(screen.getByText('SmackDown! picks first!')).toBeInTheDocument();
        expect(screen.getByTestId('reel-face')).toHaveAttribute(
            'data-face',
            'smackdown',
        );
        expect(onDone).not.toHaveBeenCalled();

        act(() => {
            vi.advanceTimersByTime(REEL_FLASH_MS);
        });
        expect(onDone).toHaveBeenCalledOnce();
    });

    it('keeps spinning until the server result arrives', () => {
        const onDone = vi.fn();
        const { rerender } = render(
            <FirstPickReel result={null} onDone={onDone} />,
        );

        act(() => {
            vi.advanceTimersByTime(REEL_SPIN_MS + 2000);
        });
        expect(screen.getByText('Spinning…')).toBeInTheDocument();

        rerender(<FirstPickReel result="raw" onDone={onDone} />);
        act(() => {
            vi.advanceTimersByTime(1000);
        });
        expect(screen.getByText('Raw picks first!')).toBeInTheDocument();
    });
});
