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
            screen.getByText('SmackDown!: no story this week'),
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
        expect(screen.getByRole('button', { name: '◀ Newer' })).toBeDisabled();

        await user.click(screen.getByRole('button', { name: 'Older ▶' }));
        expect(screen.getByTestId('news-week')).toHaveTextContent('Week 4');
        expect(screen.getByText('Raw headline 4')).toBeInTheDocument();

        fireEvent.keyDown(window, { key: 'ArrowRight' });
        expect(screen.getByTestId('news-week')).toHaveTextContent('Week 3');
        expect(screen.getByRole('button', { name: 'Older ▶' })).toBeDisabled();

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

    it('expands a card to show the full details', async () => {
        const user = userEvent.setup();
        render(<NewsGrid pages={[page(3)]} />);

        const button = screen.getByRole('button', { name: /Raw headline 3/ });
        expect(button).toHaveAttribute('aria-expanded', 'false');

        await user.click(button);

        expect(button).toHaveAttribute('aria-expanded', 'true');
        expect(
            within(button).getByText('WWE Championship'),
        ).toBeInTheDocument();
    });

    it('shows an empty state with no news', () => {
        render(<NewsGrid pages={[]} />);

        expect(screen.getByText(/No news yet/)).toBeInTheDocument();
    });
});
