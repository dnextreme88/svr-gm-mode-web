import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Faction from '@/pages/gm/faction';

const member = (id: number, name: string) => ({
    id,
    name,
    roster_type: 'superstar',
    weight_class: 'light_heavyweight',
    brand: 'raw',
});

const outcome = (position: number, winners: string, losers: string[]) => ({
    position,
    match_type: 'Tag Team — Normal Tag',
    title: position === 2 ? 'World Tag Team Championship' : null,
    winners_label: winners,
    losers,
    method_label: 'Pinfall',
    finish_label: null,
    rating: null,
    fan_delta: null,
});

let props: Record<string, unknown>;

const baseProps = () => ({
    game: { id: 3, player_brand: 'raw' },
    faction: {
        id: 7,
        name: 'Team Xtreme',
        type: 'tag_team',
        origin: 'seeded',
        active: true,
        members: [member(1, 'Matt Hardy'), member(2, 'Jeff Hardy')],
        formed: 'Jan 17, 2000',
        formed_week: null,
        real_disbanded: 'Dec 24, 2001',
        disbanded: null,
        disbanded_week: null,
        wins: 1,
        losses: 1,
        titles: [
            {
                id: 8,
                title: 'World Tag Team Championship',
                won: 'S1 W3',
                current: true,
            },
        ],
    },
    shows: [
        {
            id: 11,
            name: 'Monday Night Raw',
            week_number: 3,
            show_type: 'weekly',
            brand: 'raw',
            outcomes: [
                outcome(1, 'Team Xtreme', ['Carlito', 'Snitsky']),
                outcome(2, 'Carlito & Snitsky', ['Team Xtreme']),
            ],
        },
    ],
});

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    usePage: () => ({ props }),
}));

describe('Faction page', () => {
    beforeEach(() => {
        props = baseProps();
    });

    it('shows the faction, its record and titles', () => {
        render(<Faction />);

        expect(
            screen.getByRole('heading', { name: 'Team Xtreme' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Matt Hardy')).toBeInTheDocument();
        expect(screen.getByText('1–1')).toBeInTheDocument();
        expect(
            screen.getByText('Real life: Jan 17, 2000 – Dec 24, 2001'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: '← All factions' }),
        ).toHaveAttribute('href', '/gm/saves/3/factions');
    });

    it('lists the match history in the Reports table, one panel per show', () => {
        render(<Faction />);

        expect(
            screen.getByText('Week 3 · Monday Night Raw'),
        ).toBeInTheDocument();
        const rows = within(screen.getByRole('table')).getAllByRole('row');
        // Header plus two matches.
        expect(rows).toHaveLength(3);
        expect(rows[1]).toHaveTextContent('Team Xtreme');
        expect(rows[1]).toHaveTextContent('Carlito, Snitsky');
        expect(rows[2]).toHaveTextContent('World Tag Team Championship');
    });

    it('says so when the faction has no matches together yet', () => {
        props = { ...baseProps(), shows: [] };
        render(<Faction />);

        expect(
            screen.getByText('No matches together yet.'),
        ).toBeInTheDocument();
    });
});
