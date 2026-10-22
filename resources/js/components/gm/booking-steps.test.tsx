import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { BookingAction, BookingSteps } from '@/components/gm/booking-steps';
import type { BookingOrder } from '@/components/gm/booking-steps';
import type { GameShell } from '@/types/gm';

const router = vi.hoisted(() => ({ post: vi.fn() }));

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

const order = (overrides: Partial<BookingOrder>): BookingOrder => ({
    steps: [
        {
            id: 9,
            name: 'Backlash',
            show_type: 'ppv',
            confirmed: false,
            current: true,
        },
        {
            id: 7,
            name: 'Monday Night Raw',
            show_type: 'weekly',
            confirmed: false,
            current: false,
        },
    ],
    blocked_by: null,
    next: { id: 7, name: 'Monday Night Raw' },
    confirmed: false,
    editable: true,
    is_last: false,
    airs_this_week: true,
    ...overrides,
});

beforeEach(() => router.post.mockClear());

describe('Booking order', () => {
    it('lists the week’s cards in order and explains a blocked card', () => {
        render(
            <BookingSteps
                game={game}
                order={order({
                    blocked_by: { id: 9, name: 'Backlash' },
                    steps: order({}).steps.map((s) => ({
                        ...s,
                        current: s.id === 7,
                    })),
                })}
            />,
        );

        expect(
            within(screen.getByRole('list', { name: 'This week’s cards' }))
                .getAllByRole('link')
                .map((l) => l.textContent),
        ).toEqual(['1Backlash', '2Monday Night Raw']);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Weeks start on Sunday, so Backlash is booked first.',
        );
    });

    it('confirms the PPV card before moving on to the weekly show', async () => {
        const user = userEvent.setup();
        render(
            <BookingAction
                game={game}
                showId={9}
                showName="Backlash"
                order={order({})}
            />,
        );

        await user.click(
            screen.getByRole('button', {
                name: 'Continue to Monday Night Raw',
            }),
        );
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent('Finish Backlash?');
        expect(router.post).not.toHaveBeenCalled();

        await user.click(
            within(dialog).getByRole('button', {
                name: 'Continue to Monday Night Raw',
            }),
        );

        expect(router.post).toHaveBeenCalledWith(
            expect.stringContaining('/shows/9/confirm'),
        );
    });

    it('airs the whole week from the last card, after a confirmation', async () => {
        const user = userEvent.setup();
        render(
            <BookingAction
                game={game}
                showId={7}
                showName="Monday Night Raw"
                order={order({ is_last: true, next: null })}
            />,
        );

        await user.click(screen.getByRole('button', { name: /Air week 4/ }));
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent(
            'Backlash and Monday Night Raw air now',
        );

        await user.click(
            within(dialog).getByRole('button', { name: 'Air week 4' }),
        );

        expect(router.post).toHaveBeenCalledWith(
            expect.stringContaining('/advance'),
            {},
            expect.anything(),
        );
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
