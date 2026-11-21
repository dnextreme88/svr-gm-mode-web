import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import Reports from '@/pages/gm/reports';

const records = {
    leaders: [
        {
            key: 'main_events',
            label: 'Most PPV main events',
            rows: [{ name: 'Edge', brand: 'raw', count: 3 }],
        },
        { key: 'openers', label: 'Most Match 1 openers', rows: [] },
    ],
    rumble_winners: [
        { season: 1, name: 'Batista', brand: 'smackdown', eliminations: 4 },
    ],
    survivor_series: [{ season: 1, winner: 'raw' }],
    interbrand_series: [],
    mitb: [],
    ppv_shows: [],
};

const props = {
    game: { id: 1, player_brand: 'raw', rival_brand: 'smackdown' },
    shows: [],
    attendance: [],
    shared_ppvs: [],
    records,
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props, url: '/gm/saves/1/reports' }),
    router: { visit: vi.fn(), replace: vi.fn() },
}));

describe('Reports tabs (house rule, 2026-11-21)', () => {
    it('opens on Results and switches to the Records book', async () => {
        const user = userEvent.setup();
        render(<Reports />);

        expect(screen.getByRole('tab', { name: 'Results' })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(screen.queryByTestId('records-book')).toBeNull();

        await user.click(screen.getByRole('tab', { name: 'Records book' }));

        const book = screen.getByTestId('records-book');
        expect(
            within(book).getByRole('list', { name: 'Most PPV main events' }),
        ).toHaveTextContent('Edge');
        expect(book).toHaveTextContent('Batista · 4 eliminated');
        expect(book).toHaveTextContent('Raw won');
        expect(book).toHaveTextContent('Nobody yet.');
    });
});
