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
        career: 1_763_000_000,
        raw_wins: 2,
        smackdown_wins: 1,
        winner_brand: 'raw',
        immunity_brand: 'raw',
        exemption_wrestler_ids: [] as number[],
        immunity_needed: 1,
        manual_needed: 1,
        handpick_wrestler_ids: [] as number[],
        crowds: { raw: 410_000, smackdown: 380_000 },
    },
    rosters: {
        raw: [
            row(1, 'Edge', 'raw', {
                contract: {
                    length_weeks: 52,
                    weeks_remaining: 21,
                    weekly_cost: 4615,
                    starts_week: 1,
                },
            }),
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

const rulesKey = () =>
    `gm.redraft-rules.${baseProps.game.id}.${baseProps.series.career}.${baseProps.series.season}`;

describe('WrestleMania Re-Draft', () => {
    beforeEach(() => {
        props = baseProps;
        post.mockReset();
        requestJson.mockReset();
        window.localStorage.clear();
        window.localStorage.setItem('gm.redraft.skip-animation', 'on');
        // The rules modal only opens by itself on a first visit.
        window.localStorage.setItem(rulesKey(), 'seen');
    });

    it('puts your brand on the left and the rival’s on the right', () => {
        props = {
            ...baseProps,
            game: {
                ...baseProps.game,
                player_brand: 'smackdown',
                rival_brand: 'raw',
            },
        };
        render(<Redraft />);

        const smackdown = screen.getByTestId('redraft-roster-smackdown');
        const raw = screen.getByTestId('redraft-roster-raw');
        expect(
            smackdown.compareDocumentPosition(raw) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it('shows each superstar’s contract left, like Propose Trade', () => {
        render(<Redraft />);

        expect(screen.getByTestId('redraft-row-1')).toHaveTextContent(
            '21w left',
        );
    });

    it('explains who holds each tag and why, in a modal', async () => {
        const user = userEvent.setup();
        render(<Redraft />);

        expect(screen.queryByTestId('redraft-rules')).toBeNull();
        await user.click(screen.getByTestId('redraft-rules-button'));

        expect(screen.getByTestId('immunity-tag')).toHaveTextContent(
            'This tag is awarded to Raw for drawing more fans to its weekly shows before WrestleMania',
        );
        expect(screen.getByTestId('manual-tag')).toHaveTextContent(
            'This tag is awarded to Raw for winning the WrestleMania interbrand tournament with a score of 2–1',
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
            within(screen.getByTestId('redraft-row-2')).getByRole('checkbox'),
        ).toBeDisabled();
        expect(
            within(screen.getByTestId('redraft-roster-smackdown')).queryByRole(
                'checkbox',
            ),
        ).toBeNull();

        await user.click(
            within(screen.getByTestId('redraft-row-1')).getByRole('checkbox'),
        );
        await user.click(use);

        // Nothing is sent until the confirmation is accepted.
        expect(post).not.toHaveBeenCalled();
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent('Use the immunity tag?');
        expect(dialog).toHaveTextContent(
            'Edge will be exempted and will stay on Raw',
        );

        await user.click(
            within(dialog).getByRole('button', { name: 'Exempt Edge' }),
        );
        expect(post).toHaveBeenCalledWith(
            expect.stringContaining('/redraft/immunity'),
            { save_wrestler_ids: [1] },
            expect.anything(),
        );
    });

    it('confirms the manual pick from the other brand', async () => {
        props = { ...baseProps, phase: 'manual' };
        const user = userEvent.setup();
        render(<Redraft />);

        await user.click(
            within(screen.getByTestId('redraft-row-3')).getByRole('checkbox'),
        );
        await user.click(
            screen.getByRole('button', { name: 'Use the manual pick' }),
        );

        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent(
            'Rey Mysterio will move from Smackdown to Raw as the first pick',
        );

        await user.click(
            within(dialog).getByRole('button', { name: 'Draft Rey Mysterio' }),
        );
        expect(post).toHaveBeenCalledWith(
            expect.stringContaining('/redraft/handpick'),
            { save_wrestler_ids: [3] },
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
            screen.getByRole('button', {
                name: 'Start the Post-WrestleMania Season 1 Draft',
            }),
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
        // Every pick's logo sits in the same fixed-width box, so names align.
        for (const logo of screen.getAllByTestId('redraft-pick-logo')) {
            expect(logo).toHaveClass('w-20', 'shrink-0');
        }

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
            screen.getByRole('button', {
                name: 'Start the Post-WrestleMania Season 1 Draft',
            }),
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

    it('opens the rules by itself on a first visit, and Got it closes them for good', async () => {
        window.localStorage.removeItem(rulesKey());
        const user = userEvent.setup();
        const { unmount } = render(<Redraft />);

        expect(screen.getByRole('dialog')).toHaveTextContent(
            'Post-WrestleMania Season 1 draft',
        );
        expect(screen.getByTestId('redraft-welcome')).toHaveTextContent(
            'Welcome to the Post-WrestleMania draft!',
        );
        await user.click(screen.getByRole('button', { name: 'Got it' }));
        expect(screen.queryByRole('dialog')).toBeNull();

        unmount();
        render(<Redraft />);
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('reveals the rival’s immunity tag with a reel, once', async () => {
        window.localStorage.setItem('gm.redraft.skip-animation', 'off');
        vi.stubGlobal('matchMedia', () => ({ matches: true }));
        props = {
            ...baseProps,
            phase: 'manual',
            series: {
                ...baseProps.series,
                immunity_brand: 'smackdown',
                exemption_wrestler_ids: [4],
            },
            rosters: {
                ...baseProps.rosters,
                smackdown: [
                    row(3, 'Rey Mysterio', 'smackdown'),
                    row(4, 'JBL', 'smackdown', { locked: 'Immune' }),
                ],
            },
        } as unknown as typeof baseProps;
        const { unmount } = render(<Redraft />);

        expect(
            screen.getByRole('dialog', { name: 'Immunity tag' }),
        ).toBeInTheDocument();
        await waitFor(() =>
            expect(
                screen.getByText('Smackdown protects JBL!'),
            ).toBeInTheDocument(),
        );
        await waitFor(() =>
            expect(
                screen.queryByRole('dialog', { name: 'Immunity tag' }),
            ).toBeNull(),
        );

        unmount();
        render(<Redraft />);
        expect(
            screen.queryByRole('dialog', { name: 'Immunity tag' }),
        ).toBeNull();
        vi.unstubAllGlobals();
    });

    it('reveals the rival’s manual draft pick with a reel', async () => {
        window.localStorage.setItem('gm.redraft.skip-animation', 'off');
        vi.stubGlobal('matchMedia', () => ({ matches: true }));
        props = {
            ...baseProps,
            phase: 'picks',
            series: {
                ...baseProps.series,
                winner_brand: 'smackdown',
                handpick_wrestler_ids: [1],
            },
        } as unknown as typeof baseProps;
        render(<Redraft />);

        await waitFor(() =>
            expect(
                screen.getByText(
                    'Smackdown takes Edge with the manual draft pick!',
                ),
            ).toBeInTheDocument(),
        );
        vi.unstubAllGlobals();
    });

    it('skips the rival’s tag reels when animation is skipped', () => {
        props = {
            ...baseProps,
            phase: 'manual',
            series: {
                ...baseProps.series,
                immunity_brand: 'smackdown',
                exemption_wrestler_ids: [4],
            },
        } as unknown as typeof baseProps;
        render(<Redraft />);

        expect(
            screen.queryByRole('dialog', { name: 'Immunity tag' }),
        ).toBeNull();
    });

    it('names the chosen superstars under each brand’s tags', () => {
        props = {
            ...baseProps,
            phase: 'picks',
            series: {
                ...baseProps.series,
                exemption_wrestler_ids: [1],
                handpick_wrestler_ids: [4],
            },
        } as unknown as typeof baseProps;
        render(<Redraft />);

        expect(screen.getByTestId('tag-chosen-immunity-raw')).toHaveTextContent(
            'Immunity tag: Raw has chosen Edge to be exempted from the draft',
        );
        expect(screen.getByTestId('tag-chosen-manual-raw')).toHaveTextContent(
            'Manual draft pick tag: Raw has chosen JBL as its 1st pick, replacing the usual random pick',
        );
        expect(
            screen.queryByTestId('tag-chosen-immunity-smackdown'),
        ).toBeNull();
    });

    it('greys out the side you aren’t choosing from in steps 1 and 2', () => {
        const { unmount } = render(<Redraft />);

        // Step 1: you protect one of yours, so the rival's side is greyed.
        expect(screen.getByTestId('tag-card-smackdown').className).toContain(
            'opacity-50',
        );
        expect(
            screen.getByTestId('redraft-side-smackdown').className,
        ).toContain('opacity-50');
        expect(screen.getByTestId('tag-card-raw').className).not.toContain(
            'opacity-50',
        );
        unmount();

        // Step 2: you pick from the rival, so your own side is greyed.
        props = { ...baseProps, phase: 'manual' };
        const step2 = render(<Redraft />);
        expect(screen.getByTestId('redraft-side-raw').className).toContain(
            'opacity-50',
        );
        expect(
            screen.getByTestId('redraft-side-smackdown').className,
        ).not.toContain('opacity-50');
        step2.unmount();

        // Picks: nothing is greyed.
        props = { ...baseProps, phase: 'picks' };
        render(<Redraft />);
        expect(screen.getByTestId('redraft-side-raw').className).not.toContain(
            'opacity-50',
        );
    });

    it('separates several chosen superstars with commas, and asks for exactly as many as the tag takes', async () => {
        props = {
            ...baseProps,
            phase: 'manual',
            series: { ...baseProps.series, manual_needed: 2 },
        } as unknown as typeof baseProps;
        const user = userEvent.setup();
        const { unmount } = render(<Redraft />);
        const use = screen.getByRole('button', { name: 'Use the manual pick' });

        await user.click(
            within(screen.getByTestId('redraft-row-3')).getByRole('checkbox'),
        );
        expect(use).toBeDisabled();
        await user.click(
            within(screen.getByTestId('redraft-row-4')).getByRole('checkbox'),
        );
        expect(use).toBeEnabled();
        await user.click(use);
        await user.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Draft 2 superstars',
            }),
        );
        expect(post).toHaveBeenCalledWith(
            expect.stringContaining('/redraft/handpick'),
            { save_wrestler_ids: [3, 4] },
            expect.anything(),
        );
        unmount();

        props = {
            ...baseProps,
            phase: 'picks',
            series: { ...baseProps.series, handpick_wrestler_ids: [3, 4] },
        } as unknown as typeof baseProps;
        render(<Redraft />);
        expect(screen.getByTestId('tag-chosen-manual-raw')).toHaveTextContent(
            'Raw has chosen Rey Mysterio, JBL as its first 2 picks, replacing the usual random picks',
        );
    });
});
