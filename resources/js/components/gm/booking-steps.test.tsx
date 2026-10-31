import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { BookingAction, BookingSteps } from '@/components/gm/booking-steps';
import type { BookingOrder } from '@/components/gm/booking-steps';
import {
    UnsavedChangesProvider,
    useReportUnsaved,
} from '@/components/gm/unsaved-changes';
import type { GameShell } from '@/types/gm';

const router = vi.hoisted(() => ({
    post: vi.fn(),
    on: vi.fn(() => () => {}),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router,
    usePage: () => ({ props: {} }),
}));

const game = {
    id: 1,
    stage: 'in_season',
    season_week: 4,
    deficit: { blocked: false },
} as unknown as GameShell;

/** Backlash, the PPV night's only card, by default. */
const order = (overrides: Partial<BookingOrder>): BookingOrder => ({
    steps: [
        {
            id: 9,
            name: 'Backlash',
            show_type: 'ppv',
            confirmed: false,
            current: true,
            aired: false,
        },
        {
            id: 7,
            name: 'Monday Night Raw',
            show_type: 'weekly',
            confirmed: false,
            current: false,
            aired: false,
        },
    ],
    blocked_by: null,
    waits_for_airing: false,
    next: null,
    confirmed: false,
    editable: true,
    is_last: true,
    airs_this_week: true,
    night: 'ppv',
    ...overrides,
});

/** The same week from the weekly show's side. */
const weekly = (overrides: Partial<BookingOrder> = {}): BookingOrder =>
    order({
        night: 'weekly',
        steps: order({}).steps.map((s) => ({ ...s, current: s.id === 7 })),
        ...overrides,
    });

beforeEach(() => router.post.mockClear());

describe('Booking order', () => {
    it('lists the week’s cards in order and has the weekly show wait for the PPV to air', () => {
        render(
            <BookingSteps
                game={game}
                order={weekly({
                    blocked_by: { id: 9, name: 'Backlash' },
                    waits_for_airing: true,
                })}
            />,
        );

        expect(
            within(screen.getByRole('list', { name: 'This week’s cards' }))
                .getAllByRole('link')
                .map((l) => l.textContent),
        ).toEqual(['1Backlash', '2Monday Night Raw']);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Weeks start on Sunday, so Backlash airs first. This show is booked once its results are in.',
        );
    });

    it('explains a card waiting for an earlier one of the same night', () => {
        render(
            <BookingSteps
                game={game}
                order={order({ blocked_by: { id: 9, name: 'Backlash' } })}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'Weeks start on Sunday, so Backlash is booked first.',
        );
    });

    it('marks a card that has aired', () => {
        render(
            <BookingSteps
                game={game}
                order={weekly({
                    steps: weekly().steps.map((s) => ({
                        ...s,
                        aired: s.id === 9,
                    })),
                })}
            />,
        );

        expect(
            within(screen.getByRole('list', { name: 'This week’s cards' }))
                .getAllByRole('link')
                .map((l) => l.textContent),
        ).toEqual(['1Backlashaired', '2Monday Night Raw']);
    });

    it('confirms a PPV card before moving on to the next card of the night', async () => {
        const user = userEvent.setup();
        const next = 'Survivor Series: Raw vs. Smackdown';
        render(
            <BookingAction
                game={game}
                showId={9}
                showName="Survivor Series"
                order={order({ is_last: false, next: { id: 8, name: next } })}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: `Continue to ${next}` }),
        );
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent('Finish Survivor Series?');
        expect(dialog).toHaveTextContent('the PPV night');
        expect(router.post).not.toHaveBeenCalled();

        await user.click(
            within(dialog).getByRole('button', { name: `Continue to ${next}` }),
        );

        expect(router.post).toHaveBeenCalledWith(
            expect.stringContaining('/shows/9/confirm'),
        );
    });

    it('airs the PPV night on its own from its last card', async () => {
        const user = userEvent.setup();
        render(
            <BookingAction
                game={game}
                showId={9}
                showName="Backlash"
                order={order({})}
            />,
        );

        await user.click(screen.getByRole('button', { name: /Air Backlash/ }));
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent('Backlash airs now');
        expect(dialog).toHaveTextContent(
            'The weekly show is booked once the PPV’s results are in.',
        );

        await user.click(
            within(dialog).getByRole('button', { name: 'Air Backlash' }),
        );

        expect(router.post).toHaveBeenCalledWith(
            expect.stringContaining('/advance'),
            {},
            expect.anything(),
        );
    });

    it('airs the rest of the week from the weekly show, after a confirmation', async () => {
        const user = userEvent.setup();
        render(
            <BookingAction
                game={game}
                showId={7}
                showName="Monday Night Raw"
                order={weekly()}
            />,
        );

        await user.click(screen.getByRole('button', { name: /Air week 4/ }));
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent('Monday Night Raw airs now');
        expect(dialog).not.toHaveTextContent('PPV’s results');

        await user.click(
            within(dialog).getByRole('button', { name: 'Air week 4' }),
        );

        expect(router.post).toHaveBeenCalledWith(
            expect.stringContaining('/advance'),
            {},
            expect.anything(),
        );
    });

    it('won’t air the week while slots have unsaved changes, and lists them', async () => {
        const user = userEvent.setup();

        function Dirty({ slot }: { slot: string }) {
            useReportUnsaved(slot, true);

            return null;
        }

        render(
            <UnsavedChangesProvider>
                <Dirty slot="Promo 1" />
                <Dirty slot="Match 2" />
                <BookingAction
                    game={game}
                    showId={7}
                    showName="Monday Night Raw"
                    order={weekly()}
                />
            </UnsavedChangesProvider>,
        );

        await user.click(screen.getByRole('button', { name: /Air week 4/ }));
        const dialog = screen.getByRole('dialog');

        expect(dialog).toHaveTextContent(
            'Match 2 and Promo 1 have unsaved changes',
        );
        expect(
            within(within(dialog).getByRole('list', { name: 'Unsaved slots' }))
                .getAllByRole('listitem')
                .map((li) => li.textContent),
        ).toEqual(['Match 2', 'Promo 1']);
        expect(
            within(dialog).queryByRole('button', { name: 'Air week 4' }),
        ).not.toBeInTheDocument();

        await user.click(
            within(dialog).getByRole('button', { name: 'Back to the card' }),
        );

        expect(router.post).not.toHaveBeenCalled();
    });

    it('offers to unlock a confirmed card', async () => {
        const user = userEvent.setup();
        render(
            <BookingAction
                game={game}
                showId={9}
                showName="Backlash"
                order={order({ confirmed: true, editable: false })}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Unlock card' }));

        expect(router.post).toHaveBeenCalledWith(
            expect.stringContaining('/shows/9/unlock'),
        );
    });
});
