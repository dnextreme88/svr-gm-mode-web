import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { REEL_FLASH_MS, REEL_SPIN_MS } from '@/components/gm/first-pick-reel';
import { LOADING_OVERLAY_DELAY_MS } from '@/components/gm/loading-overlay';
import Saves from '@/pages/gm/saves/index';

const router = vi.hoisted(() => ({ visit: vi.fn(), post: vi.fn() }));
const requestJson = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router,
    usePage: () => ({ props: {} }),
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setAll] = useState(initial);

        return {
            data,
            errors: {},
            setData: (key: keyof T, value: unknown) =>
                setAll((d) => ({ ...d, [key]: value })),
            setError: vi.fn(),
            clearErrors: vi.fn(),
        };
    },
}));

vi.mock('@/lib/http', () => ({
    HttpError: class extends Error {},
    requestJson,
}));

describe('new game rosters', () => {
    beforeEach(() => {
        router.visit.mockReset();
        requestJson.mockReset();
        window.localStorage.clear();
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });
    afterEach(() => vi.useRealTimers());

    const renderPage = () =>
        render(<Saves saves={[]} unsaved={null} starting_budget={15000000} />);

    it('defaults to the manual draft', () => {
        renderPage();

        expect(
            screen.getByRole('radio', { name: /manual draft pick/i }),
        ).toBeChecked();
        expect(
            screen.getByRole('button', { name: 'Start the draft' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Draft Rules:')).toBeInTheDocument();
    });

    it('signs the WWE rosters and goes straight to the next screen', async () => {
        requestJson.mockResolvedValue({
            next_url: '/gm/saves/1/titles/assign',
            first_brand: 'raw',
        });
        renderPage();

        await userEvent.click(
            screen.getByRole('radio', { name: /use wwe roster/i }),
        );

        expect(screen.getByText('Roster Rules:')).toBeInTheDocument();

        await userEvent.click(
            screen.getByRole('button', { name: 'Play with WWE Roster' }),
        );

        expect(requestJson).toHaveBeenCalledWith(
            'POST',
            expect.any(String),
            expect.objectContaining({ draft_mode: 'wwe_roster' }),
        );
        // The loading overlay covers the page for its pause first.
        expect(screen.getByTestId('starting-game')).toHaveTextContent(
            'Signing the WWE rosters. Please wait…',
        );
        expect(router.visit).not.toHaveBeenCalled();
        await act(async () => {
            await vi.advanceTimersByTimeAsync(LOADING_OVERLAY_DELAY_MS);
        });
        expect(router.visit).toHaveBeenCalledWith('/gm/saves/1/titles/assign');
        expect(screen.queryByText(/who picks first/i)).not.toBeInTheDocument();
    });

    it('plays the draft order reel, then covers the page with the loading overlay', async () => {
        requestJson.mockResolvedValue({
            next_url: '/gm/saves/1/draft',
            first_brand: 'raw',
        });
        renderPage();

        await userEvent.click(
            screen.getByRole('button', { name: 'Start the draft' }),
        );

        // The reel comes first.
        expect(screen.getByText(/who picks first/i)).toBeInTheDocument();
        expect(screen.queryByTestId('starting-game')).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Start the draft' }),
        ).toBeDisabled();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(
                REEL_SPIN_MS + REEL_FLASH_MS + 1000,
            );
        });

        // Once it lands, the overlay, then the draft room.
        expect(screen.getByTestId('starting-game')).toHaveTextContent(
            'Setting up the draft. Please wait…',
        );
        expect(screen.queryByText(/who picks first/i)).not.toBeInTheDocument();
        expect(router.visit).not.toHaveBeenCalled();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(LOADING_OVERLAY_DELAY_MS);
        });

        expect(router.visit).toHaveBeenCalledWith('/gm/saves/1/draft');
    });
});

describe('loading a saved career (house rule, 2026-11-17)', () => {
    const slot = {
        id: 7,
        name: 'My Career',
        player_brand: 'raw' as const,
        stage: 'in_season' as const,
        season: 1,
        season_week: 12,
        month: 'June',
        budget: 9_000_000,
        updated_at: null,
        has_changes: false,
    };

    beforeEach(() => router.post.mockReset());

    it('loads straight away when nothing changed since the save', async () => {
        const user = userEvent.setup();
        render(<Saves saves={[slot]} unsaved={null} starting_budget={1} />);

        expect(screen.getByText(/Season 1, week 12/)).toBeInTheDocument();
        await user.click(screen.getByTestId('load-career-7'));

        expect(router.post).toHaveBeenCalledWith(
            '/gm/saves/7/load',
            {},
            expect.any(Object),
        );
    });

    it('asks first when progress since the save would be lost', async () => {
        const user = userEvent.setup();
        render(
            <Saves
                saves={[{ ...slot, has_changes: true }]}
                unsaved={null}
                starting_budget={1}
            />,
        );

        expect(screen.getByTestId('unsaved-progress-7')).toBeInTheDocument();
        await user.click(screen.getByTestId('load-career-7'));

        expect(router.post).not.toHaveBeenCalled();
        expect(screen.getByRole('dialog')).toHaveTextContent(
            'Season 1, week 12',
        );

        await user.click(screen.getByRole('button', { name: 'Load save' }));
        expect(router.post).toHaveBeenCalledWith(
            '/gm/saves/7/load',
            {},
            expect.any(Object),
        );
    });

    it('covers the page while the save loads', async () => {
        const user = userEvent.setup();
        router.post.mockImplementation(
            (_u: string, _d: unknown, o?: { onStart?: () => void }) =>
                o?.onStart?.(),
        );
        render(<Saves saves={[slot]} unsaved={null} starting_budget={1} />);

        await user.click(screen.getByTestId('load-career-7'));

        expect(screen.getByTestId('loading-career')).toHaveTextContent(
            'Loading your career. Please wait…',
        );
    });
});
