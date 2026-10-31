import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Schedule from '@/pages/gm/schedule';

const router = vi.hoisted(() => ({ visit: vi.fn(), post: vi.fn() }));
const requestJson = vi.hoisted(() => vi.fn());

vi.mock('@/lib/http', () => ({
    requestJson: (...args: unknown[]) => requestJson(...args),
}));

/** The step endpoint's answers for weeks 1–3 on the way to week 4. */
const steps = [1, 2, 3].map((week) => ({
    week,
    season_week: week,
    done: week === 3,
    stopped: null,
}));

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
                            name: 'Friday Night Smackdown',
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
                    day('2005-04-25', [
                        air({
                            week: 4,
                            season_week: 4,
                            state: 'open',
                            card_url: '/gm/saves/1/shows/9',
                        }),
                    ]),
                    day('2005-04-26'),
                    day('2005-04-27'),
                    day('2005-04-28'),
                    day('2005-04-29', [
                        air({
                            week: 4,
                            season_week: 4,
                            brand: 'smackdown',
                            name: 'Friday Night Smackdown',
                            mine: false,
                            // The rival's show this week.
                            state: 'rival',
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
    requestJson.mockReset();
});

describe('Schedule calendar', () => {
    it('opens on the current month with Sunday-first weekday columns', () => {
        render(<Schedule />);

        expect(screen.getByTestId('calendar-month')).toHaveTextContent(
            'April 2005',
        );
        expect(
            screen.getAllByRole('columnheader').map((h) => h.textContent),
        ).toEqual(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']);
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

    it('slides the month in the way the arrow points, but not on first load', async () => {
        const user = userEvent.setup();
        render(<Schedule />);

        expect(screen.getByTestId('calendar-days').className).not.toContain(
            'animate-gm-slide',
        );

        await user.click(screen.getByRole('button', { name: 'Next month' }));
        expect(screen.getByTestId('calendar-days')).toHaveClass(
            'animate-gm-slide-from-below',
        );
        expect(screen.getByTestId('calendar-month')).toHaveClass(
            'animate-gm-slide-from-below',
        );

        await user.click(
            screen.getByRole('button', { name: 'Previous month' }),
        );
        expect(screen.getByTestId('calendar-days')).toHaveClass(
            'animate-gm-slide-from-above',
        );
    });

    it('hovers each show in its brand colour, PPVs in gold', () => {
        render(<Schedule />);

        expect(screen.getByTestId('air-2005-04-04-weekly-raw')).toHaveClass(
            'hover:bg-raw',
        );
        expect(
            screen.getByTestId('air-2005-04-08-weekly-smackdown'),
        ).toHaveClass('hover:bg-sd');
        expect(
            screen.getByTestId('air-2005-04-24-ppv-shared').className,
        ).not.toMatch(/hover:bg-(raw|sd)/);
    });

    it('marks your card this week with the moving border, without a Book label', () => {
        render(<Schedule />);

        const cell = document.querySelector('[data-date="2005-04-25"]');
        const show = screen.getByTestId('air-2005-04-25-weekly-raw');

        expect(cell).toHaveClass('gm-current-week');
        expect(show).not.toHaveTextContent('Book');
        expect(show).toHaveAccessibleName(/this week, to book/);
    });

    it('doesn’t highlight the rival’s show this week', () => {
        render(<Schedule />);

        const cell = document.querySelector('[data-date="2005-04-29"]');

        expect(cell?.className).not.toContain('gm-current-week');
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

    it('asks before simulating to a future date, then airs it week by week with a progress bar', async () => {
        for (const step of steps) {
            requestJson.mockResolvedValueOnce(step);
        }
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

        // One step per week toward week 4, then the summary.
        await vi.waitFor(() => expect(router.post).toHaveBeenCalled());
        expect(requestJson).toHaveBeenCalledTimes(3);
        expect(requestJson).toHaveBeenCalledWith(
            'POST',
            '/gm/saves/1/simulate/step',
            { week: 4 },
        );
        expect(router.post).toHaveBeenCalledWith(
            '/gm/saves/1/simulate/finish',
            { week: 4, weeks: [1, 2, 3], stopped: null },
            expect.anything(),
        );

        const bar = within(dialog).getByRole('progressbar', {
            name: 'Simulation progress',
        });
        expect(bar).toHaveAttribute('aria-valuenow', '3');
        expect(bar).toHaveAttribute('aria-valuemax', '3');
        expect(within(dialog).getByText('3 / 3 weeks')).toBeInTheDocument();
    });

    it('stops after the week in progress when you press Stop', async () => {
        let release: (step: unknown) => void = () => {};
        requestJson.mockImplementationOnce(
            () => new Promise((resolve) => (release = resolve)),
        );
        const user = userEvent.setup();
        render(<Schedule />);

        await user.click(screen.getByTestId('air-2005-04-24-ppv-shared'));
        const dialog = screen.getByRole('dialog');
        await user.click(
            within(dialog).getByRole('button', { name: 'Simulate' }),
        );

        expect(
            within(dialog).getByRole('button', { name: 'Simulating…' }),
        ).toBeDisabled();
        await user.click(
            within(dialog).getByRole('button', {
                name: 'Stop after this week',
            }),
        );
        expect(dialog).toHaveTextContent('Stopping after this week…');

        release(steps[0]);

        await vi.waitFor(() => expect(router.post).toHaveBeenCalled());
        expect(requestJson).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/gm/saves/1/simulate/finish',
            { week: 4, weeks: [1], stopped: 'you stopped the simulation.' },
            expect.anything(),
        );
    });
});
