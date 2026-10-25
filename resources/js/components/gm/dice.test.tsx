import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Dice } from '@/components/gm/dice';
import type { DiceFace } from '@/components/gm/dice';

const dice = () => screen.getByTestId('dice-icon');

describe('Dice', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it.each([1, 2, 3, 4, 5, 6] as DiceFace[])('shows %i pips', (face) => {
        render(<Dice face={face} />);

        expect(dice()).toHaveAttribute('data-face', String(face));
        expect(dice()).toHaveAttribute('aria-hidden', 'true');
        expect(dice().querySelectorAll('circle')).toHaveLength(face);
    });

    it('defaults to a five', () => {
        render(<Dice />);

        expect(dice()).toHaveAttribute('data-face', '5');
    });

    it('tumbles through faces while rolling, then lands on its face', () => {
        const { rerender } = render(<Dice face={3} rolling />);
        expect(dice()).toHaveClass('animate-gm-dice-tumble');

        const seen = new Set<string | null>();

        for (let i = 0; i < 6; i++) {
            act(() => {
                vi.advanceTimersByTime(90);
            });
            seen.add(dice().getAttribute('data-face'));
        }

        expect(seen.size).toBeGreaterThan(1);

        rerender(<Dice face={3} rolling={false} />);
        expect(dice()).toHaveAttribute('data-face', '3');
        expect(dice()).not.toHaveClass('animate-gm-dice-tumble');
    });
});
