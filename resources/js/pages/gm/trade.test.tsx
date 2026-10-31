import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import ProposeTrade from '@/pages/gm/trade';

const row = (id: number, name: string, extra: object = {}) => ({
    id,
    name,
    roster_type: 'superstar',
    weight_class: 'heavyweight',
    titles: [],
    sitting_out: false,
    fatigue: 30,
    injury: null,
    morale: 'happy',
    wins: 4,
    losses: 2,
    win_streak: 0,
    loss_streak: 0,
    contract: { weeks_remaining: 20, weekly_cost: 8000 },
    popularity: null,
    specialties: ['ladder'],
    untradeable: null,
    ...extra,
});

const props = {
    game: {
        id: 1,
        player_brand: 'smackdown',
        rival_brand: 'raw',
        roster_cap: 30,
        fatigue_config: {
            bar_size: 10,
            safe_max: 70,
            serious_at: 100,
            max: 100,
        },
    },
    yours: [row(1, 'Edge'), row(2, 'Batista')],
    theirs: [
        row(3, 'Kane', {
            titles: ['Intercontinental Championship'],
            untradeable: 'Champion',
        }),
        row(4, 'Lita', { roster_type: 'diva' }),
    ],
    roster_counts: { smackdown: 30, raw: 30 },
    max_per_side: 5,
    judge: {
        legend_value: 2,
        champion_history_value: 1,
        focus_value: 1,
        fair_accept: 0.9,
        unfair_accept: 0.05,
        fair_ratio: 1,
    },
    history: [],
};

const requestJson = vi.fn(async () => ({ chance: 0.9 }));

vi.mock('@/lib/http', () => ({
    requestJson: (...args: unknown[]) => requestJson(...(args as [])),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: vi.fn() },
    usePage: () => ({ props }),
}));

const sendButton = () => screen.getByRole('button', { name: 'Send offer' });

describe('Propose Trade', () => {
    it('explains why the offer can’t go yet', async () => {
        const user = userEvent.setup();
        render(<ProposeTrade />);

        expect(sendButton()).toBeDisabled();
        expect(screen.getByText(/Pick up to 5 wrestlers/)).toBeInTheDocument();

        // 1 for 0 leaves the rival at 31.
        await user.click(
            within(screen.getByTestId('trade-row-1')).getByRole('checkbox'),
        );
        expect(sendButton()).toBeDisabled();
        expect(
            screen.getByText(
                'Their roster would end at 31/30. Ask for more or send fewer.',
            ),
        ).toBeInTheDocument();

        await user.click(
            within(screen.getByTestId('trade-row-4')).getByRole('checkbox'),
        );
        expect(sendButton()).toBeEnabled();
        expect(screen.getByText(/1 for 1/)).toBeInTheDocument();
    });

    it('lists the offer and lets you remove someone from it', async () => {
        const user = userEvent.setup();
        render(<ProposeTrade />);

        await user.click(
            within(screen.getByTestId('trade-row-2')).getByRole('checkbox'),
        );
        const offer = screen.getByRole('region', { name: 'Your offer' });
        expect(offer).toHaveTextContent('Batista');

        await user.click(
            within(offer).getByRole('button', { name: 'Remove Batista' }),
        );
        expect(offer).not.toHaveTextContent('Batista');
    });

    it('says why a champion can’t be traded and shows the details that matter', () => {
        render(<ProposeTrade />);

        const kane = screen.getByTestId('trade-row-3');
        expect(within(kane).getByRole('checkbox')).toBeDisabled();
        expect(kane).toHaveTextContent('Champion:can’t be traded');

        const edge = screen.getByTestId('trade-row-1');
        expect(edge).toHaveTextContent('4–2');
        expect(edge).toHaveTextContent('20w left');
        expect(
            within(edge).getByRole('meter', { name: 'Fatigue' }),
        ).toBeInTheDocument();
    });

    it('searches each list on its own', async () => {
        const user = userEvent.setup();
        render(<ProposeTrade />);

        await user.type(
            screen.getByRole('searchbox', { name: 'Search: you send' }),
            'bat',
        );

        expect(screen.queryByTestId('trade-row-1')).toBeNull();
        expect(screen.getByTestId('trade-row-2')).toBeInTheDocument();
        expect(screen.getByTestId('trade-row-4')).toBeInTheDocument();
    });

    it('lists the trade rules under the heading, with the rival’s numbers', () => {
        render(<ProposeTrade />);

        const rules = screen
            .getByRole('heading', { name: 'How trades work' })
            .closest('section') as HTMLElement;
        expect(rules).toHaveTextContent('Up to 5 wrestlers on each side');
        expect(rules).toHaveTextContent('Neither roster can end up over 30');
        expect(rules).toHaveTextContent(
            'Champions and seriously injured superstars can’t be traded',
        );

        const judge = within(rules).getByTestId('trade-judge');
        expect(judge).toHaveTextContent('+2 points for a Legend');
        expect(judge).toHaveTextContent('+1 point for a Diva');
        expect(judge).toHaveTextContent('at least as many points as they give');
        expect(judge).toHaveTextContent(
            '90% of the time, and anything else 5%',
        );
    });

    it('shows the rival’s chance of accepting, green at 90% and orange below', async () => {
        const user = userEvent.setup();
        render(<ProposeTrade />);

        expect(screen.queryByTestId('offer-chance')).toBeNull();

        await user.click(
            within(screen.getByTestId('trade-row-1')).getByRole('checkbox'),
        );
        await user.click(
            within(screen.getByTestId('trade-row-4')).getByRole('checkbox'),
        );

        const chance = await screen.findByTestId('offer-chance');
        expect(chance).toHaveTextContent('Chance the rival GM accepts: 90%');
        expect(within(chance).getByText('90%').className).toContain(
            'text-zone-safe',
        );
        expect(requestJson).toHaveBeenLastCalledWith(
            'POST',
            '/gm/saves/1/trade/odds',
            { out: [1], in: [4] },
        );

        requestJson.mockResolvedValueOnce({ chance: 0.05 });
        await user.click(
            within(screen.getByTestId('trade-row-4')).getByRole('checkbox'),
        );
        await user.click(
            within(screen.getByTestId('trade-row-2')).getByRole('checkbox'),
        );
        // 2 for 0 is over their cap, so there's no chance to show.
        expect(screen.queryByTestId('offer-chance')).toBeNull();
    });
});
