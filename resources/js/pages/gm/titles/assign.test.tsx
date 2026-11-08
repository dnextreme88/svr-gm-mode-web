import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AssignTitles from '@/pages/gm/titles/assign';

const post = vi.fn();

const title = (
    id: number,
    key: string,
    name: string,
    holder: string | null,
    decided: boolean,
) => ({
    id,
    key,
    name,
    is_tag: key === 'world_tag_team',
    eligibility: key === 'womens' ? 'divas_only' : 'open',
    holder,
    decided,
    eligible: [],
});

const page: { props: Record<string, unknown> } = { props: {} };

const decidedProps = {
    game: { id: 3, player_brand: 'raw' },
    initial: true,
    current_id: null,
    championships: [
        title(1, 'wwe', 'WWE Championship', null, true),
        title(
            2,
            'intercontinental',
            'Intercontinental Championship',
            'Edge',
            true,
        ),
        title(
            3,
            'world_tag_team',
            'World Tag Team Championship',
            'Kane & John Cena',
            true,
        ),
        title(4, 'womens', "Women's Championship", 'Trish Stratus', true),
    ],
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: (...args: unknown[]) => post(...args) },
    usePage: () => ({ props: page.props }),
}));

afterEach(() => post.mockReset());

describe('Crown your champions (house rule, 2026-11-07)', () => {
    it('reviews every decided title, with Change, and offers Start the season', () => {
        page.props = decidedProps;
        render(<AssignTitles />);

        const review = screen.getByRole('list', { name: 'Your champions' });
        expect(within(review).getAllByRole('listitem')).toHaveLength(4);
        expect(screen.getByTestId('review-wwe')).toHaveTextContent('Vacant');
        expect(screen.getByTestId('review-intercontinental')).toHaveTextContent(
            'Edge',
        );
        expect(
            screen.getByRole('button', { name: 'Start the season' }),
        ).toBeInTheDocument();
    });

    it('opens a title again with Change', async () => {
        const user = userEvent.setup();
        page.props = decidedProps;
        render(<AssignTitles />);

        await user.click(
            within(screen.getByTestId('review-wwe')).getByRole('button', {
                name: 'Change WWE Championship',
            }),
        );

        expect(post).toHaveBeenCalledWith(
            '/gm/saves/3/titles/assign/1/undo',
            {},
            { preserveScroll: true },
        );
    });

    it('covers the page while the season starts', async () => {
        const user = userEvent.setup();
        page.props = decidedProps;
        render(<AssignTitles />);

        expect(screen.queryByTestId('starting-season')).toBeNull();
        await user.click(
            screen.getByRole('button', { name: 'Start the season' }),
        );

        expect(post).toHaveBeenCalledWith(
            '/gm/saves/3/titles/assign-complete',
            {},
            expect.objectContaining({ onStart: expect.any(Function) }),
        );

        // The visit starts: the overlay shows and the button is disabled.
        act(() => post.mock.calls[0][2].onStart());
        expect(screen.getByTestId('starting-season')).toHaveTextContent(
            'Starting the season. Please wait...',
        );
        expect(screen.getByRole('status')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Start the season' }),
        ).toBeDisabled();

        // It failed (no redirect): the page is usable again.
        act(() => post.mock.calls[0][2].onFinish());
        expect(screen.queryByTestId('starting-season')).toBeNull();
    });

    it('hides Start the season while a title is being decided again', () => {
        page.props = {
            ...decidedProps,
            current_id: 1,
            championships: decidedProps.championships.map((c) =>
                c.id === 1 ? { ...c, decided: false } : c,
            ),
        };
        render(<AssignTitles />);

        expect(
            screen.queryByRole('button', { name: 'Start the season' }),
        ).toBeNull();
        expect(
            screen.queryByRole('list', { name: 'Your champions' }),
        ).toBeNull();
        // The other decided titles can still be changed from their step.
        expect(
            within(screen.getByTestId('step-intercontinental')).getByRole(
                'button',
                { name: 'Change Intercontinental Championship' },
            ),
        ).toBeInTheDocument();
        expect(screen.getByTestId('step-wwe')).toHaveAttribute(
            'aria-current',
            'step',
        );
    });
});
