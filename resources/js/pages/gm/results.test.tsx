import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Results from '@/pages/gm/results';
import type { MatchOutcome, PendingMatch, ResultPromo } from '@/types/gm';

function outcome(
    position: number,
    winners: string[],
    losers: string[],
    overrides: Partial<MatchOutcome> = {},
): MatchOutcome {
    return {
        id: position,
        position,
        match_type: 'Normal Matches — No Manager',
        winners,
        losers,
        loser_sides: losers.map((side) => side.split(' & ')),
        booked: false,
        champions: [],
        captains: [],
        managers: [],
        method: 'pinfall',
        method_label: 'Pinfall',
        finish_event: null,
        finish_label: null,
        summary: `${winners.join(' & ')} wins.`,
        title: null,
        title_banner: null,
        stipulation: null,
        stipulation_outcome: null,
        rating: null,
        fan_delta: null,
        ...overrides,
    };
}

const props = {
    game: {
        id: 1,
        fatigue_config: {
            bar_size: 10,
            safe_max: 70,
            serious_at: 100,
            max: 100,
        },
    },
    week_number: 1,
    new_emails: 0,
    shows: [
        {
            id: 7,
            name: 'Monday Night Raw',
            show_type: 'weekly',
            brand: 'raw',
            status: 'aired',
            week_number: 1,
            ppv_event_id: null,
            match_slot_count: 6,
            promo_slot_count: 2,
            outcomes: [
                outcome(
                    1,
                    ['Chris Masters', 'Kurt Angle'],
                    ['Big Show & Danny Basham'],
                    {
                        method_label: 'Count-out',
                        title: 'World Tag Team Championship',
                    },
                ),
                outcome(2, ['Kane'], ['Carlito']),
            ],
            fatigue: [
                {
                    id: 11,
                    name: 'Zack Ryder',
                    roster_type: 'superstar',
                    weight_class: 'heavyweight',
                    start: 0,
                    after_shows: 10,
                    end: 0,
                    recovered_bars: 2,
                    rested: false,
                },
                {
                    id: 10,
                    name: 'Kane',
                    roster_type: 'superstar',
                    weight_class: 'ultra_heavyweight',
                    start: 20,
                    after_shows: 60,
                    end: 40,
                    recovered_bars: 2,
                    rested: false,
                },
            ],
        },
    ],
};

function pending(
    position: number,
    sides: string[][],
    overrides: Partial<PendingMatch> = {},
): PendingMatch {
    return {
        id: 100 + position,
        pending: true,
        is_next: false,
        position,
        match_type: 'Normal Matches — No Manager',
        win_condition: null,
        sides: sides.map((names, side) => ({
            side,
            members: names.map((name, i) => ({
                id: side * 10 + i + 1,
                name,
                roster_type: 'superstar',
                weight_class: 'heavyweight',
            })),
        })),
        managers: [],
        referee: null,
        title: null,
        stipulation: null,
        champions: [],
        captains: [],
        ...overrides,
    };
}

function promo(
    position: number,
    beforePosition: number,
    status: ResultPromo['status'],
): ResultPromo {
    return {
        position,
        before_position: beforePosition,
        type: 'Interview Space',
        status,
        targets: [
            {
                id: 20,
                name: 'Rob Van Dam',
                roster_type: 'superstar',
                weight_class: 'heavyweight',
            },
        ],
    };
}

const page: { props: Record<string, unknown> } = { props };
const post = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: (...args: unknown[]) => post(...args) },
    Link: ({
        children,
        href,
        className,
    }: {
        children: ReactNode;
        href: string;
        className?: string;
    }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
    usePage: () => ({ props: page.props }),
}));

afterEach(() => {
    vi.useRealTimers();
    page.props = props;
    post.mockReset();
});

describe('Results page', () => {
    it('lists every result with a chevron and no Enter-to-continue prompt', () => {
        render(<Results />);

        expect(
            screen.getByRole('heading', { name: 'Monday Night Raw' }).className,
        ).toContain('text-2xl');
        expect(
            screen.getByText('◆ World Tag Team Championship').className,
        ).toContain('from-gold-hot');
        expect(screen.queryByText(/press enter/i)).toBeNull();
        expect(screen.queryByTestId('result-detail')).toBeNull();
    });

    it('opens a result’s card from its chevron and steps through with the arrows', async () => {
        const user = userEvent.setup();
        render(<Results />);

        await user.click(
            screen.getByRole('button', {
                name: 'Show details for Monday Night Raw match 1',
            }),
        );

        const detail = screen.getByTestId('result-detail');
        expect(within(detail).getByText('1 / 2').className).toContain(
            'text-lg',
        );
        expect(
            within(detail).getByRole('heading', {
                name: 'Chris Masters & Kurt Angle',
            }),
        ).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Next result' }));
        expect(within(detail).getByText('2 / 2')).toBeInTheDocument();
        expect(
            within(detail).getByRole('heading', { name: 'Kane' }),
        ).toBeInTheDocument();

        // Wraps around both ways.
        await user.click(screen.getByRole('button', { name: 'Next result' }));
        expect(within(detail).getByText('1 / 2')).toBeInTheDocument();
        await user.click(
            screen.getByRole('button', { name: 'Previous result' }),
        );
        expect(within(detail).getByText('2 / 2')).toBeInTheDocument();
    });

    it('steps with the arrow keys and closes from the same chevron', async () => {
        const user = userEvent.setup();
        render(<Results />);

        await user.click(
            screen.getByRole('button', {
                name: 'Show details for Monday Night Raw match 1',
            }),
        );
        await user.keyboard('{ArrowRight}');
        expect(screen.getByText('2 / 2')).toBeInTheDocument();

        await user.click(
            screen.getByRole('button', {
                name: 'Hide details for Monday Night Raw match 2',
            }),
        );
        expect(screen.queryByTestId('result-detail')).toBeNull();
    });

    it('continues to the News page', () => {
        render(<Results />);

        expect(screen.getByRole('link', { name: 'Continue' })).toHaveAttribute(
            'href',
            expect.stringContaining('/news'),
        );
    });

    it('shows a serious injury instead of recovery, and a minor one beside it', () => {
        vi.useFakeTimers();
        page.props = {
            ...props,
            shows: [
                {
                    ...props.shows[0],
                    fatigue: [
                        {
                            ...props.shows[0].fatigue[1],
                            start: 80,
                            after_shows: 110,
                            end: 90,
                            injury: { severity: 'serious', label: 'Torn ACL' },
                        },
                        {
                            ...props.shows[0].fatigue[0],
                            start: 60,
                            after_shows: 80,
                            end: 60,
                            injury: {
                                severity: 'minor',
                                label: 'Sprained Wrist',
                            },
                        },
                    ],
                },
            ],
        };
        render(<Results />);

        const kane = screen.getByTestId('fatigue-10');
        const ryder = screen.getByTestId('fatigue-11');

        // After the show: all bars full and a red 110% Injury.
        act(() => {
            vi.advanceTimersByTime(1100);
        });
        expect(within(kane).getByRole('meter')).toHaveAttribute(
            'aria-valuenow',
            '110',
        );
        expect(kane).toHaveTextContent('110%Injury');
        expect(
            within(kane)
                .getByRole('meter')
                .querySelectorAll('[data-state="filled"]'),
        ).toHaveLength(10);

        act(() => {
            vi.advanceTimersByTime(1100);
        });
        expect(within(kane).queryByRole('meter')).toBeNull();
        expect(within(kane).getByTestId('injury-10')).toHaveTextContent(
            'Serious Injury: Torn ACL!',
        );
        expect(kane).toHaveTextContent('80% → 110% → Injured');

        // A minor injury still recovers, with the injury beside it.
        expect(within(ryder).getByRole('meter')).toHaveAttribute(
            'aria-valuenow',
            '60',
        );
        expect(within(ryder).getByTestId('injury-11')).toHaveTextContent(
            'Minor Injury: Sprained Wrist!',
        );
        expect(ryder).toHaveTextContent('60% → 80% → 60%');
    });

    it('animates each wrestler’s fatigue: going in, after the show, after recovery', () => {
        vi.useFakeTimers();
        render(<Results />);

        const kane = screen.getByTestId('fatigue-10');
        const meter = () => within(kane).getByRole('meter');
        expect(meter()).toHaveAttribute('aria-valuenow', '20');

        act(() => {
            vi.advanceTimersByTime(1100);
        });
        expect(meter()).toHaveAttribute('aria-valuenow', '60');

        act(() => {
            vi.advanceTimersByTime(1100);
        });
        expect(meter()).toHaveAttribute('aria-valuenow', '40');
        expect(kane).toHaveTextContent('−2 bars');
    });

    it('labels each result M1, M2… and the main event', () => {
        render(<Results />);

        expect(screen.getByTestId('match-label-7-1')).toHaveTextContent('M1');
        expect(screen.getByTestId('match-label-7-2')).toHaveTextContent('M2');
    });

    it('labels the last slot on the card as the main event', () => {
        props.shows[0].outcomes[1].position = 6;
        render(<Results />);
        props.shows[0].outcomes[1].position = 2;

        expect(screen.getByTestId('match-label-7-6')).toHaveTextContent(
            'Main Event',
        );
    });

    it('makes the whole row the hover target, filled with the brand colour', () => {
        render(<Results />);

        const row = screen.getByRole('button', {
            name: 'Show details for Monday Night Raw match 1',
        });
        // The row button holds the participants, the method and the chevron.
        expect(row.className).toContain('hover:bg-brand');
        expect(row.className).toContain('w-full');
        expect(row).toHaveTextContent('Chris Masters & Kurt Angle');
        expect(row).toHaveTextContent('Count-out');
        expect(
            within(row).getByTestId('result-chevron').getAttribute('class'),
        ).toContain('size-6');
    });

    it('draws M1, M2… in the win condition’s grey, not as a red chip', () => {
        render(<Results />);

        const label = screen.getByTestId('match-label-7-1');
        expect(label.className).toContain('gm-label');
        expect(label.className).toContain('text-ink-dim');
        expect(label.className).not.toContain('bg-brand');
    });

    it('lists fatigue alphabetically and shows a replay icon', () => {
        render(<Results />);

        const names = screen
            .getAllByTestId(/^fatigue-\d+$/)
            .map((row) => row.querySelector('span')?.textContent);
        expect(names).toEqual(['Kane', 'Zack Ryder']);
        expect(
            screen.getByRole('button', { name: 'Replay' }).querySelector('svg'),
        ).not.toBeNull();
    });

    it('tags the champions going into a title match with (C)', () => {
        page.props = {
            ...props,
            shows: [
                {
                    ...props.shows[0],
                    outcomes: [
                        outcome(1, ['John Cena'], ['Edge'], {
                            title: 'WWE Championship',
                            champions: ['Edge'],
                        }),
                    ],
                },
            ],
        };
        render(<Results />);

        const row = screen.getByTestId('result-7-1');
        expect(row).toHaveTextContent('John Cenadef.Edge(C)');
        const tag = within(row).getByText('(C)');
        expect(tag.className).toContain('text-gold');
        expect(tag.className).toContain('text-sm');
    });

    it('tags each team captain in a Captain Fall match with (Cpt)', () => {
        page.props = {
            ...props,
            shows: [
                {
                    ...props.shows[0],
                    outcomes: [
                        outcome(
                            1,
                            ['Tommy Dreamer', 'Kane', 'Kurt Angle'],
                            ['Christian & Rhino & Spike Dudley'],
                            { captains: ['Tommy Dreamer', 'Christian'] },
                        ),
                    ],
                },
            ],
        };
        render(<Results />);

        const row = screen.getByTestId('result-7-1');
        expect(row).toHaveTextContent(
            'Tommy Dreamer(Cpt) & Kane & Kurt Angledef.Christian(Cpt) & Rhino & Spike Dudley',
        );
        for (const tag of within(row).getAllByText('(Cpt)')) {
            expect(tag.className).toContain('text-gold');
        }
    });

    it('lists promos where they aired, with their result', () => {
        page.props = {
            ...props,
            shows: [
                {
                    ...props.shows[0],
                    promos: [promo(1, 2, 'succeeded'), promo(2, 6, 'failed')],
                },
            ],
        };
        render(<Results />);

        const rows = screen
            .getAllByTestId(/^(result|promo)-7-\d+$/)
            .map((r) => r.dataset.testid);
        expect(rows).toEqual([
            'result-7-1',
            'promo-7-1',
            'result-7-2',
            'promo-7-2',
        ]);

        const first = screen.getByTestId('promo-7-1');
        expect(first).toHaveTextContent('Promo 1');
        expect(first).toHaveTextContent('Interview Space');
        expect(first).toHaveTextContent('Rob Van Dam');
        expect(within(first).getByText('Succeeded').className).toContain(
            'text-zone-safe',
        );
        expect(
            within(screen.getByTestId('promo-7-2')).getByText('Failed')
                .className,
        ).toContain('text-zone-serious');
    });

    describe('while the week airs live', () => {
        const live = () => ({
            ...props,
            airing: true,
            shows: [
                {
                    ...props.shows[0],
                    status: 'airing',
                    fatigue: [],
                    outcomes: [
                        outcome(1, ['Kane'], ['Carlito']),
                        pending(2, [['Edge'], ['John Cena']], {
                            is_next: true,
                            title: 'WWE Championship',
                            champions: ['Edge'],
                        }),
                        pending(3, [['Batista'], ['JBL']]),
                    ],
                },
            ],
        });

        it('opens the next match with Book to Win for each side', async () => {
            page.props = live();
            const user = userEvent.setup();
            render(<Results />);

            expect(
                within(screen.getByTestId('result-7-2')).getByText(
                    'Pending Result',
                ),
            ).toBeInTheDocument();
            expect(screen.getByTestId('result-7-2')).toHaveTextContent(
                'Edge(C)vsJohn Cena',
            );
            expect(screen.getByTestId('result-7-3')).toHaveTextContent(
                'Waiting',
            );

            // The next match opens by itself.
            const detail = screen.getByTestId('result-detail');
            expect(
                within(detail).getByText(/always ends with a decisive/),
            ).toBeInTheDocument();

            const cena = within(detail).getByTestId('book-side-1');
            await user.click(
                within(cena).getByRole('button', { name: 'Book to Win' }),
            );

            expect(post).toHaveBeenCalledWith(
                expect.stringContaining('/results/1/decide'),
                { slot_id: 102, winner_side: 1 },
                expect.anything(),
            );
        });

        it('simulates one match or all of them', async () => {
            page.props = live();
            const user = userEvent.setup();
            render(<Results />);

            await user.click(
                screen.getByRole('button', { name: 'Simulate match' }),
            );
            expect(post).toHaveBeenLastCalledWith(
                expect.stringContaining('/results/1/decide'),
                { slot_id: 102, winner_side: null },
                expect.anything(),
            );
            // Let the request finish before the next click.
            post.mock.calls[0][2].onFinish();

            await user.click(
                screen.getByRole('button', { name: 'Simulate all' }),
            );
            expect(post).toHaveBeenLastCalledWith(
                expect.stringContaining('/results/1/simulate-all'),
                {},
                expect.anything(),
            );
        });

        it('can’t decide a later match first', async () => {
            page.props = live();
            const user = userEvent.setup();
            render(<Results />);

            await user.click(
                screen.getByRole('button', {
                    name: 'Show details for Monday Night Raw match 3',
                }),
            );

            const detail = screen.getByTestId('result-detail');
            for (const button of within(detail).getAllByRole('button', {
                name: /Book to Win|Simulate match/,
            })) {
                expect(button).toBeDisabled();
            }
        });

        it('holds Continue and the fatigue card until every match is in', () => {
            page.props = live();
            render(<Results />);

            expect(
                screen.getByRole('button', { name: 'Continue' }),
            ).toBeDisabled();
            expect(screen.getByTestId('fatigue-pending')).toBeInTheDocument();
            expect(screen.queryByTestId(/^fatigue-\d+$/)).toBeNull();
        });
    });
});
