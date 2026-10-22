import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Schedule from '@/pages/gm/schedule';

const router = vi.hoisted(() => ({ visit: vi.fn(), post: vi.fn() }));

const air = (overrides: Record<string, unknown>) => ({
    week: 1,
    season_week: 1,
    kind: 'weekly',
    brand: 'raw',
    ppv: null,
    name: 'Monday Night Raw',
    mine: true,
    show_id: null,
    state: 'upcoming',
    results_url: null,
    card_url: null,
    can_simulate: false,
    ...overrides,
});

const day = (date: string, airs: unknown[] = [], inMonth = true) => ({
    date,
    day: Number(date.slice(-2)),
    in_month: inMonth,
    airs,
});

const props = {
    game: {
        id: 1,
        week: 1,
        season: 1,
        season_week: 1,
        player_brand: 'raw',
        rival_brand: 'smackdown',
    },
    season: 1,
    seasons: [1],
    initial_month: '2005-04',
    months: [
        {
            key: '2005-04',
            label: 'April 2005',
            rows: [
                [
                    day('2005-04-03'),
                    day('2005-04-04', [
                        air({
                            state: 'aired',
                            results_url: '/results/1?show=1',
                        }),
                    ]),
                    day('2005-04-05'),
                    day('2005-04-06'),
                    day('2005-04-07'),
                    day('2005-04-08', [
                        air({
                            brand: 'smackdown',
                            name: 'Friday Night SmackDown!',
                            mine: false,
                            state: 'aired',
                            results_url: '/results/1?brand=smackdown&show=2',
                        }),
                    ]),
                    day('2005-04-09'),
                ],
                [
                    day('2005-04-24', [
                        air({
                            week: 4,
                            season_week: 4,
                            kind: 'ppv',
                            brand: null,
                            name: 'Backlash',
                            ppv: {
                                key: 'backlash',
                                name: 'Backlash',
                                brand_scope: 'raw',
                            },
                            can_simulate: true,
                        }),
                    ]),
                    day('2005-04-25'),
                    day('2005-04-26'),
                    day('2005-04-27'),
                    day('2005-04-28'),
                    day('2005-04-29', [
                        air({
                            week: 4,
                            season_week: 4,
                            brand: 'smackdown',
                            name: 'Friday Night SmackDown!',
                            mine: false,
                        }),
                    ]),
                    day('2005-04-30'),
                ],
            ],
        },
        {
            key: '2005-05',
            label: 'May 2005',
            rows: [[day('2005-05-01')]],
        },
    ],
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router,
    usePage: () => ({ props }),
}));

beforeEach(() => {
    router.visit.mockClear();
    router.post.mockClear();
});

describe('Schedule calendar', () => {
    it('opens on the current month with Sunday-first weekday columns', () => {
        render(<Schedule />);

        expect(screen.getByTestId('calendar-month')).toHaveTextContent(
            'April 2005',
        );
        expect(
            screen.getAllByRole('columnheader').map((h) => h.textContent),
        ).toEqual(['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']);
    });

    it('steps between months with the arrows', async () => {
        const user = userEvent.setup();
        render(<Schedule />);

        expect(
            screen.getByRole('button', { name: 'Previous month' }),
        ).toBeDisabled();
        await user.click(screen.getByRole('button', { name: 'Next month' }));

        expect(screen.getByTestId('calendar-month')).toHaveTextContent(
            'May 2005',
        );
    });

    it('opens an aired show’s results, the rival’s included', async () => {
        const user = userEvent.setup();
        render(<Schedule />);

        await user.click(screen.getByTestId('air-2005-04-08-weekly-smackdown'));

        expect(router.visit).toHaveBeenCalledWith(
            '/results/1?brand=smackdown&show=2',
        );
    });

    it('won’t simulate to the rival’s show nights', async () => {
        const user = userEvent.setup();
        render(<Schedule />);

        const rival = screen.getByTestId('air-2005-04-29-weekly-smackdown');
        expect(rival).toBeDisabled();
        expect(rival).not.toHaveAccessibleName(/simulate/);

        await user.click(rival);

        expect(screen.queryByRole('dialog')).toBeNull();
        expect(router.post).not.toHaveBeenCalled();
    });

    it('asks before simulating to a future date, then posts the target week', async () => {
        const user = userEvent.setup();
        render(<Schedule />);

        await user.click(screen.getByTestId('air-2005-04-24-ppv-shared'));

        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent('Simulate to week 4?');
        expect(dialog).toHaveTextContent('from week 1 to week 3');
        expect(router.post).not.toHaveBeenCalled();

        await user.click(
            within(dialog).getByRole('button', { name: 'Simulate' }),
        );

        expect(router.post).toHaveBeenCalledWith(
            expect.stringContaining('/simulate'),
            { week: 4 },
            expect.anything(),
        );
    });
});
