import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    SUPERSTAR_REEL_FLASH_MS,
    SUPERSTAR_REEL_SPIN_MS,
    SuperstarReel,
} from '@/components/gm/superstar-reel';

const pool = [
    { id: 1, name: 'Edge' },
    { id: 2, name: 'Kane' },
    { id: 3, name: 'Carlito' },
];

describe('SuperstarReel', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('spins through the pool, stops on the drafted superstar, then finishes', () => {
        const onDone = vi.fn();
        render(
            <SuperstarReel
                pool={pool}
                result={pool[1]}
                brand="smackdown"
                pickNumber={2}
                onDone={onDone}
            />,
        );

        expect(
            screen.getByRole('dialog', { name: 'Re-draft pick 2' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Smackdown is drafting…')).toBeInTheDocument();

        const seen = new Set<string | null>();

        for (let t = 0; t < SUPERSTAR_REEL_SPIN_MS - 300; t += 100) {
            act(() => {
                vi.advanceTimersByTime(100);
            });
            seen.add(
                screen
                    .getByTestId('superstar-reel-face')
                    .getAttribute('data-face'),
            );
        }

        // It ran through more than one face before stopping.
        expect(seen.size).toBeGreaterThan(1);

        act(() => {
            vi.advanceTimersByTime(800);
        });
        expect(screen.getByText('Smackdown drafts Kane!')).toBeInTheDocument();
        expect(screen.getByTestId('superstar-reel-face')).toHaveAttribute(
            'data-face',
            '2',
        );
        expect(onDone).not.toHaveBeenCalled();

        act(() => {
            vi.advanceTimersByTime(SUPERSTAR_REEL_FLASH_MS);
        });
        expect(onDone).toHaveBeenCalledOnce();
    });
});
