import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import FreeAgency from '@/pages/gm/free-agency';

const agent = (
    id: number,
    name: string,
    upfront: number,
    extra: object = {},
) => ({
    id,
    name,
    roster_type: 'superstar',
    weight_class: 'heavyweight',
    titles: [],
    injury: null,
    specialties: [],
    quotes: [
        { length_weeks: 52, label: '1 year', upfront, weekly: 8000, total: 0 },
        {
            length_weeks: 5,
            label: '5 weeks',
            upfront: 10,
            weekly: 99999,
            total: 0,
        },
    ],
    ...extra,
});

const page = {
    props: {
        game: { id: 1, roster_cap: 30 },
        available: [
            agent(1, 'Bret Hart', 150000, { roster_type: 'legend' }),
            agent(2, 'Al Snow', 100000),
            agent(3, 'Cherry', 120000, { roster_type: 'diva' }),
        ],
        locked: [],
        lengths: [
            { value: 52, label: '1 year' },
            { value: 5, label: '5 weeks' },
        ],
        has_room: true,
    },
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...rest }: { children: ReactNode; href: string }) => (
        <a {...rest}>{children}</a>
    ),
    router: { post: vi.fn() },
    usePage: () => page,
}));

const names = () =>
    screen
        .getAllByTestId(/^free-agent-\d+$/)
        .map((r) => within(r).getAllByRole('link')[0].textContent);

describe('Free Agency', () => {
    it('sorts by up-front cost for the length picked on each row', async () => {
        const user = userEvent.setup();
        render(<FreeAgency />);

        await user.click(screen.getByRole('button', { name: 'Up front' }));
        expect(names()).toEqual(['Al Snow', 'Cherry', 'Bret Hart']);

        await user.selectOptions(
            screen.getByLabelText('Contract length for Bret Hart'),
            '5',
        );
        expect(names()).toEqual(['Bret Hart', 'Al Snow', 'Cherry']);
    });

    it('says the roster is full on every row instead of a greyed-out Sign', () => {
        page.props = { ...page.props, has_room: false };
        render(<FreeAgency />);

        expect(screen.getByRole('status')).toHaveTextContent(
            'Your roster is full (30/30)',
        );
        expect(screen.queryByRole('button', { name: /^Sign / })).toBeNull();
        expect(
            within(screen.getByTestId('free-agent-2')).getByText('Roster full'),
        ).toBeInTheDocument();
        page.props = { ...page.props, has_room: true };
    });
});
