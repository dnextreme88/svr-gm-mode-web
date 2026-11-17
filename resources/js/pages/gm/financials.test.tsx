import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Financials from '@/pages/gm/financials';

const { router } = vi.hoisted(() => ({
    router: { post: vi.fn(), get: vi.fn() },
}));

const match = (name: string, cost: number) => ({
    category: 'match_cost',
    group: 'match_cost',
    label: 'Match Cost',
    memo: `${name} (Backlash)`,
    amount: -cost,
});

const props = {
    game: {
        id: 1,
        budget: 14_470_000,
        player_brand: 'smackdown',
        deficit: { blocked: false, weeks: 0 },
    },
    brand: 'smackdown',
    balance: 14_470_000,
    statement: {
        week: 4,
        season: 1,
        season_week: 4,
        title: 'Backlash + Friday Night Smackdown',
        groups: [
            {
                key: '4:ppv',
                name: 'Backlash',
                ppv: true,
                crowd: 24310,
                total: 1_045_500,
                lines: [
                    {
                        category: 'show_income',
                        group: 'show_income',
                        label: 'Fan attendance gate',
                        memo: 'Gate: Backlash (24,310 fans)',
                        amount: 1_215_500,
                    },
                    {
                        category: 'show_production',
                        group: 'show_production',
                        label: 'Show production',
                        memo: 'Production: Backlash',
                        amount: -100_000,
                    },
                    match('Ladder', 30_000),
                    match('Hardcore', 40_000),
                ],
            },
            {
                key: '4:smackdown',
                name: 'Friday Night Smackdown',
                ppv: false,
                crowd: 12000,
                total: 150_000,
                lines: [
                    {
                        category: 'promo_income',
                        group: 'promos',
                        label: 'Promos',
                        memo: 'Advertising payout',
                        amount: 200_000,
                    },
                    {
                        category: 'promo_cost',
                        group: 'promos',
                        label: 'Promos',
                        memo: 'Slander Promotion promo',
                        amount: -50_000,
                    },
                ],
            },
            {
                key: 'week',
                name: 'Week-wide',
                ppv: false,
                crowd: null,
                total: -500_000,
                lines: [
                    {
                        category: 'contract_upkeep',
                        group: 'contract_upkeep',
                        label: 'Superstar salaries',
                        memo: 'Salaries (30 superstars)',
                        amount: -480_000,
                    },
                    {
                        category: 'medical_rehab',
                        group: 'medical_rehab',
                        label: 'Medical Rehab',
                        memo: 'Medical rehab (2 minor, 1 serious injuries)',
                        amount: -20_000,
                    },
                ],
            },
        ],
        totals: [
            {
                group: 'show_income',
                label: 'Fan attendance gate',
                amount: 1_215_500,
            },
            { group: 'promos', label: 'Promos', amount: 150_000 },
            {
                group: 'contract_upkeep',
                label: 'Superstar salaries',
                amount: -480_000,
            },
        ],
        starting_budget: 0,
        income: 1_415_500,
        expenses: -720_000,
        net: 695_500,
    },
    history: [3, 4, 5].map((w) => ({
        week_number: w,
        season: 1,
        season_week: w,
        ppv: w === 4 ? 'Backlash' : null,
        income: 500_000,
        expenses: -400_000,
        net: w === 4 ? 695_500 : 100_000,
        balance: 14_000_000 + w,
    })),
    deficit_choices: [],
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        children,
        preserveScroll: _p,
        ...rest
    }: {
        children: ReactNode;
        href: string;
        preserveScroll?: boolean;
    }) => <a {...rest}>{children}</a>,
    router,
    usePage: () => ({ props }),
}));

describe('Financials', () => {
    it('groups the week by night, then week-wide lines', () => {
        render(<Financials />);

        const ppv = screen.getByTestId('night-4:ppv');
        expect(ppv).toHaveTextContent('Backlash');
        expect(ppv).toHaveTextContent('24,310 fans');
        expect(ppv).toHaveTextContent('+$1,045,500');
        expect(screen.getByTestId('night-week')).toHaveTextContent(
            'Superstar salaries',
        );
        expect(
            screen.getByText('Backlash + Friday Night Smackdown'),
        ).toBeInTheDocument();
    });

    it('adds a night’s promos into one line that opens to show each', async () => {
        const user = userEvent.setup();
        render(<Financials />);

        const promos = screen.getByTestId('statement-promos');
        const toggle = within(promos).getByRole('button', { name: /Promos/ });
        expect(toggle).toHaveTextContent('+$150,000');
        expect(toggle).toHaveAttribute('aria-expanded', 'false');

        await user.click(toggle);
        expect(toggle).toHaveAttribute('aria-expanded', 'true');
        expect(promos).toHaveTextContent('Advertising payout');
    });

    it('keeps the week when switching brand, and steps between weeks', () => {
        render(<Financials />);

        const brands = screen.getByRole('group', { name: 'Brand' });
        const rival = within(brands).getByRole('link', { name: /Raw/ });
        expect(rival.getAttribute('href')).toContain('brand=raw');
        expect(rival.getAttribute('href')).toContain('week=4');

        expect(
            screen
                .getByRole('link', { name: 'Previous week' })
                .getAttribute('href'),
        ).toContain('week=3');
        expect(
            screen
                .getByRole('link', { name: 'Next week' })
                .getAttribute('href'),
        ).toContain('week=5');
    });

    it('opens a week from the season trend', async () => {
        const user = userEvent.setup();
        render(<Financials />);

        await user.click(screen.getByRole('button', { name: /Week 5: net/ }));
        expect(router.get).toHaveBeenCalledWith(
            expect.stringContaining('week=5'),
            {},
            expect.anything(),
        );
    });
});

describe('Financials after an aired week (house rule, 2026-11-17)', () => {
    it('offers Back to Schedule on any week opened from the menu', () => {
        render(<Financials />);

        expect(
            screen.getByRole('link', { name: 'Back to Schedule' }),
        ).toHaveAttribute('href', '/gm/saves/1/schedule');
        expect(
            screen.queryByRole('link', { name: 'Continue to News' }),
        ).toBeNull();
    });

    it('continues to the News on the week just aired', () => {
        Object.assign(props, { wrap_up: true });

        try {
            render(<Financials />);

            expect(
                screen.getByRole('link', { name: 'Continue to News' }),
            ).toHaveAttribute('href', '/gm/saves/1/news?wrap=1');
        } finally {
            Object.assign(props, { wrap_up: false });
        }
    });
});
