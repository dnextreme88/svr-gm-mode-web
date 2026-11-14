import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Rivalries from '@/pages/gm/rivalries';

const props = {
    game: { id: 1 },
    rivalries: [],
    max_heat: 100,
    heating_up: 50,
    rules: {
        meetings: 2,
        window_weeks: 4,
        start_heat: 10,
        heat_per_meeting: 10,
        heat_per_ppv_meeting: 20,
        heat_per_interference: 10,
        idle_decay: 20,
        milestone_weeks: 10,
        start_promo_bars: 2,
        continue_promo_bars: 1,
        continue_promo_cost: 2000,
    },
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props }),
}));

describe('Rivalries rules', () => {
    it('explains how to start a rivalry and how heat works', () => {
        render(<Rivalries />);

        const start = screen.getByTestId('rivalry-rules-start');
        expect(start).toHaveTextContent(
            'Book the same two superstars against each other 2 times within 4 weeks',
        );
        expect(start).toHaveTextContent('tires both 2 fatigue bars');

        const heat = screen.getByTestId('rivalry-rules-heat');
        expect(heat).toHaveTextContent('Heat (0 to 100)');
        expect(heat).toHaveTextContent(
            'costs $2,000 and tires both rivals 1 fatigue bar',
        );
        expect(heat).toHaveTextContent(
            'A week the rivals share a match adds 10, and a PPV they share a match at adds 20',
        );
        expect(heat).toHaveTextContent('holds the heat where it is');
        expect(heat).toHaveTextContent('Any other week loses 20 heat');
        expect(heat).toHaveTextContent(
            'A rivalry that reaches 100 has peaked: it ends the following week',
        );
        expect(heat).not.toHaveTextContent('blow-off');
        expect(within(heat).getByText(/makes the news/)).toHaveTextContent(
            'At 50',
        );
    });
});
