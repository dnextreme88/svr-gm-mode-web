import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Results from '@/pages/gm/results';
import type { MatchOutcome } from '@/types/gm';

function outcome(
    position: number,
    winners: string[],
    losers: string[],
    overrides: Partial<MatchOutcome> = {},
): MatchOutcome {
    return {
        position,
        match_type: 'Normal Matches — No Manager',
        winners,
        losers,
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

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
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
    usePage: () => ({ props }),
}));

afterEach(() => {
    vi.useRealTimers();
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
});
