import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { NewsGrid } from '@/components/gm/news-grid';
import type { NewsCard, NewsPage } from '@/components/gm/news-grid';

function card(
    id: number,
    slot: NewsCard['slot'],
    text: string,
    tier: NewsCard['tier'] = 'story',
): NewsCard {
    return {
        id,
        type: 'title_change',
        tier,
        slot,
        priority: 10,
        text,
        summary: `Why: ${text}`,
        payload: { title: 'WWE Championship' },
        promoted: false,
    };
}

function page(week: number, overrides: Partial<NewsPage> = {}): NewsPage {
    return {
        week,
        season: 1,
        season_week: week,
        headlines: {
            raw: card(
                week * 100 + 1,
                'raw',
                `Raw headline ${week}`,
                'headline',
            ),
            smackdown: card(
                week * 100 + 2,
                'smackdown',
                `SmackDown headline ${week}`,
                'headline',
            ),
            neutral: card(
                week * 100 + 3,
                'neutral',
                `Neutral headline ${week}`,
                'headline',
            ),
        },
        stories: {
            raw: [
                card(week * 100 + 4, 'raw', `Raw story A ${week}`),
                card(week * 100 + 5, 'raw', `Raw story B ${week}`),
            ],
            smackdown: [
                card(week * 100 + 6, 'smackdown', `SD story A ${week}`),
            ],
        },
        ticker: [card(week * 100 + 7, 'raw', `Ticker item ${week}`, 'ticker')],
        tickers: {
            raw: ['Edge def. Kane · Normal Matches — Single'],
            smackdown: [],
        },
        attendance: [
            {
                name: 'Monday Night Raw',
                ppv: false,
                crowd: 15050,
                brands: ['raw'],
            },
            {
                name: 'Friday Night Smackdown',
                ppv: false,
                crowd: 9800,
                brands: ['smackdown'],
            },
        ],
        ...overrides,
    };
}

describe('NewsGrid', () => {
    it('lays out 3 headline cards, 4 story cards and a ticker', () => {
        render(<NewsGrid pages={[page(3)]} />);

        expect(
            within(
                screen.getByRole('group', { name: 'Headlines' }),
            ).getAllByTestId('headline-card'),
        ).toHaveLength(3);
        expect(
            within(
                screen.getByRole('group', { name: 'Stories' }),
            ).getAllByTestId('story-card'),
        ).toHaveLength(4);
        expect(screen.getByText('Raw headline 3')).toBeInTheDocument();
        expect(screen.getByText('Neutral headline 3')).toBeInTheDocument();
        // SmackDown has one story; its second card is an empty placeholder.
        expect(
            screen.getByText('Smackdown: no story this week'),
        ).toBeInTheDocument();
    });

    it('gives each brand a ticker of its match results only (house rule, 2026-11-17)', () => {
        render(<NewsGrid pages={[page(3)]} />);

        expect(
            within(screen.getByTestId('ticker-raw'))
                .getAllByTestId('ticker-item')
                .map((li) => li.textContent),
        ).toEqual(['◆Edge def. Kane · Normal Matches — Single']);
        expect(
            screen.getByRole('marquee', { name: 'Raw ticker' }),
        ).toBeInTheDocument();
        // No crowd line, no other news, and no WWE ticker.
        expect(screen.queryByText(/Fan attendance/)).toBeNull();
        expect(screen.queryByText('Ticker item 3')).toBeNull();
        expect(screen.queryByTestId('ticker-neutral')).toBeNull();
        // Smackdown had no matches to list, so it has no ticker this week.
        expect(screen.queryByTestId('ticker-smackdown')).toBeNull();
    });

    it('moves between weeks, newest first, with buttons and arrow keys', async () => {
        const user = userEvent.setup();
        render(<NewsGrid pages={[page(5), page(4), page(3)]} />);

        expect(screen.getByTestId('news-week')).toHaveTextContent('Week 5');
        // House rule (2026-11-20): a button with nowhere to go is hidden.
        expect(screen.queryByRole('button', { name: /Newer/ })).toBeNull();

        await user.click(screen.getByRole('button', { name: 'Older' }));
        expect(screen.getByTestId('news-week')).toHaveTextContent('Week 4');
        expect(screen.getByText('Raw headline 4')).toBeInTheDocument();
        expect(window.location.search).toBe('?week=4');

        fireEvent.keyDown(window, { key: 'ArrowRight' });
        expect(screen.getByTestId('news-week')).toHaveTextContent('Week 3');
        expect(screen.queryByRole('button', { name: /Older/ })).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Newer' }),
        ).toBeInTheDocument();

        fireEvent.keyDown(window, { key: 'ArrowLeft' });
        expect(screen.getByTestId('news-week')).toHaveTextContent('Week 4');
    });

    it('opens on the week in the URL', () => {
        window.history.replaceState(null, '', '/?week=3');
        render(<NewsGrid pages={[page(5), page(4), page(3)]} />);

        expect(screen.getByTestId('news-week')).toHaveTextContent('Week 3');
    });

    it('leaves arrow keys alone while the player types', () => {
        render(
            <>
                <input aria-label="Search" />
                <NewsGrid pages={[page(5), page(4)]} />
            </>,
        );

        fireEvent.keyDown(screen.getByLabelText('Search'), {
            key: 'ArrowRight',
        });
        expect(screen.getByTestId('news-week')).toHaveTextContent('Week 5');
    });

    it('hides the week buttons and keys during the end-of-week flow', () => {
        window.history.replaceState(null, '', '/?week=4');
        render(<NewsGrid pages={[page(5), page(4)]} wrapUp />);

        expect(screen.getByTestId('news-week')).toHaveTextContent('Week 5');
        expect(
            screen.queryByRole('button', { name: /Older|Newer/ }),
        ).toBeNull();

        fireEvent.keyDown(window, { key: 'ArrowRight' });
        expect(screen.getByTestId('news-week')).toHaveTextContent('Week 5');
    });

    it('pauses a ticker from a button', async () => {
        const user = userEvent.setup();
        render(<NewsGrid pages={[page(5)]} />);

        const pause = screen.getByRole('button', {
            name: 'Pause the Raw ticker',
        });
        await user.click(pause);

        expect(
            screen.getByRole('button', { name: 'Pause the Raw ticker' }),
        ).toHaveAttribute('aria-pressed', 'true');
    });

    it('swipes between weeks', () => {
        render(<NewsGrid pages={[page(5), page(4)]} />);
        const carousel = screen.getByRole('region', { name: 'News by week' });

        fireEvent.touchStart(carousel, { touches: [{ clientX: 300 }] });
        fireEvent.touchEnd(carousel, { changedTouches: [{ clientX: 100 }] });

        expect(screen.getByTestId('news-week')).toHaveTextContent('Week 4');
    });

    it('orders the headlines Raw, WWE, Smackdown', () => {
        render(<NewsGrid pages={[page(3)]} />);

        const headlines = within(
            screen.getByRole('group', { name: 'Headlines' }),
        ).getAllByTestId('headline-card');

        expect(headlines.map((h) => h.textContent)).toEqual([
            expect.stringContaining('Raw headline 3'),
            expect.stringContaining('Neutral headline 3'),
            expect.stringContaining('SmackDown headline 3'),
        ]);
    });

    it('shows a summary under each headline instead of the raw payload', () => {
        render(<NewsGrid pages={[page(3)]} />);

        const card = screen
            .getAllByTestId('headline-card')
            .find((c) => c.textContent?.includes('Raw headline 3'))!;

        expect(within(card).getByText('Why: Raw headline 3')).toBeVisible();
        expect(
            within(card).queryByText('WWE Championship'),
        ).not.toBeInTheDocument();
    });

    it('expands a story card to show its full summary', async () => {
        const user = userEvent.setup();
        render(<NewsGrid pages={[page(3)]} />);

        // House rule (2026-11-15): a story's summary starts hidden.
        const button = screen.getByRole('button', { name: /Raw story A 3/ });
        const panel = document.getElementById(
            button.getAttribute('aria-controls')!,
        )!;
        expect(button).toHaveAttribute('aria-expanded', 'false');
        expect(button).toHaveTextContent('Show story');
        expect(panel).toHaveAttribute('data-state', 'closed');
        expect(panel).toHaveAttribute('inert');

        await user.click(button);

        expect(button).toHaveAttribute('aria-expanded', 'true');
        expect(button).toHaveTextContent('Hide story');
        expect(panel).toHaveAttribute('data-state', 'open');
        expect(within(panel).getByTestId('news-summary')).toHaveTextContent(
            'Why: Raw story A 3',
        );
    });

    it('keeps the fallback cards on a quiet week', () => {
        render(
            <NewsGrid
                pages={[
                    page(2, {
                        headlines: {
                            raw: null,
                            smackdown: null,
                            neutral: null,
                        },
                        stories: { raw: [], smackdown: [] },
                        ticker: [],
                    }),
                ]}
            />,
        );

        expect(
            screen.getByText('Raw: no headline this week'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('WWE: no headline this week'),
        ).toBeInTheDocument();
        expect(screen.getAllByText('Raw: no story this week')).toHaveLength(2);
    });

    it('shows an empty state with no news', () => {
        render(<NewsGrid pages={[]} />);

        expect(screen.getByText(/No news yet/)).toBeInTheDocument();
    });
});
