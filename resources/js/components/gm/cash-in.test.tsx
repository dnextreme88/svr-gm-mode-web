import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { BookToWinCard } from '@/components/gm/book-to-win-card';
import { CashInButton } from '@/components/gm/cash-in-button';
import type { CardSlot, PendingMatch } from '@/types/gm';

const router = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router,
    usePage: () => ({ props: {} }),
}));

beforeEach(() => router.post.mockClear());

const member = (id: number, name: string) => ({
    id,
    name,
    roster_type: 'superstar' as const,
    weight_class: 'heavyweight' as const,
});

const titleMatch: PendingMatch = {
    id: 7,
    pending: true,
    is_next: true,
    position: 1,
    match_type: 'Hardcore — Single',
    win_condition: null,
    sides: [
        { side: 0, members: [member(1, 'Daivari')] },
        { side: 1, members: [member(2, 'Chris Benoit')] },
    ],
    managers: [],
    referee: null,
    title: 'World Heavyweight Championship',
    stipulation: null,
    champions: ['Daivari'],
    captains: [],
    cash_in_holder: 'Batista',
};

describe('Money in the Bank during a match (house rule)', () => {
    it('offers the holder a cash-in on the world title match up next', async () => {
        const user = userEvent.setup();
        const onCashIn = vi.fn();
        render(
            <BookToWinCard
                match={titleMatch}
                showName="Smackdown"
                isMainEvent={false}
                busy={false}
                onDecide={vi.fn()}
                onCashIn={onCashIn}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Cash in: Batista' }),
        );
        await user.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Cash in',
            }),
        );

        expect(onCashIn).toHaveBeenCalled();
    });

    it('has no cash-in button without a holder to cash in', () => {
        render(
            <BookToWinCard
                match={{ ...titleMatch, cash_in_holder: null }}
                showName="Smackdown"
                isMainEvent={false}
                busy={false}
                onDecide={vi.fn()}
                onCashIn={vi.fn()}
            />,
        );

        expect(screen.queryByRole('button', { name: /Cash in/ })).toBeNull();
    });

    it('shows a cash-in on the match card', () => {
        render(
            <BookToWinCard
                match={{ ...titleMatch, mitb_cash_in: 'during' }}
                showName="Smackdown"
                isMainEvent={false}
                busy={false}
                onDecide={vi.fn()}
            />,
        );

        expect(screen.getByTestId('cash-in-tag')).toHaveTextContent(
            'Money in the Bank cash-in',
        );
    });
});

describe('Money in the Bank before the show (house rule)', () => {
    const slot = (position: number): CardSlot => ({
        id: position,
        position,
        match_type_id: 1,
        sides: [],
        managers: {},
        title_championship_id: null,
        stipulation: null,
        stipulation_championship_id: null,
        cost: 0,
        fixed_kind: null,
        owner_brand: 'smackdown',
    });

    it('lets you place the cash-in before any match or after the Main Event', async () => {
        const user = userEvent.setup();
        render(
            <CashInButton
                briefcase={{
                    id: 1,
                    holder: 'Batista',
                    expires_week: 52,
                    cashed_in_here: false,
                }}
                slots={[1, 2, 3, 4, 5, 6].map(slot)}
                url="/gm/saves/1/shows/2/cash-in"
            />,
        );

        await user.click(
            screen.getByRole('button', { name: /Cash in: Batista/ }),
        );
        const dialog = screen.getByRole('dialog');
        const labels = within(dialog)
            .getAllByRole('radio')
            .map((r) => r.closest('label')?.textContent);

        expect(labels).toEqual([
            'Before Match 1',
            'Before Match 2',
            'Before Match 3',
            'Before Match 4',
            'Before Match 5',
            'Before the Main Event',
            'After the Main Event',
        ]);

        await user.click(within(dialog).getByLabelText('Before Match 3'));
        await user.click(
            within(dialog).getByRole('button', { name: 'Cash in' }),
        );

        expect(router.post).toHaveBeenCalledWith(
            '/gm/saves/1/shows/2/cash-in',
            { position: 3 },
            expect.anything(),
        );
    });
});
