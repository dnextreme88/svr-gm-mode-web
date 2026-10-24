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
        fan_support: null,
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

    it('shows ticker-tier events and the fan support trend in the ticker', () => {
        render(<NewsGrid pages={[page(3)]} />);

        const items = screen
            .getAllByTestId('ticker-item')
            .map((li) => li.textContent);

        expect(items).toEqual(['◆Ticker item 3', '◆Fan support trend: —']);
    });

    it('moves between weeks, newest first, with buttons and arrow keys', async () => {
        const user = userEvent.setup();
        render(<NewsGrid pages={[page(5), page(4), page(3)]} />);

        expect(screen.getByTestId('news-week')).toHaveTextContent('Week 5');
        expect(screen.getByRole('button', { name: 'Newer ▶' })).toBeDisabled();

        await user.click(screen.getByRole('button', { name: '◀ Older' }));
        expect(screen.getByTestId('news-week')).toHaveTextContent('Week 4');
        expect(screen.getByText('Raw headline 4')).toBeInTheDocument();

        fireEvent.keyDown(window, { key: 'ArrowRight' });
        expect(screen.getByTestId('news-week')).toHaveTextContent('Week 3');
        expect(screen.getByRole('button', { name: '◀ Older' })).toBeDisabled();

        fireEvent.keyDown(window, { key: 'ArrowLeft' });
        expect(screen.getByTestId('news-week')).toHaveTextContent('Week 4');
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

        const button = screen.getByRole('button', { name: /Raw headline 3/ });

        expect(
            within(button).getByText('Why: Raw headline 3'),
        ).toBeInTheDocument();
        expect(
            within(button).queryByText('WWE Championship'),
        ).not.toBeInTheDocument();
    });

    it('expands a story card to show its full summary', async () => {
        const user = userEvent.setup();
        render(<NewsGrid pages={[page(3)]} />);

        const button = screen.getByRole('button', { name: /Raw story A 3/ });
        const summary = within(button).getByTestId('news-summary');
        expect(button).toHaveAttribute('aria-expanded', 'false');
        expect(summary).toHaveClass('line-clamp-2');

        await user.click(button);

        expect(button).toHaveAttribute('aria-expanded', 'true');
        expect(summary).not.toHaveClass('line-clamp-2');
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
