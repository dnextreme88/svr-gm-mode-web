import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LOADING_OVERLAY_DELAY_MS } from '@/components/gm/loading-overlay';
import Draft from '@/pages/gm/draft';

const post = vi.fn();
const visit = vi.fn();

const pick = (
    n: number,
    brand: 'raw' | 'smackdown',
    name: string,
    rosterType: 'superstar' | 'diva',
    weightClass: string,
) => ({
    pick_number: n,
    brand,
    save_wrestler_id: n,
    name,
    roster_type: rosterType,
    weight_class: weightClass,
    pick_type: 'manual',
});

const props = {
    game: { id: 3, player_brand: 'raw', budget: 9_500_000 },
    pool: [],
    picks: [
        pick(1, 'raw', 'John Cena', 'superstar', 'heavyweight'),
        pick(2, 'smackdown', 'Batista', 'superstar', 'heavyweight'),
        pick(3, 'raw', 'Trish Stratus', 'diva', 'featherweight'),
        pick(4, 'smackdown', 'Rey Mysterio', 'superstar', 'cruiserweight'),
        pick(5, 'raw', 'Chris Jericho', 'superstar', 'light_heavyweight'),
    ],
    next_brand: 'raw',
    max_picks: 30,
    picks_made: { raw: 3, smackdown: 2 },
    draft_order: ['raw', 'smackdown'],
    contract_length: 52,
    player_done: false,
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: {
        post: (...args: unknown[]) => post(...args),
        visit: (...args: unknown[]) => visit(...args),
    },
    usePage: () => ({ props: page.props }),
}));

const page: { props: Record<string, unknown> } = { props };

afterEach(() => {
    post.mockReset();
    visit.mockReset();
    page.props = props;
});

describe('Stop drafting (house rule, 2026-11-07)', () => {
    it('reviews your picks before you stop', async () => {
        const user = userEvent.setup();
        render(<Draft />);

        await user.click(screen.getByRole('button', { name: 'Stop drafting' }));

        const summary = screen.getByTestId('stop-draft-summary');
        expect(summary).toHaveTextContent('3 of 30');
        expect(summary).toHaveTextContent('Divas1');
        expect(summary).toHaveTextContent('Cruiser / LHW1');
        expect(summary).toHaveTextContent('$9,500,000');
        expect(
            within(
                screen.getByRole('list', { name: 'Your picks' }),
            ).getAllByRole('listitem'),
        ).toHaveLength(3);
        expect(summary).not.toHaveTextContent('Batista');
        expect(summary).toHaveTextContent('then you crown your champions');
        // Keep drafting is the safe default.
        expect(
            screen.getByRole('button', { name: 'Keep drafting' }),
        ).toHaveFocus();
    });

    it('keeps drafting, or stops and moves on to the champions', async () => {
        const user = userEvent.setup();
        render(<Draft />);

        await user.click(screen.getByRole('button', { name: 'Stop drafting' }));
        await user.click(screen.getByRole('button', { name: 'Keep drafting' }));
        expect(screen.queryByTestId('stop-draft-summary')).toBeNull();
        expect(post).not.toHaveBeenCalled();

        await user.click(screen.getByRole('button', { name: 'Stop drafting' }));
        await user.click(
            screen.getByRole('button', { name: 'Stop and crown champions' }),
        );
        expect(post).toHaveBeenCalledWith('/gm/saves/3/draft/finish');
    });
});

describe('End of the draft', () => {
    it('says the draft picks are finished, then opens the champions', () => {
        vi.useFakeTimers();
        page.props = { ...props, player_done: true, concluded: true };
        render(<Draft />);

        expect(screen.getByTestId('draft-concluded')).toHaveTextContent(
            "Draft picks are finished. You may now assign your brand's champions.",
        );
        expect(visit).not.toHaveBeenCalled();

        act(() => {
            vi.advanceTimersByTime(LOADING_OVERLAY_DELAY_MS);
        });
        expect(visit).toHaveBeenCalledWith('/gm/saves/3');
        vi.useRealTimers();
    });

    it('has no overlay while the draft is on', () => {
        render(<Draft />);

        expect(screen.queryByTestId('draft-concluded')).toBeNull();
    });
});
