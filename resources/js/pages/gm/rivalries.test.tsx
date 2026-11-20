import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Rivalries from '@/pages/gm/rivalries';

const props: Record<string, unknown> = {
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
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
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

describe('Brand column (house rule, 2026-11-15)', () => {
    it('shows the rivalry’s brand logo before the rivalry', () => {
        props.rivalries = [
            {
                id: 1,
                wrestler_a: 'Batista',
                wrestler_b: 'Undertaker',
                brand: 'smackdown',
                brand_a: 'smackdown',
                brand_b: 'smackdown',
                heat: 40,
                weeks_active: 3,
                started_week: 2,
                active: true,
                ended_week: null,
            },
        ];
        render(<Rivalries />);

        const headers = screen
            .getAllByRole('columnheader')
            .map((h) => h.textContent);
        expect(headers.slice(0, 2)).toEqual(['Brand', 'Rivalry']);
        expect(
            within(screen.getAllByRole('row')[1]).getByAltText('Smackdown'),
        ).toBeInTheDocument();
        props.rivalries = [];
    });
});

const row = (overrides: Record<string, unknown>) => ({
    id: 1,
    wrestler_a: 'Edge',
    wrestler_b: 'John Cena',
    brand: 'raw',
    brand_a: 'raw',
    brand_b: 'raw',
    heat: 40,
    weeks_active: 3,
    started_week: 2,
    active: true,
    ended_week: null,
    ...overrides,
});

describe('Heat colours (house rule, 2026-11-20)', () => {
    it('is yellow to 40, orange from 50, red from 80', () => {
        props.heat_colors = [50, 80];
        props.rivalries = [
            row({ id: 1, heat: 40 }),
            row({ id: 2, heat: 50 }),
            row({ id: 3, heat: 70 }),
            row({ id: 4, heat: 80 }),
        ];
        render(<Rivalries />);

        expect(screen.getByTestId('heat-bar-1')).toHaveClass('bg-zone-minor');
        expect(screen.getByTestId('heat-bar-2')).toHaveClass('bg-zone-warn');
        expect(screen.getByTestId('heat-bar-3')).toHaveClass('bg-zone-warn');
        expect(screen.getByTestId('heat-bar-4')).toHaveClass('bg-zone-serious');
        props.rivalries = [];
    });
});

describe('Match history (house rule, 2026-11-20)', () => {
    const outcome = {
        id: 9,
        position: 1,
        match_type: 'Hardcore — Single',
        winners: ['Edge'],
        winners_label: 'Edge',
        losers: ['John Cena'],
        loser_sides: [['John Cena']],
        winner_manager: 'Lita',
        loser_side_managers: [null],
        booked: false,
        champions: [],
        captains: [],
        managers: ['Lita'],
        referee: null,
        method: 'pinfall',
        method_label: 'Pinfall',
        finish_event: null,
        finish_label: null,
        summary: '',
        title: null,
        title_banner: null,
        stipulation: null,
        stipulation_outcome: null,
    };

    it('has no toggle before the rivals have met', () => {
        props.rivalries = [row({ matches: [] })];
        render(<Rivalries />);

        expect(screen.queryByRole('button', { name: /match/ })).toBeNull();
        props.rivalries = [];
    });

    it('opens the rivals’ matches with their week, PPV and outcome', async () => {
        const user = userEvent.setup();
        props.rivalries = [
            row({
                matches: [
                    {
                        id: 9,
                        season: 1,
                        season_week: 4,
                        ppv: 'Backlash',
                        link: '/gm/saves/1/results/4?match=9',
                        outcome,
                    },
                ],
            }),
        ];
        render(<Rivalries />);

        const toggle = screen.getByRole('button', {
            name: '1 match between Edge and John Cena',
        });
        expect(toggle).toHaveAttribute('aria-expanded', 'false');

        await user.click(toggle);
        expect(toggle).toHaveAttribute('aria-expanded', 'true');

        const table = screen.getByRole('table', {
            name: 'Matches between Edge and John Cena',
        });
        expect(
            within(table)
                .getAllByRole('columnheader')
                .map((h) => h.textContent),
        ).toEqual(['Week/PPV', 'Match outcome']);
        expect(table).toHaveTextContent('S1 W4Backlash');
        expect(
            within(table).getByRole('link', {
                name: 'Edge w/ Lita def. John Cena · Hardcore — Single',
            }),
        ).toHaveAttribute('href', '/gm/saves/1/results/4?match=9');
        props.rivalries = [];
    });
});
