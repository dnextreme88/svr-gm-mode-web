import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Rosters from '@/pages/gm/rosters/index';

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
        {
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
            contract: null,
            popularity: null,
            specialties: ['ladder'],
        },
    ],
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { visit: vi.fn() },
    usePage: () => ({ props }),
}));

describe('Rosters table', () => {
    it('drops Weight Class and puts Specialties right after Streak', () => {
        render(<Rosters />);

        const headers = screen
            .getAllByRole('columnheader')
            .map((th) => th.textContent);
        expect(headers).toEqual([
            'Wrestler',
            'Fatigue',
            'Injury',
            'Morale',
            'Record (W-L)',
            'Streak',
            'Specialties',
            'Contract',
            'Popularity',
        ]);
        expect(screen.queryByText('Heavyweight')).toBeNull();
    });
});
