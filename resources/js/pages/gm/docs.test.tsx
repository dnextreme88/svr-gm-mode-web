import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Docs from '@/pages/gm/docs';

const variant = (overrides: Record<string, unknown>) => ({
    id: 1,
    variant: 'No Manager',
    sides: [1, 1],
    competitors: 2,
    managers: 0,
    fatigue_bars: 1,
    cost: 0,
    dq: true,
    countout: true,
    rope_breaks: true,
    ring_out: true,
    captain_fall: false,
    timed: false,
    titles_allowed: true,
    divas_only: false,
    divas_allowed: true,
    elimination: false,
    ppv_only: false,
    ppv_exclusive: null,
    brand: null,
    gm_bookable: true,
    special_referee: false,
    win_condition: null,
    ...overrides,
});

const props = {
    match_types: [
        {
            family: 'single',
            label: 'Normal Matches',
            variants: [
                variant({}),
                variant({
                    id: 2,
                    variant: 'Normal Triple Threat',
                    sides: [1, 1, 1],
                    dq: false,
                    countout: false,
                }),
            ],
        },
        {
            family: 'six_man_tag',
            label: '6-Man Tag',
            variants: [
                variant({
                    id: 3,
                    variant: 'Captain Fall Tornado 6-Man Tag Elimination',
                    sides: [3, 3],
                    captain_fall: true,
                    titles_allowed: false,
                    elimination: true,
                }),
            ],
        },
        {
            family: 'table',
            label: 'Table',
            variants: [
                variant({
                    id: 4,
                    variant: 'Single',
                    dq: false,
                    countout: false,
                    rope_breaks: false,
                    win_condition: 'Put through a table',
                }),
            ],
        },
    ],
    injuries: { minor: [], serious: [] },
    fatigue: {
        bar_size: 10,
        max: 100,
        safe_max: 70,
        serious_at: 100,
        rest_recovery_bars: 2,
        minor_chance: 0.15,
        escalation_chance: 0.2,
    },
    specialists: [],
    stipulations: [],
    promos: {
        slots: 2,
        types: [
            {
                key: 'advertising',
                name: 'Advertising',
                amount: 150000,
                variable_amount: false,
                success_rate: 1,
                fatigue_bars: 0,
                recovery_bars: 0,
                targets_rival: false,
                requirement: 'Nothing.',
                effect: 'Guaranteed flat payout next week.',
            },
            {
                key: 'interference_promotion',
                name: 'Interference Promotion',
                amount: -50000,
                variable_amount: false,
                success_rate: 0.5,
                fatigue_bars: 2,
                recovery_bars: 0,
                targets_rival: true,
                requirement:
                    'A non-Diva superstar who isn’t seriously injured.',
                effect: 'Sabotages the rival show.',
            },
            {
                key: 'inactive_superstar',
                name: 'Inactive Superstar',
                amount: -2000,
                variable_amount: false,
                success_rate: 0.9,
                fatigue_bars: 0,
                recovery_bars: 2,
                targets_rival: false,
                requirement:
                    'A wrestler who isn’t booked anywhere on this card.',
                effect: 'Rests a wrestler who isn’t being used.',
            },
        ],
    },
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    usePage: () => ({ props, url: '/gm/docs' }),
}));

const terms = (panel: HTMLElement) =>
    within(panel)
        .getAllByRole('term')
        .map((t) => t.textContent);

const rowBadges = (name: string) => {
    const row = screen.getByRole('row', { name: new RegExp(`^${name}`) });
    const cells = within(row).getAllByRole('cell');

    return {
        rules: [...cells[4].querySelectorAll('span')].map((s) => s.textContent),
        wins: [...cells[5].querySelectorAll('span')].map((s) => s.textContent),
    };
};

describe('Docs page', () => {
    it('lists match rules and win conditions alphabetically, side by side', () => {
        render(<Docs />);

        const rules = screen
            .getByRole('heading', { name: 'Match rules' })
            .closest('section') as HTMLElement;
        const wins = screen
            .getByRole('heading', { name: 'Win conditions' })
            .closest('section') as HTMLElement;

        expect(terms(rules)).toEqual([
            'DQ',
            'Elimination',
            'Ring out',
            'Rope breaks',
            'Special referee',
            'Timed',
            'Titles',
        ]);
        expect(terms(wins)).toContain('Count-out');
        expect(terms(wins)).toEqual(
            [...terms(wins)].sort((a, b) => (a ?? '').localeCompare(b ?? '')),
        );
        expect(rules.parentElement).toBe(wins.parentElement);
    });

    it('shows only the rules that apply, sorted, with count-out as a win condition', () => {
        render(<Docs />);

        expect(rowBadges('No Manager')).toEqual({
            rules: ['DQ', 'Ring out', 'Rope breaks', 'Titles'],
            wins: ['Count-out', 'Pinfall', 'Submission'],
        });
        expect(rowBadges('Normal Triple Threat')).toEqual({
            rules: ['Ring out', 'Rope breaks', 'Titles'],
            wins: ['Pinfall', 'Submission'],
        });
        expect(rowBadges('Captain Fall').wins).toEqual(['Captain fall']);
        expect(rowBadges('Single').wins).toEqual(['Table']);
    });

    it('marks the active tab and slides the panel in from the side moved towards', async () => {
        const user = userEvent.setup();
        render(<Docs />);

        const tab = (name: string) => screen.getByRole('tab', { name });

        expect(tab('Match types')).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByTestId('tab-indicator')).toBeInTheDocument();

        await user.click(tab('Specialties'));
        expect(tab('Specialties')).toHaveAttribute('data-active', 'true');
        expect(tab('Match types')).not.toHaveAttribute('data-active');
        expect(screen.getByRole('tabpanel')).toHaveClass(
            'animate-gm-slide-from-right',
        );

        await user.click(tab('Fatigue & injuries'));
        expect(screen.getByRole('tabpanel')).toHaveClass(
            'animate-gm-slide-from-left',
        );
    });

    it('documents every promo with its cost, success probability, fatigue and needs', async () => {
        const user = userEvent.setup();
        render(<Docs />);

        await user.click(screen.getByRole('tab', { name: 'Promos' }));

        expect(
            screen.getByRole('heading', { name: 'When promos air' }),
        ).toBeInTheDocument();
        const interference = screen.getByTestId('promo-interference_promotion');
        expect(interference).toHaveTextContent('Interference Promotion');
        expect(interference).toHaveTextContent('50%');
        expect(interference).toHaveTextContent('A non-Diva superstar');
        expect(
            interference.querySelectorAll('[data-state="filled"]'),
        ).toHaveLength(2);
        expect(screen.getByTestId('promo-advertising')).toHaveTextContent(
            '+$150,000',
        );
    });

    it('draws Inactive Superstar’s recovery as green bars', async () => {
        const user = userEvent.setup();
        render(<Docs />);

        await user.click(screen.getByRole('tab', { name: 'Promos' }));

        const bars = within(
            screen.getByTestId('promo-inactive_superstar'),
        ).getByRole('img', { name: 'Recovers 2 fatigue bars' });
        const filled = bars.querySelectorAll('[data-state="filled"]');
        expect(filled).toHaveLength(2);
        expect(filled[0].className).toContain('bg-zone-safe');
        expect(
            within(
                screen.getByTestId('promo-interference_promotion'),
            ).getByRole('img', { name: 'Adds 2 fatigue bars' }),
        ).toBeInTheDocument();
    });
});
