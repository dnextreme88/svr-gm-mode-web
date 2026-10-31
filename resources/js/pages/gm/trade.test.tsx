import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
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
        base_value: 1,
        legend_value: 2,
        focus_value: 1,
        specialty_value: 1,
        minor_injury_value: -1,
        win_streak_step: 5,
        win_streak_value: 1,
        loss_streak_from: 7,
        loss_streak_value: -1,
        tag_partner_value: 1,
        full_tag_team_value: 1,
        full_stable_value: 2,
        world_reign_bands: { 1: 1, 5: 2, 8: 3 },
        repeat_title_reigns: 2,
        repeat_title_value: 1,
        fair_ratio: 1,
        fair_accept: 0.75,
        fair_ahead_accept: { 1: 0.9, 2: 1 },
        unfair_accept: 0.25,
    },
    history: [],
};

const assessment = (chance: number) => ({
    chance,
    fair: true,
    gets: { total: 3, lines: { base: 1, legend: 2 } },
    gives: { total: 1, lines: { base: 2, minor_injury: -1 } },
});

const requestJson = vi.fn(async () => assessment(0.9));

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
    beforeEach(() => {
        requestJson.mockReset();
        requestJson.mockImplementation(async () => assessment(0.9));
    });

    it('explains why the offer can’t go yet', async () => {
        const user = userEvent.setup();
        render(<ProposeTrade />);

        expect(sendButton()).toBeDisabled();
        expect(screen.getByText(/Pick up to 5 superstars/)).toBeInTheDocument();

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
        // Nothing to explain once the offer can go.
        expect(document.getElementById('offer-hint')).toBeNull();
        expect(sendButton()).not.toHaveAttribute('aria-describedby');
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
        expect(rules).toHaveTextContent('Up to 5 superstars on each side');
        expect(rules).toHaveTextContent('Neither roster can end up over 30');
        expect(rules).toHaveTextContent(
            'Champions and seriously injured superstars can’t be traded',
        );

        const judge = within(rules).getByTestId('trade-judge');
        // Each rule is its own line, grouped under a heading line.
        const lines = within(judge)
            .getAllByRole('listitem')
            .map((li) => li.textContent);
        expect(lines).toEqual(
            expect.arrayContaining([
                'A Legend: +2',
                'A Diva: +1',
                'A match specialist: +1',
                'Their tag team partner is on the rival’s roster: +1',
                'A whole tag team traded together: +1',
                'A whole stable traded together: +2',
                'Every 5 wins in a row: +1',
                'A losing streak of 7 or more: −1',
                'A minor injury: −1',
                'Their own brand’s world title: +1 for 1–4 reigns, +2 for 5–7, +3 for 8 or more',
                'The same other title 2 or more times: +1',
            ]),
        );
        expect(judge).toHaveTextContent('at least as many points as they give');
        expect(lines).toEqual(
            expect.arrayContaining([
                'A fair offer with even points: 75%',
                'They come out 1 point ahead: 90%',
                'They come out 2 or more points ahead: 100%',
                'Anything else: 25%',
            ]),
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

        requestJson.mockResolvedValueOnce(assessment(0.2));
        await user.click(
            within(screen.getByTestId('trade-row-4')).getByRole('checkbox'),
        );
        await user.click(
            within(screen.getByTestId('trade-row-2')).getByRole('checkbox'),
        );
        // 2 for 0 is over their cap, so there's no chance to show.
        expect(screen.queryByTestId('offer-chance')).toBeNull();
    });

    it('shows orange below 90% and lists each side’s points by category', async () => {
        requestJson.mockImplementation(async () => assessment(0.8));
        const user = userEvent.setup();
        render(<ProposeTrade />);

        await user.click(
            within(screen.getByTestId('trade-row-1')).getByRole('checkbox'),
        );
        await user.click(
            within(screen.getByTestId('trade-row-4')).getByRole('checkbox'),
        );

        const chance = await screen.findByTestId('offer-chance');
        expect(within(chance).getByText('80%').className).toContain(
            'text-gm-orange',
        );

        const gets = screen.getByTestId('offer-points-gets');
        expect(gets).toHaveTextContent('They get: 3 points');
        expect(
            within(gets)
                .getAllByRole('listitem')
                .map((li) => li.textContent),
        ).toEqual(['Superstars: +1', 'Legends: +2']);

        const gives = screen.getByTestId('offer-points-gives');
        expect(gives).toHaveTextContent('They give: 1 point');
        expect(within(gives).getByText('−1').className).toContain(
            'text-zone-serious',
        );
    });
});
