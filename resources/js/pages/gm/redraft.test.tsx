import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Redraft from '@/pages/gm/redraft';

const row = (
    id: number,
    name: string,
    brand: 'raw' | 'smackdown',
    extra: object = {},
) => ({
    id,
    name,
    slug: name.toLowerCase(),
    brand,
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
    contract: null,
    popularity: null,
    specialties: [],
    locked: null,
    ...extra,
});

const baseProps = {
    game: {
        id: 1,
        player_brand: 'raw',
        rival_brand: 'smackdown',
        fatigue_config: {
            bar_size: 10,
            safe_max: 70,
            serious_at: 100,
            max: 100,
        },
    },
    phase: 'immunity',
    series: {
        season: 1,
        raw_wins: 2,
        smackdown_wins: 1,
        winner_brand: 'raw',
        immunity_brand: 'raw',
        exemption_wrestler_id: null,
        handpick_wrestler_id: null,
        crowds: { raw: 410_000, smackdown: 380_000 },
    },
    rosters: {
        raw: [
            row(1, 'Edge', 'raw'),
            row(2, 'John Cena', 'raw', {
                titles: ['WWE Championship'],
                locked: 'Champion',
            }),
        ],
        smackdown: [
            row(3, 'Rey Mysterio', 'smackdown'),
            row(4, 'JBL', 'smackdown'),
        ],
    },
    order: ['raw', 'smackdown', 'raw', 'smackdown'],
    per_brand: 2,
    picks: [],
};

let props: typeof baseProps = baseProps;
const post = vi.fn();
const requestJson = vi.fn();

vi.mock('@/lib/http', () => ({
    requestJson: (...args: unknown[]) => requestJson(...args),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: (...args: unknown[]) => post(...args) },
    usePage: () => ({ props }),
}));

describe('WrestleMania Re-Draft', () => {
    beforeEach(() => {
        props = baseProps;
        post.mockReset();
        requestJson.mockReset();
        window.localStorage.setItem('gm.redraft.skip-animation', 'on');
    });

    it('explains who holds each tag and why', () => {
        render(<Redraft />);

        expect(screen.getByTestId('immunity-tag')).toHaveTextContent(
            'Raw drew more fans to its weekly shows before WrestleMania',
        );
        expect(screen.getByTestId('manual-tag')).toHaveTextContent(
            'Raw won the WrestleMania interbrand series 2–1',
        );
    });

    it('protects one of your own with the immunity tag after a confirmation, never a champion', async () => {
        const user = userEvent.setup();
        render(<Redraft />);

        const use = screen.getByRole('button', {
            name: 'Use the immunity tag',
        });
        expect(use).toBeDisabled();
        expect(
            within(screen.getByTestId('redraft-row-2')).getByRole('radio'),
        ).toBeDisabled();
        expect(
            within(screen.getByTestId('redraft-roster-smackdown')).queryByRole(
                'radio',
            ),
        ).toBeNull();

        await user.click(
            within(screen.getByTestId('redraft-row-1')).getByRole('radio'),
        );
        await user.click(use);

        // Nothing is sent until the confirmation is accepted.
        expect(post).not.toHaveBeenCalled();
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent('Use the immunity tag?');
        expect(dialog).toHaveTextContent('Edge is protected and stays on Raw');

        await user.click(
            within(dialog).getByRole('button', { name: 'Protect Edge' }),
        );
        expect(post).toHaveBeenCalledWith(
            expect.stringContaining('/redraft/immunity'),
            { save_wrestler_id: 1 },
            expect.anything(),
        );
    });

    it('confirms the manual pick from the other brand', async () => {
        props = { ...baseProps, phase: 'manual' };
        const user = userEvent.setup();
        render(<Redraft />);

        await user.click(
            within(screen.getByTestId('redraft-row-3')).getByRole('radio'),
        );
        await user.click(
            screen.getByRole('button', { name: 'Use the manual pick' }),
        );

        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent(
            'Rey Mysterio moves from Smackdown to Raw as the first pick',
        );

        await user.click(
            within(dialog).getByRole('button', { name: 'Draft Rey Mysterio' }),
        );
        expect(post).toHaveBeenCalledWith(
            expect.stringContaining('/redraft/handpick'),
            { save_wrestler_id: 3 },
            expect.anything(),
        );
    });

    it('plays the picks out, moves each superstar across and then continues to the news', async () => {
        props = { ...baseProps, phase: 'picks' };
        const picks = [
            {
                pick_number: 1,
                brand: 'raw',
                from: 'smackdown',
                save_wrestler_id: 3,
                name: 'Rey Mysterio',
                pick_type: 'manual',
                skipped: false,
            },
            {
                pick_number: 2,
                brand: 'smackdown',
                from: 'raw',
                save_wrestler_id: 1,
                name: 'Edge',
                pick_type: 'random',
                skipped: false,
            },
        ];
        requestJson
            .mockResolvedValueOnce({ pick: picks[0], done: false })
            .mockResolvedValueOnce({ pick: picks[1], done: true });
        const user = userEvent.setup();
        render(<Redraft />);

        await user.click(
            screen.getByRole('button', { name: 'Start the re-draft' }),
        );

        await waitFor(() =>
            expect(screen.getByTestId('redraft-picks')).toHaveTextContent(
                'Edge',
            ),
        );
        expect(
            within(screen.getByTestId('redraft-roster-raw')).getByText(
                'Rey Mysterio',
            ),
        ).toBeInTheDocument();
        expect(
            within(screen.getByTestId('redraft-roster-smackdown')).getByText(
                'Edge',
            ),
        ).toBeInTheDocument();
        expect(screen.getByTestId('redraft-picks')).toHaveTextContent(
            'Manual pick',
        );

        await user.click(
            await screen.findByRole('button', { name: 'Continue' }),
        );
        expect(post).toHaveBeenCalledWith(
            expect.stringContaining('/redraft/finish'),
            {},
            expect.anything(),
        );
    });

    it('spins a reel for each random pick, never the manual one, unless animation is skipped', async () => {
        window.localStorage.setItem('gm.redraft.skip-animation', 'off');
        // Reduced motion keeps the reel and the move short for the test.
        vi.stubGlobal('matchMedia', () => ({ matches: true }));
        props = { ...baseProps, phase: 'picks' };
        const manual = {
            pick_number: 1,
            brand: 'raw',
            from: 'smackdown',
            save_wrestler_id: 3,
            name: 'Rey Mysterio',
            pick_type: 'manual',
            skipped: false,
        };
        const random = {
            pick_number: 2,
            brand: 'smackdown',
            from: 'raw',
            save_wrestler_id: 1,
            name: 'Edge',
            pick_type: 'random',
            skipped: false,
        };
        let release: () => void = () => {};
        requestJson
            .mockResolvedValueOnce({ pick: manual, done: false })
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        release = () => resolve({ pick: random, done: true });
                    }),
            );
        const user = userEvent.setup();
        render(<Redraft />);

        await user.click(
            screen.getByRole('button', { name: 'Start the re-draft' }),
        );

        // The manual pick moves with no reel.
        await waitFor(() =>
            expect(
                within(screen.getByTestId('redraft-roster-raw')).getByText(
                    'Rey Mysterio',
                ),
            ).toBeInTheDocument(),
        );
        expect(screen.queryByTestId('superstar-reel-face')).toBeNull();

        release();
        const reel = await screen.findByRole('dialog', {
            name: 'Re-draft pick 2',
        });
        await waitFor(() =>
            expect(reel).toHaveTextContent('Smackdown drafts Edge!'),
        );

        // The reel closes on its own, then Edge moves across.
        await waitFor(() =>
            expect(
                within(
                    screen.getByTestId('redraft-roster-smackdown'),
                ).getByText('Edge'),
            ).toBeInTheDocument(),
        );
        expect(screen.queryByRole('dialog')).toBeNull();
        vi.unstubAllGlobals();
    });
});
