import { act, fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ChampionshipEligibility } from '@/types/enums';
import { LOADING_OVERLAY_DELAY_MS } from '@/components/gm/loading-overlay';
import AssignTitles, { championsProgress } from '@/pages/gm/titles/assign';

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
    eligibility: (key === 'womens'
        ? 'divas_only'
        : 'open') as ChampionshipEligibility,
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

    it('covers the page while the season starts, for a moment first', () => {
        vi.useFakeTimers();
        page.props = decidedProps;
        render(<AssignTitles />);

        expect(screen.queryByTestId('starting-season')).toBeNull();
        fireEvent.click(
            screen.getByRole('button', { name: 'Start the season' }),
        );

        // The overlay shows at once and the button is disabled.
        expect(screen.getByTestId('starting-season')).toHaveTextContent(
            'Starting the season. Please wait...',
        );
        expect(screen.getByRole('status')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Start the season' }),
        ).toBeDisabled();

        // The season starts once the delay is up.
        expect(post).not.toHaveBeenCalled();
        act(() => {
            vi.advanceTimersByTime(LOADING_OVERLAY_DELAY_MS);
        });
        expect(post).toHaveBeenCalledWith(
            '/gm/saves/3/titles/assign-complete',
            {},
            expect.objectContaining({ onFinish: expect.any(Function) }),
        );
        vi.useRealTimers();

        // It failed (no redirect): the page is usable again.
        act(() => {
            post.mock.calls[0][2].onFinish();
        });
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
        // Change lives on the review only, not on the step tiles.
        expect(
            within(screen.getByTestId('step-intercontinental')).queryByRole(
                'button',
            ),
        ).toBeNull();
        expect(screen.getByTestId('step-wwe')).toHaveAttribute(
            'aria-current',
            'step',
        );
    });

    it('counts champions assigned while titles are decided, from 0/5', () => {
        const fresh = decidedProps.championships.map((c) => ({
            ...c,
            holder: null,
            decided: false,
        }));
        page.props = { ...decidedProps, current_id: 1, championships: fresh };
        const { unmount } = render(<AssignTitles />);

        const bar = screen.getByRole('progressbar', {
            name: 'Champions assigned',
        });
        expect(bar).toHaveAttribute('aria-valuenow', '0');
        expect(bar).toHaveAttribute('aria-valuemax', '5');
        expect(screen.getByTestId('champions-progress')).toHaveTextContent(
            '0/5 champions assigned',
        );
        unmount();

        // The tag champions count as two.
        page.props = {
            ...decidedProps,
            current_id: 4,
            championships: decidedProps.championships.map((c) =>
                c.id === 4 ? { ...c, holder: null, decided: false } : c,
            ),
        };
        render(<AssignTitles />);
        // The WWE title was left vacant: it stays in the total, unfilled.
        expect(screen.getByTestId('champions-progress')).toHaveTextContent(
            '3/5 champions assigned',
        );
    });

    it('keeps the progress bar on the review', () => {
        page.props = decidedProps;
        render(<AssignTitles />);

        // The WWE title is vacant: 4 of 5.
        expect(screen.getByTestId('champions-progress')).toHaveTextContent(
            '4/5 champions assigned',
        );
        expect(championsProgress(decidedProps.championships)).toEqual({
            assigned: 4,
            total: 5,
        });
    });
});
