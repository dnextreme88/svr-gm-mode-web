import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { WinningOdds, oddsBreakdownText } from '@/components/gm/winning-odds';
import type { WinningOddsSide } from '@/types/gm';

const odds: WinningOddsSide[] = [
    {
        side: 0,
        label: 'Jeff Hardy',
        chance: 60,
        points: 10,
        factors: [{ label: 'Ladder specialist', points: 10 }],
    },
    { side: 1, label: 'Spike Dudley', chance: 40, points: 0, factors: [] },
];

describe('WinningOdds', () => {
    it('shows each side’s chance as a meter', () => {
        render(<WinningOdds odds={odds} />);

        const meters = screen.getAllByRole('meter');
        expect(meters).toHaveLength(2);
        expect(meters[0]).toHaveAttribute('aria-valuenow', '60');
        expect(screen.getByText('40%')).toBeInTheDocument();
    });

    it('opens the breakdown on click, not only on hover', () => {
        render(<WinningOdds odds={odds} />);

        expect(screen.queryByTestId('winning-odds-breakdown')).toBeNull();
        fireEvent.click(screen.getByTestId('winning-odds-toggle'));

        const panel = screen.getByTestId('winning-odds-breakdown');
        expect(panel).toHaveTextContent('Ladder specialist');
        expect(panel).toHaveTextContent('+10%');
        expect(panel).toHaveTextContent('No modifiers');
    });

    it('puts the breakdown in the hover text too', () => {
        expect(oddsBreakdownText(odds)).toContain('Ladder specialist: +10');
        expect(oddsBreakdownText(odds)).toContain('Spike Dudley: 40%');
    });

    it('renders nothing without odds', () => {
        const { container } = render(<WinningOdds odds={null} />);

        expect(container).toBeEmptyDOMElement();
    });
});
