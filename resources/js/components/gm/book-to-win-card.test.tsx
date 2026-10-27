import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { BookToWinCard } from '@/components/gm/book-to-win-card';
import { OutcomeCard } from '@/components/gm/outcome-card';
import type { MatchOutcome, PendingMatch } from '@/types/gm';

const entrants = [
    'Jeff Hardy',
    'Rob Van Dam',
    'Edge',
    'Matt Hardy',
    'Triple H',
    'Undertaker',
];

function match(overrides: Partial<PendingMatch> = {}): PendingMatch {
    return {
        id: 1,
        pending: true,
        is_next: true,
        position: 1,
        match_type: 'Gauntlet — 6-Man Gauntlet',
        win_condition: null,
        sides: entrants.map((name, side) => ({
            side,
            members: [
                {
                    id: side + 1,
                    name,
                    roster_type: 'superstar',
                    weight_class: 'heavyweight',
                },
            ],
        })),
        managers: [],
        referee: null,
        title: null,
        stipulation: null,
        champions: [],
        captains: [],
        gauntlet: true,
        ...overrides,
    };
}

function renderCard(m: PendingMatch, onDecide = vi.fn()) {
    render(
        <BookToWinCard
            match={m}
            showName="Monday Night Raw"
            isMainEvent={false}
            busy={false}
            onDecide={onDecide}
        />,
    );

    return onDecide;
}

const book = (user: ReturnType<typeof userEvent.setup>, side: number) =>
    user.click(
        within(screen.getByTestId(`book-side-${side}`)).getByRole('button', {
            name: 'Book to Win',
        }),
    );

describe('Gauntlet Book to Win', () => {
    it('books each bout in order, the winner meeting the next entrant', async () => {
        const user = userEvent.setup();
        const onDecide = renderCard(match());

        expect(screen.getByTestId('gauntlet-bout')).toHaveTextContent(
            'Bout 1 of 5',
        );
        expect(screen.getByTestId('book-side-0')).toHaveTextContent(
            'Jeff Hardy',
        );
        expect(screen.getByTestId('book-side-1')).toHaveTextContent(
            'Rob Van Dam',
        );

        await book(user, 1);
        await book(user, 1);
        await book(user, 3);

        expect(screen.getByTestId('gauntlet-bout')).toHaveTextContent(
            'Bout 4 of 5',
        );
        expect(screen.getByTestId('book-side-3')).toBeInTheDocument();
        expect(screen.getByTestId('book-side-4')).toHaveTextContent('Triple H');
        expect(screen.getByTestId('gauntlet-picks')).toHaveTextContent(
            'Bout 3Matt Hardy def. Rob Van Dam',
        );

        await book(user, 3);
        await book(user, 3);

        expect(onDecide).toHaveBeenCalledWith(null, [1, 1, 3, 3, 3]);
    });

    it('undoes the last pick with Back', async () => {
        const user = userEvent.setup();
        renderCard(match());

        await book(user, 0);
        await user.click(screen.getByRole('button', { name: 'Back' }));

        expect(screen.getByTestId('gauntlet-bout')).toHaveTextContent(
            'Bout 1 of 5',
        );
        expect(screen.queryByTestId('gauntlet-picks')).toBeNull();
    });

    it('simulates the rest after the picks so far', async () => {
        const user = userEvent.setup();
        const onDecide = renderCard(match());

        await book(user, 0);
        await user.click(
            screen.getByRole('button', { name: 'Simulate the rest' }),
        );

        expect(onDecide).toHaveBeenCalledWith(null, [0]);
    });

    it('tags Captain Fall captains on a normal match', () => {
        renderCard(
            match({
                gauntlet: false,
                sides: [
                    {
                        side: 0,
                        members: [
                            {
                                id: 1,
                                name: 'Kane',
                                roster_type: 'superstar',
                                weight_class: 'heavyweight',
                            },
                        ],
                    },
                    {
                        side: 1,
                        members: [
                            {
                                id: 2,
                                name: 'Edge',
                                roster_type: 'superstar',
                                weight_class: 'heavyweight',
                            },
                        ],
                    },
                ],
                captains: ['Kane', 'Edge'],
            }),
        );

        expect(screen.getByTestId('book-side-0')).toHaveTextContent(
            'Kane(Cpt)',
        );
    });
});

describe('Gauntlet outcome', () => {
    it('lists every bout under the summary', () => {
        const outcome = {
            id: 1,
            position: 1,
            match_type: 'Gauntlet — 6-Man Gauntlet',
            winners: ['Matt Hardy'],
            winners_label: 'Matt Hardy',
            losers: entrants.filter((n) => n !== 'Matt Hardy'),
            loser_sides: entrants
                .filter((n) => n !== 'Matt Hardy')
                .map((n) => [n]),
            booked: false,
            champions: [],
            captains: [],
            managers: [],
            method: 'pinfall',
            method_label: 'Pinfall',
            finish_event: null,
            finish_label: null,
            summary:
                'Matt Hardy wins the Gauntlet, beating Rob Van Dam, Triple H and Undertaker.',
            title: null,
            title_banner: null,
            stipulation: null,
            stipulation_outcome: null,
            bouts: [
                {
                    round: 1,
                    winner: 'Rob Van Dam',
                    loser: 'Jeff Hardy',
                    method_label: 'Pinfall',
                    booked: true,
                    summary:
                        'Rob Van Dam pins Jeff Hardy clean in the middle of the ring.',
                },
            ],
            rating: null,
            fan_delta: null,
        } as MatchOutcome;

        render(
            <OutcomeCard
                outcome={outcome}
                showName="Monday Night Raw"
                isMainEvent={false}
            />,
        );

        const bouts = screen.getByTestId('gauntlet-bouts');
        expect(bouts).toHaveTextContent('Bout 1Rob Van Damdef.Jeff Hardy');
        expect(bouts).toHaveTextContent('PinfallBooked');
    });
});
