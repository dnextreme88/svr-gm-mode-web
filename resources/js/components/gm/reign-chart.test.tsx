import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import {
    ReignChart,
    reignSteps,
    reignSummary,
} from '@/components/gm/reign-chart';
import type { ReignTitle } from '@/components/gm/reign-chart';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));

const defense = (week: number, number: number, opponents: string) => ({
    kind: 'defense' as const,
    number,
    week,
    season: 1,
    season_week: week,
    opponents,
    ppv: null,
    match_type: 'Single',
    link: `/results/${week}`,
});

const titles: ReignTitle[] = [
    {
        id: 1,
        name: 'WWE Championship',
        brand: 'raw',
        current: {
            holder: 'Edge',
            won_week: 2,
            won_season: 1,
            won_season_week: 2,
            weeks_held: 10,
            defenses: 2,
            // Newest first, the won match last, as the page sends them.
            matches: [
                defense(9, 2, 'Kane'),
                defense(5, 1, 'John Cena'),
                { ...defense(2, 0, 'Batista'), kind: 'won', number: null },
            ],
        },
    },
    {
        id: 2,
        name: 'Intercontinental Championship',
        brand: 'raw',
        current: {
            holder: 'Shelton Benjamin',
            won_week: 10,
            won_season: 1,
            won_season_week: 10,
            weeks_held: 2,
            defenses: 0,
            matches: [],
        },
    },
    {
        id: 3,
        name: "Women's Championship",
        brand: 'raw',
        current: null,
    },
    {
        id: 4,
        name: 'World Heavyweight Championship',
        brand: 'smackdown',
        current: {
            holder: 'Batista',
            won_week: 1,
            won_season: 1,
            won_season_week: 1,
            weeks_held: 11,
            defenses: 0,
            matches: [],
        },
    },
];

describe('reignSteps', () => {
    it('steps up one at each defense, from the week won to this week', () => {
        expect(reignSteps(titles[0]).map((s) => [s.x, s.y])).toEqual([
            [0, 0],
            [3, 0],
            [3, 1],
            [7, 1],
            [7, 2],
            [10, 2],
        ]);
    });

    it('is flat for a reign with no defenses and empty while vacant', () => {
        expect(reignSteps(titles[1]).map((s) => [s.x, s.y])).toEqual([
            [0, 0],
            [2, 0],
        ]);
        expect(reignSteps(titles[2])).toEqual([]);
    });
});

describe('ReignChart', () => {
    it('opens on the given brand, names the longest reign and the vacant titles', () => {
        render(<ReignChart titles={titles} defaultBrand="raw" />);

        expect(screen.getByTestId('reign-summary')).toHaveTextContent(
            'Longest current reign: Edge (WWE Championship), 10 weeks. Most defenses: Edge (WWE Championship), 2. Vacant: Women’s Championship.'.replace(
                '’',
                "'",
            ),
        );
        expect(
            screen.getByRole('img', {
                name: 'Edge, WWE Championship: defense 1 against John Cena, week 3 of the reign',
            }),
        ).toBeInTheDocument();
    });

    it('switches brands', async () => {
        const user = userEvent.setup();
        render(<ReignChart titles={titles} defaultBrand="raw" />);

        await user.click(screen.getByRole('button', { name: 'Smackdown' }));

        expect(
            screen.getByRole('button', { name: 'Smackdown' }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByTestId('reign-summary')).toHaveTextContent(
            'Longest current reign: Batista (World Heavyweight Championship), 11 weeks.',
        );
    });

    it('shows a defense when it is focused', async () => {
        const user = userEvent.setup();
        render(<ReignChart titles={titles} defaultBrand="raw" />);

        await user.tab();
        await user.tab();
        await user.tab();
        screen
            .getByRole('img', {
                name: 'Edge, WWE Championship: defense 2 against Kane, week 7 of the reign',
            })
            .focus();

        expect(await screen.findByRole('status')).toHaveTextContent(
            'Defense 2 vs. Kane',
        );
    });

    it('sums up without defenses', () => {
        expect(
            reignSummary([
                {
                    title: titles[1],
                    color: '#000',
                    holder: 'Shelton Benjamin',
                    weeks: 1,
                    defenses: [],
                    steps: [],
                    offset: 0,
                },
            ]),
        ).toBe(
            'Longest current reign: Shelton Benjamin (Intercontinental Championship), 1 week.',
        );
    });
});
