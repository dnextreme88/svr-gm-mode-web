import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import TitleHistory from '@/pages/gm/titles/history';

const visit = vi.fn();
const replace = vi.fn();

const reign = {
    id: 2,
    reign_number: 2,
    holder: 'Triple H',
    holder_type: 'wrestler',
    holder_reign: 1,
    won_week: 2,
    won_season: 1,
    won_season_week: 2,
    won_ppv: null,
    won_link: '/gm/saves/1/results/2?match=5',
    how: 'Hardcore — Single',
    vacated_reason: null,
    weeks_held: 4,
    defenses: 2,
    matches: [
        {
            id: 31,
            kind: 'defense',
            number: 2,
            opponents: 'Rey Mysterio',
            opponent_sides: [
                [
                    {
                        name: 'Rey Mysterio',
                        roster_type: 'superstar',
                        weight_class: 'cruiserweight',
                    },
                ],
            ],
            season: 1,
            season_week: 4,
            ppv: 'Backlash',
            match_type: 'Last Man Standing — Single',
            link: '/gm/saves/1/results/4?match=9&brand=raw',
        },
        {
            id: 30,
            kind: 'defense',
            number: 1,
            opponents: 'Snitsky',
            season: 1,
            season_week: 3,
            ppv: null,
            match_type: 'Hardcore — Single',
            link: '/gm/saves/1/results/3?match=7&brand=raw',
        },
        {
            id: 5,
            kind: 'won',
            number: null,
            opponents: 'Batista',
            season: 1,
            season_week: 2,
            ppv: null,
            match_type: 'Hardcore — Single',
            link: '/gm/saves/1/results/2?match=5',
        },
    ],
    active: true,
};

const props = {
    game: { id: 1, player_brand: 'raw' },
    titles: [
        {
            id: 1,
            key: 'wwe',
            name: 'WWE Championship',
            brand: 'raw',
            is_tag: false,
            current: reign,
            vacant: false,
            lineage: [
                reign,
                {
                    ...reign,
                    id: 1,
                    reign_number: null,
                    holder: 'Vacant',
                    holder_type: 'vacant',
                    holder_reign: null,
                    won_link: null,
                    how: null,
                    vacated_reason: 'injured',
                    defenses: 0,
                    matches: [],
                    active: false,
                },
            ],
        },
    ],
    hall: { raw: [], smackdown: [] },
    records: { overall: {}, per_title: {} },
    briefcases: [],
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        children,
        ...rest
    }: {
        children: ReactNode;
        href: string;
        className?: string;
    }) => <a {...rest}>{children}</a>,
    router: {
        visit: (url: string) => visit(url),
        replace: (options: unknown) => replace(options),
    },
    usePage: () => ({ props, url: '/gm/saves/1/titles' }),
}));

describe('Title History lineage', () => {
    it('shows #, Holder, Won, Reign, How, Weeks and Defenses only', () => {
        render(<TitleHistory />);

        // The lineage table, not the current reigns chart's table.
        const headers = within(
            document.getElementById('lineage') as HTMLElement,
        )
            .getAllByRole('columnheader')
            .map((th) => th.textContent);
        expect(headers).toEqual([
            '#',
            'Holder',
            'Won',
            'Reign',
            'How',
            'Weeks',
            'Defenses',
        ]);
    });

    it('lists each defense and then the title win under its reign, newest first', () => {
        render(<TitleHistory />);

        const rows = screen
            .getAllByTestId(/^(reign|defense|won)-\d+$/)
            .map((r) => r.dataset.testid);
        expect(rows).toEqual([
            'reign-2',
            'defense-31',
            'defense-30',
            'won-5',
            'reign-1',
        ]);

        const won = screen.getByTestId('won-5');
        expect(won).toHaveTextContent('vs. Batista');
        expect(within(won).getByText('Won').className).toContain('text-gold');

        const rey = screen.getByTestId('defense-31');
        expect(rey).toHaveTextContent('vs. Rey Mysterio');
        expect(within(rey).getByText('Backlash').className).toContain(
            'text-gold',
        );
        expect(rey).toHaveTextContent('Last Man Standing — Single');
    });

    it('opens a defense on its week’s results', async () => {
        const user = userEvent.setup();
        render(<TitleHistory />);

        await user.click(screen.getByTestId('defense-30'));

        expect(visit).toHaveBeenCalledWith(
            '/gm/saves/1/results/3?match=7&brand=raw',
        );
    });

    it('shows how the reign began and why a title was vacated', () => {
        render(<TitleHistory />);

        expect(screen.getByTestId('reign-2')).toHaveTextContent(
            'Hardcore — Single',
        );
        expect(screen.getByTestId('reign-1')).toHaveTextContent(
            'Vacated: Injured',
        );
    });

    it('links each defense to its results and colour-codes the opponent', () => {
        render(<TitleHistory />);

        const link = within(screen.getByTestId('defense-31')).getByRole(
            'link',
            { name: 'Defense 2 against Rey Mysterio' },
        );
        expect(link).toHaveAttribute(
            'href',
            '/gm/saves/1/results/4?match=9&brand=raw',
        );
        expect(within(link).getByText('Rey Mysterio')).toHaveAttribute(
            'data-tone',
            'cruiser',
        );
    });

    it('keeps the selected title in the URL', async () => {
        const user = userEvent.setup();
        render(<TitleHistory />);

        await user.click(
            screen.getByRole('button', { name: 'WWE Championship' }),
        );

        expect(replace).toHaveBeenCalledWith(
            expect.objectContaining({
                url: expect.stringContaining('title=wwe'),
                preserveState: true,
            }),
        );
    });
});

describe('Title History stats (house rule, 2026-11-21)', () => {
    const named = (name: string) => ({
        name,
        roster_type: 'superstar',
        weight_class: 'heavyweight',
    });
    const stats = {
        per_title: {
            1: {
                title_matches: 5,
                ppv_title_matches: 2,
                vacated: 3,
                vacated_reasons: { injured: 2, released: 1 },
                most_type: { types: ['Hardcore — Single'], value: 3 },
                least_type: { types: ['Ladder — Single'], value: 1 },
                champions: 4,
                teams: null,
                defenses: 7,
                most_reigns_superstar: null,
                most_reigns_team: null,
            },
        },
        overall: {
            most_titles: {
                holder: 'Edge',
                holder_parts: [named('Edge')],
                value: 3,
                titles: [
                    'WWE Championship',
                    'Intercontinental Championship',
                    'World Tag Team Championship',
                ],
            },
            grand_slams: [
                {
                    holder: 'Edge',
                    holder_parts: [named('Edge')],
                    value: 0,
                    titles: [
                        {
                            group: 'world',
                            championship: 'WWE Championship',
                            season: 1,
                            season_week: 6,
                            won_ppv: 'Backlash',
                        },
                        {
                            group: 'secondary',
                            championship: 'Intercontinental Championship',
                            season: 1,
                            season_week: 2,
                            won_ppv: null,
                        },
                        {
                            group: 'tag',
                            championship: 'World Tag Team Championship',
                            season: 1,
                            season_week: 10,
                            won_ppv: null,
                        },
                    ],
                    attained: { season: 1, season_week: 10, ppv: null },
                },
            ],
        },
    };

    it('shows the title’s stats and the Grand Slam champions in a modal', async () => {
        Object.assign(props, { stats });

        try {
            render(<TitleHistory />);

            expect(screen.getByTestId('stat-ppv')).toHaveTextContent(
                'Title matches held on PPV2of 5 title matches',
            );
            expect(screen.getByTestId('stat-vacated')).toHaveTextContent(
                /3.*Injured 2 · Released 1/,
            );
            expect(screen.getByTestId('stat-most-type')).toHaveTextContent(
                'Hardcore — Single3 matches',
            );
            expect(screen.getByTestId('stat-least-type')).toHaveTextContent(
                'Ladder — Single1 match',
            );
            expect(screen.getByTestId('stat-champions')).toHaveTextContent(
                '4 superstars',
            );
            expect(screen.getByTestId('stat-defenses')).toHaveTextContent('7');
            expect(screen.getByTestId('stat-most-titles')).toHaveTextContent(
                /Edge.*3 titles.*WWE · Intercontinental · World Tag Team/,
            );
            // Not a tag title: no superstar / team split.
            expect(
                screen.queryByTestId('stat-most-reigns-team'),
            ).not.toBeInTheDocument();

            await userEvent.click(
                within(screen.getByTestId('stat-grand-slams')).getByRole(
                    'button',
                    { name: 'View' },
                ),
            );

            const table = await screen.findByTestId('grand-slam-table');
            const rows = within(table).getAllByRole('row');

            // The header, then one row per title group.
            expect(rows).toHaveLength(4);
            expect(rows[1]).toHaveTextContent(
                /Edge.*WWE Championship.*S1 W6.*Backlash.*S1 W10/,
            );
            expect(rows[3]).toHaveTextContent(
                /World Tag Team Championship.*S1 W10/,
            );
        } finally {
            delete (props as { stats?: unknown }).stats;
        }
    });
});
