import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Financials from '@/pages/gm/financials';

const signing = (name: string) => ({
    category: 'signing_fee',
    label: 'Signing Fee',
    memo: `Signed ${name} (1 year)`,
    amount: -100000,
});

const props = {
    game: { id: 1, budget: 14_470_000, deficit: { blocked: false, weeks: 0 } },
    statement: {
        week: 1,
        starting_budget: 15_000_000,
        lines: [
            ...['Edge', 'Kane', 'Batista', 'Christian', 'JBL'].map(signing),
            {
                category: 'match_cost',
                label: 'Match Cost',
                memo: 'Ladder (Raw)',
                amount: -30000,
            },
        ],
        income: 0,
        expenses: -530000,
        net: -530000,
    },
    history: [
        {
            week_number: 1,
            season: 1,
            income: 0,
            expenses: -530000,
            net: -530000,
            balance: 14_470_000,
        },
    ],
    weekly_upkeep: 240000,
    deficit_choices: [],
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...rest }: { children: ReactNode; href: string }) => (
        <a {...rest}>{children}</a>
    ),
    router: { post: vi.fn() },
    usePage: () => ({ props }),
}));

describe('Financials', () => {
    it('shows the starting budget as the opening balance, not income', () => {
        render(<Financials />);

        expect(screen.getByText(/isn’t counted as income/)).toHaveTextContent(
            '$15,000,000',
        );
        expect(screen.getByText('Upkeep per week')).toBeInTheDocument();
        expect(screen.getByText('$240,000')).toBeInTheDocument();
    });

    it('groups line items by category and collapses long groups to a subtotal', async () => {
        const user = userEvent.setup();
        render(<Financials />);

        const fees = screen.getByTestId('ledger-signing_fee');
        expect(fees).toHaveTextContent('−$500,000');
        expect(fees).not.toHaveTextContent('Signed Edge');

        await user.click(
            within(fees).getByRole('button', { name: /Signing Fee/ }),
        );
        expect(fees).toHaveTextContent('Signed Edge (1 year)');

        // Short groups stay open.
        expect(screen.getByTestId('ledger-match_cost')).toHaveTextContent(
            'Ladder (Raw)',
        );
    });

    it('labels each week bar for screen readers', () => {
        render(<Financials />);

        expect(
            screen.getByRole('link', {
                name: 'Week 1: income $0, expenses $530,000, net −$530,000',
            }),
        ).toHaveAttribute('aria-current', 'page');
    });
});
