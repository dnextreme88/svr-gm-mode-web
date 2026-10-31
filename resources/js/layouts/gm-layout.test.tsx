import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import GmLayout from '@/layouts/gm-layout';

const game = {
    id: 1,
    name: 'Test',
    saved: true,
    player_brand: 'raw',
    rival_brand: 'smackdown',
    stage: 'in_season',
    season: 1,
    week: 2,
    season_week: 2,
    airing: false,
    current_card_url: '/gm/saves/1/shows/3',
    month: 'April',
    date: 'Mon Apr 11',
    budget: 11_560_000,
    rival_budget: 10_000_000,
    roster_count: 30,
    rival_roster_count: 30,
    roster_cap: 30,
    unread_emails: 2,
    next_ppv: { name: 'Backlash', week: 4, weeks_away: 2 },
    fatigue_config: { bar_size: 10, safe_max: 70, serious_at: 100, max: 100 },
    deficit: { weeks: 0, limit: 8, choice: null, blocked: false },
    faction_prompt: null,
};

vi.mock('@inertiajs/react', () => ({
    router: { on: () => () => {}, post: vi.fn() },
    usePage: () => ({ props: { game }, url: '/gm/saves/1/factions' }),
    Link: ({
        children,
        href,
        ...rest
    }: {
        children: ReactNode;
        href: string;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

/**
 * jsdom has no layout: fake how wide the menu links are and how much room
 * the bar has, and fire the ResizeObserver callback by hand.
 */
let needed = 0;
let room = 0;
const observers: Array<() => void> = [];

beforeEach(() => {
    observers.length = 0;
    vi.stubGlobal(
        'ResizeObserver',
        class {
            constructor(private cb: () => void) {
                observers.push(cb);
            }

            observe() {}

            disconnect() {}
        },
    );
    vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockImplementation(
        () => needed,
    );
    vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockImplementation(
        () => room,
    );
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

function renderAt(linksWidth: number, barWidth: number) {
    needed = linksWidth;
    room = barWidth;

    return render(
        <GmLayout>
            <p>Page</p>
        </GmLayout>,
    );
}

describe('GM menu bar', () => {
    it('shows every link when they fit', () => {
        renderAt(1100, 1400);
        const nav = screen.getByRole('navigation', { name: 'GM menus' });

        expect(
            within(nav).getByRole('link', { name: 'Schedule' }),
        ).toBeInTheDocument();
        expect(
            within(nav).queryByRole('button', { name: 'Menu' }),
        ).not.toBeInTheDocument();
    });

    it('folds into a Menu button naming the current page when they don’t fit', async () => {
        const user = userEvent.setup();
        renderAt(1230, 900);
        const nav = screen.getByRole('navigation', { name: 'GM menus' });
        const button = within(nav).getByRole('button', { name: 'Menu' });

        expect(button).toHaveTextContent('Menu· Factions2');
        expect(within(nav).queryAllByRole('link')).toHaveLength(0);

        await user.click(button);

        const menu = screen.getByRole('menu', { name: 'GM menus' });
        const items = within(menu).getAllByRole('menuitem');
        expect(items.map((i) => i.textContent)).toContain('Free Agency');
        expect(
            within(menu).getByRole('menuitem', { name: 'Factions' }).className,
        ).toContain('border-brand');
        expect(
            within(menu).getByRole('menuitem', { name: /Email/ }),
        ).toHaveAttribute('href', expect.stringContaining('/email'));
    });

    it('switches back when the bar grows', () => {
        renderAt(1230, 900);
        expect(
            screen.getByRole('button', { name: 'Menu' }),
        ).toBeInTheDocument();

        room = 1400;
        act(() => observers.forEach((cb) => cb()));

        expect(
            screen.queryByRole('button', { name: 'Menu' }),
        ).not.toBeInTheDocument();
    });
});

describe('GM menu bar re-checks', () => {
    it('unfolds on a window resize even without the observer', () => {
        renderAt(1230, 900);
        expect(
            screen.getByRole('button', { name: 'Menu' }),
        ).toBeInTheDocument();

        room = 1470;
        act(() => {
            window.dispatchEvent(new Event('resize'));
        });

        expect(
            screen.queryByRole('button', { name: 'Menu' }),
        ).not.toBeInTheDocument();
    });

    it('measures again once the webfont has loaded', async () => {
        let loaded: () => void = () => {};
        const ready = new Promise<void>((resolve) => {
            loaded = resolve;
        });
        Object.defineProperty(document, 'fonts', {
            configurable: true,
            value: { ready },
        });

        // The fallback font is wider: the links don't fit at first.
        renderAt(1500, 1400);
        expect(
            screen.getByRole('button', { name: 'Menu' }),
        ).toBeInTheDocument();

        needed = 1184;
        await act(async () => {
            loaded();
            await ready;
        });

        expect(
            screen.queryByRole('button', { name: 'Menu' }),
        ).not.toBeInTheDocument();
    });
});

describe('GM header', () => {
    it('is a size container for its responsive layout', () => {
        renderAt(1100, 1400);

        expect(screen.getByTestId('gm-header').className).toContain(
            '@container',
        );
        expect(screen.getByTestId('gm-header-stats').className).toContain(
            '@6xl:ml-auto',
        );
    });
});

describe('GM footer', () => {
    it('leaves a tall, quiet runway under the page with a sign-off', () => {
        renderAt(500, 800);

        const footer = screen.getByTestId('gm-footer');

        // Tall enough for a long dropdown near the bottom to land on.
        expect(footer.className).toContain('min-h-56');
        expect(footer).toHaveTextContent(
            'Smackdown vs Raw 2006 - a fan-made GM mode web version',
        );
        expect(within(footer).queryByRole('link')).toBeNull();
    });
});
