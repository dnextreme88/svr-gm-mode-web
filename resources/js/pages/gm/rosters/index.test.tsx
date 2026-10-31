import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Rosters from '@/pages/gm/rosters/index';

const wrestler = {
    id: 1,
    name: 'Edge',
    roster_type: 'superstar',
    weight_class: 'heavyweight',
    titles: [],
    sitting_out: false,
    fatigue: 20,
    injury: null,
    morale: 'content',
    wins: 3,
    losses: 1,
    win_streak: 2,
    loss_streak: 0,
    contract: { weeks_remaining: 12, weekly_cost: 8000 },
    popularity: null,
    specialties: ['ladder'],
};

const props = {
    game: {
        id: 1,
        player_brand: 'raw',
        roster_cap: 30,
        fatigue_config: {
            bar_size: 10,
            safe_max: 70,
            serious_at: 100,
            max: 100,
        },
    },
    brand: 'raw',
    pending: 0,
    wrestlers: [
        wrestler,
        {
            ...wrestler,
            id: 2,
            name: 'Trish Stratus',
            roster_type: 'diva',
            weight_class: 'featherweight',
            fatigue: 0,
            morale: 'happy',
            wins: 5,
            losses: 0,
            contract: { weeks_remaining: 3, weekly_cost: 9000 },
            specialties: [],
        },
        {
            ...wrestler,
            id: 3,
            name: 'Kane',
            fatigue: 60,
            morale: 'upset',
            wins: 0,
            losses: 4,
            contract: { weeks_remaining: 30, weekly_cost: 7000 },
            specialties: [],
        },
    ],
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...rest }: { children: ReactNode; href: string }) => (
        <a {...rest}>{children}</a>
    ),
    router: { visit: vi.fn() },
    usePage: () => ({ props }),
}));

const names = () =>
    screen
        .getAllByTestId('roster-row')
        .map((r) => within(r).getByRole('link').textContent);

describe('Rosters table', () => {
    it('drops Weight Class and Popularity and puts Specialties right after Streak', () => {
        render(<Rosters />);

        const headers = screen
            .getAllByRole('columnheader')
            .map((th) => th.textContent);
        expect(headers).toEqual([
            'Wrestler',
            'Fatigue',
            'Injury',
            'Morale',
            'W–L',
            'Streak',
            'Specialties',
            'Contract',
        ]);
        expect(screen.queryByText('Heavyweight')).toBeNull();
    });

    it('links each name to the wrestler page', () => {
        render(<Rosters />);

        expect(screen.getByRole('link', { name: 'Edge' })).toHaveAttribute(
            'href',
            expect.stringContaining('/rosters/1'),
        );
    });

    it('marks the brand being shown as the current page', () => {
        render(<Rosters />);

        expect(screen.getByRole('link', { name: 'Raw' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(
            screen.getByRole('link', { name: 'Smackdown' }),
        ).not.toHaveAttribute('aria-current');
    });

    it('sorts by a column, then reverses on a second click', async () => {
        const user = userEvent.setup();
        render(<Rosters />);

        const fatigue = screen.getByRole('button', { name: 'Fatigue' });
        await user.click(fatigue);
        expect(names()).toEqual(['Trish Stratus', 'Edge', 'Kane']);
        expect(fatigue.closest('th')).toHaveAttribute('aria-sort', 'ascending');

        await user.click(fatigue);
        expect(names()).toEqual(['Kane', 'Edge', 'Trish Stratus']);
        expect(fatigue.closest('th')).toHaveAttribute(
            'aria-sort',
            'descending',
        );

        await user.click(screen.getByRole('button', { name: 'Contract' }));
        expect(names()).toEqual(['Trish Stratus', 'Edge', 'Kane']);
        expect(fatigue.closest('th')).toHaveAttribute('aria-sort', 'none');
    });

    it('filters by roster type', async () => {
        const user = userEvent.setup();
        render(<Rosters />);

        await user.click(screen.getByRole('button', { name: 'Divas' }));

        expect(names()).toEqual(['Trish Stratus']);
        expect(screen.getByText('1 of 3')).toBeInTheDocument();
    });

    it('flags an expiring contract with more than colour', () => {
        render(<Rosters />);

        const trish = screen.getAllByTestId('roster-row')[1];
        expect(trish).toHaveTextContent('Expiring: 3w');
    });
});
