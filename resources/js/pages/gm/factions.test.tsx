import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import Factions from '@/pages/gm/factions';

const post = vi.fn();

const member = (id: number, name: string) => ({
    id,
    name,
    roster_type: 'superstar',
    weight_class: 'heavyweight',
    brand: 'raw',
});

const props = {
    game: { id: 3, player_brand: 'raw' },
    formation_wins: 3,
    max_members: 5,
    factions: [
        {
            id: 1,
            name: 'Team Xtreme',
            type: 'stable',
            origin: 'seeded',
            active: true,
            members: [member(1, 'Matt Hardy'), member(2, 'Jeff Hardy')],
            formed: 'Jan 17, 2000',
            formed_week: null,
            real_disbanded: 'Dec 24, 2001',
            disbanded: null,
            disbanded_week: null,
            wins: 4,
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
        {
            id: 2,
            name: 'Cenation',
            type: 'tag_team',
            origin: 'player',
            active: true,
            members: [member(3, 'John Cena'), member(4, 'Kane')],
            formed: 'Apr 4, 2005',
            formed_week: 'S1 W1',
            real_disbanded: null,
            disbanded: null,
            disbanded_week: null,
            wins: 3,
            losses: 0,
            titles: [],
        },
        {
            id: 3,
            name: 'Edge & Christian',
            type: 'tag_team',
            origin: 'seeded',
            active: false,
            members: [member(5, 'Edge'), member(6, 'Christian')],
            formed: 'Oct 26, 1998',
            formed_week: null,
            real_disbanded: 'Oct 18, 1999',
            disbanded: 'Apr 10, 2005',
            disbanded_week: 'S1 W2',
            wins: 0,
            losses: 0,
            titles: [],
        },
        {
            id: 4,
            name: 'The Enforcers',
            type: 'tag_team',
            origin: 'rival',
            active: true,
            members: [member(7, 'Rey Mysterio'), member(8, 'Booker T')],
            formed: 'Apr 8, 2005',
            formed_week: 'S1 W1',
            real_disbanded: null,
            disbanded: null,
            disbanded_week: null,
            wins: 3,
            losses: 0,
            titles: [],
        },
    ],
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: (...args: unknown[]) => post(...args) },
    usePage: () => ({ props }),
}));

describe('Factions page', () => {
    it('opens on the factions created in this career, by either brand', () => {
        render(<Factions />);

        expect(
            screen.getByRole('tab', { name: 'Created (2)' }),
        ).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByTestId('faction-2')).toHaveTextContent('Yours');
        expect(screen.getByTestId('faction-4')).toHaveTextContent('Rival');
        expect(screen.getByTestId('faction-4')).toHaveTextContent('3–0');
        expect(screen.queryByTestId('faction-1')).not.toBeInTheDocument();
        expect(screen.queryByTestId('faction-3')).not.toBeInTheDocument();
    });

    it('shows every faction under All, with record, dates and titles', async () => {
        const user = userEvent.setup();
        render(<Factions />);

        await user.click(screen.getByRole('tab', { name: 'All (4)' }));

        const xtreme = screen.getByTestId('faction-1');
        expect(xtreme).toHaveTextContent('Matt Hardy');
        expect(xtreme).toHaveTextContent('Jan 17, 2000');
        expect(xtreme).toHaveTextContent('4–1');
        expect(xtreme).toHaveTextContent('World Tag Team Championship');
        expect(screen.getByTestId('faction-3')).toBeInTheDocument();
    });

    it('filters by status', async () => {
        const user = userEvent.setup();
        render(<Factions />);

        await user.click(screen.getByRole('tab', { name: 'All (4)' }));
        await user.click(screen.getByRole('tab', { name: 'Disbanded (1)' }));

        const edgeChristian = screen.getByTestId('faction-3');
        expect(edgeChristian).toHaveTextContent('Apr 10, 2005');
        expect(
            within(edgeChristian).queryByRole('button'),
        ).not.toBeInTheDocument();
        expect(screen.queryByTestId('faction-1')).not.toBeInTheDocument();

        await user.click(screen.getByRole('tab', { name: 'Created (2)' }));

        expect(
            screen.getByText(/No faction has disbanded in this career/),
        ).toBeInTheDocument();
    });

    it('searches factions by member', async () => {
        const user = userEvent.setup();
        render(<Factions />);

        await user.click(screen.getByRole('tab', { name: 'All (4)' }));
        await user.type(
            screen.getByRole('searchbox', { name: 'Search members' }),
            'jeff',
        );

        expect(screen.getByTestId('faction-1')).toBeInTheDocument();
        expect(screen.queryByTestId('faction-3')).not.toBeInTheDocument();

        await user.clear(screen.getByRole('searchbox'));
        await user.type(screen.getByRole('searchbox'), 'nobody');

        expect(
            screen.getByText('No faction has a member matching “nobody”.'),
        ).toBeInTheDocument();
    });

    it('confirms before disbanding', async () => {
        const user = userEvent.setup();
        render(<Factions />);

        await user.click(
            within(screen.getByTestId('faction-2')).getByRole('button', {
                name: 'Disband',
            }),
        );

        expect(screen.getByText('Disband Cenation?')).toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();

        await user.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Disband',
            }),
        );

        expect(post).toHaveBeenCalledWith(
            '/gm/saves/3/factions/2/disband',
            {},
            expect.anything(),
        );
    });
});
