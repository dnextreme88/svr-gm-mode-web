import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { PromoSlots, successLabel } from '@/components/gm/promo-slots';
import type {
    PromoEligibilityView,
    PromoSlotView,
    PromoTypeOption,
} from '@/components/gm/promo-slots';
import { saveVisit } from '@/lib/save-status';
import type { RosterEntry } from '@/types/gm';

// The Clear confirmation reads the player's brand from the Inertia page.
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: {} }),
}));

function promo(
    id: number,
    key: string,
    name: string,
    overrides: Partial<PromoTypeOption> = {},
): PromoTypeOption {
    return {
        id,
        key,
        name,
        amount: -2000,
        success_rate: 0.9,
        needs_wrestler: false,
        needs_rival_pair: false,
        needs_diva_pair: false,
        needs_championship: false,
        fatigue_bars: 0,
        targets_rival: false,
        variable_amount: false,
        effect: `${name} effect`,
        requirement: null,
        ...overrides,
    };
}

const types = [
    promo(1, 'advertising', 'Advertising', { amount: 150000, success_rate: 1 }),
    promo(2, 'inactive_superstar', 'Inactive Superstar', {
        needs_wrestler: true,
    }),
    promo(3, 'interference_promotion', 'Interference Promotion', {
        needs_wrestler: true,
        fatigue_bars: 2,
        success_rate: 0.5,
    }),
    promo(4, 'continue_rivalry', 'Continue Rivalry', {
        needs_rival_pair: true,
    }),
    promo(5, 'title_match_hype', 'Title Match Hype', {
        needs_championship: true,
    }),
];

function rules(
    overrides: Partial<PromoEligibilityView> = {},
): PromoEligibilityView {
    return {
        available: true,
        reason: null,
        wrestler_ids: [],
        pairs: [],
        championship_ids: [],
        ...overrides,
    };
}

const eligibility: Record<string, PromoEligibilityView> = {
    advertising: rules(),
    inactive_superstar: rules({
        available: false,
        reason: 'Nobody on your roster is seriously injured.',
    }),
    interference_promotion: rules({ wrestler_ids: [10] }),
    continue_rivalry: rules({
        wrestler_ids: [11, 12, 10],
        // Kane vs Edge and Edge vs Carlito, stored in no particular order.
        pairs: [
            [11, 10],
            [10, 12],
        ],
    }),
    title_match_hype: rules({ championship_ids: [7] }),
};

function wrestler(id: number, name: string): RosterEntry {
    return {
        id,
        name,
        slug: name.toLowerCase(),
        roster_type: 'superstar',
        weight_class: 'heavyweight',
        brand: 'raw',
        morale: 'happy',
        fatigue: 0,
        fatigue_zone: 'safe',
        wins: 0,
        losses: 0,
        win_streak: 0,
        loss_streak: 0,
        popularity: null,
        specialties: [],
        injury: null,
        sitting_out: false,
        unbookable_until_week: null,
        contract: null,
        titles: [],
        bookable: true,
        blocked_reason: null,
    };
}

const roster = [
    wrestler(10, 'Edge'),
    wrestler(11, 'Kane'),
    wrestler(12, 'Carlito'),
];

const emptySlot = (position: number): PromoSlotView => ({
    position,
    promo_type_id: null,
    target_wrestler_id: null,
    target_wrestler_b_id: null,
    succeeded: null,
    backfired: false,
    outcome_note: null,
});

function setup(slots: PromoSlotView[] = [emptySlot(1)]) {
    const onSave = vi.fn();
    const onClear = vi.fn();

    render(
        <PromoSlots
            showId={1}
            slots={slots}
            types={types}
            eligibility={eligibility}
            roster={roster}
            matchSlotCount={6}
            titleNames={{ 7: 'WWE Championship' }}
            readOnly={false}
            onSave={onSave}
            onClear={onClear}
        />,
    );

    return { onSave, onClear, user: userEvent.setup() };
}

const grid = () => screen.getByRole('radiogroup', { name: 'Promo 1 type' });

describe('PromoSlots', () => {
    it('shows a success range for a promo whose odds depend on a rolled deal', () => {
        expect(
            successLabel({ success_rate: 0.8, success_range: [0.25, 0.8] }),
        ).toBe('25–80%');
        expect(successLabel({ success_rate: 0.5 })).toBe('50%');
    });

    it('greys out Save once the promo is saved, until something changes', async () => {
        const { user } = setup([
            {
                ...emptySlot(1),
                promo_type_id: 3,
                target_wrestler_id: 10,
                before_position: 6,
            },
        ]);

        const save = screen.getByRole('button', { name: 'Save promo 1' });
        expect(save).toBeDisabled();
        expect(within(save).getByTestId('floppy-icon')).toHaveAttribute(
            'data-status',
            'idle',
        );

        await user.click(screen.getByRole('radio', { name: 'Before match 2' }));
        expect(save).toBeEnabled();
        expect(screen.getByText('Unsaved changes')).toBeInTheDocument();
    });

    it('animates the floppy while the promo saves, then shows the check', () => {
        setup();
        const floppy = within(
            screen.getByRole('button', { name: 'Save promo 1' }),
        ).getByTestId('floppy-icon');
        const visit = saveVisit('promo:1:1');

        act(() => visit.onStart());
        expect(floppy).toHaveAttribute('data-status', 'saving');

        act(() => visit.onSuccess());
        expect(floppy).toHaveAttribute('data-status', 'saved');

        act(() => visit.onError());
        expect(floppy).toHaveAttribute('data-status', 'idle');
    });

    it('discards unsaved changes back to the saved promo', async () => {
        const { user } = setup([
            {
                ...emptySlot(1),
                promo_type_id: 3,
                target_wrestler_id: 10,
                before_position: 6,
            },
        ]);
        const discard = screen.getByRole('button', {
            name: 'Discard changes to promo 1',
        });
        expect(discard).toBeDisabled();

        await user.click(screen.getByTestId('promo-1-advertising'));
        expect(discard).toBeEnabled();

        await user.click(discard);
        expect(
            screen.getByTestId('promo-1-interference_promotion'),
        ).toHaveAttribute('aria-checked', 'true');
        expect(
            screen.getByRole('radio', { name: 'Before main event' }),
        ).toHaveAttribute('aria-checked', 'true');
        expect(discard).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Save promo 1' }),
        ).toBeDisabled();
        expect(screen.queryByText('Unsaved changes')).toBeNull();
    });

    it('won’t put the same wrestler in two promos of the same type', async () => {
        const { user } = setup([
            {
                ...emptySlot(1),
                promo_type_id: 3,
                target_wrestler_id: 10,
                before_position: 6,
            },
            emptySlot(2),
        ]);

        await user.click(screen.getByTestId('promo-2-interference_promotion'));
        await user.click(
            screen.getByRole('button', { name: 'Promo 2 wrestler' }),
        );
        const edge = within(
            screen.getByRole('listbox', { name: 'Promo 2 wrestler' }),
        ).getByRole('option', { name: /Edge/ });

        expect(edge).toHaveAttribute('aria-disabled', 'true');
        expect(edge).toHaveTextContent('In Promo 1');
    });

    it('blocks the spot another promo already airs in, and defaults to the next free one', async () => {
        const { user } = setup([
            { ...emptySlot(1), promo_type_id: 1, before_position: 6 },
            emptySlot(2),
        ]);

        await user.click(screen.getByTestId('promo-2-advertising'));
        const group = screen.getByRole('radiogroup', { name: 'Promo 2 airs' });
        const mainEvent = within(group).getByRole('radio', {
            name: 'Before main event',
        });

        expect(mainEvent).toBeDisabled();
        expect(mainEvent).toHaveAttribute('title', 'Promo 1 already airs here');
        expect(mainEvent.className).toContain('cursor-not-allowed');
        expect(mainEvent.querySelector('svg')?.getAttribute('class')).toContain(
            'text-zone-serious',
        );
        expect(
            within(group).getByRole('radio', { name: 'Before match 5' }),
        ).toHaveAttribute('aria-checked', 'true');
        expect(
            screen.getByRole('button', { name: 'Save promo 2' }),
        ).toBeEnabled();
    });

    it('lists every promo alphabetically, left to right', () => {
        setup();

        const names = within(grid())
            .getAllByRole('radio')
            .map((b) => b.querySelector('span')?.textContent);

        expect(names).toEqual([
            'Advertising',
            'Continue Rivalry',
            'Inactive Superstar',
            'Interference Promotion',
            'Title Match Hype',
        ]);
    });

    it('greys out promos nobody can do, with the reason', () => {
        setup();

        const inactive = screen.getByTestId('promo-1-inactive_superstar');
        expect(inactive).toBeDisabled();
        expect(inactive.className).toContain('opacity-40');
        expect(inactive).toHaveAttribute(
            'title',
            'Nobody on your roster is seriously injured.',
        );
    });

    it('labels success rates as Success Probability and draws promo fatigue as bars', () => {
        setup();

        const interference = screen.getByTestId(
            'promo-1-interference_promotion',
        );
        expect(interference).toHaveTextContent('Success Probability: 50%');
        expect(
            interference.querySelectorAll('[data-state="filled"]'),
        ).toHaveLength(2);
    });

    it('asks for a wrestler and saves with the chosen placement', async () => {
        const { onSave, user } = setup();

        await user.click(screen.getByTestId('promo-1-interference_promotion'));
        expect(screen.getByTestId('promo-details-1')).toHaveTextContent(
            'Interference Promotion effect',
        );
        expect(
            screen.getByRole('radio', { name: 'Before main event' }),
        ).toHaveAttribute('aria-checked', 'true');

        await user.click(
            screen.getByRole('button', { name: 'Promo 1 wrestler' }),
        );
        const options = within(
            screen.getByRole('listbox', { name: 'Promo 1 wrestler' }),
        ).getAllByRole('option');
        expect(options.map((o) => o.textContent)).toEqual([
            'Leave vacant',
            'Edge',
        ]);
        await user.click(options[1]);
        await user.click(screen.getByRole('radio', { name: 'Before match 2' }));
        await user.click(screen.getByRole('button', { name: 'Save promo 1' }));

        expect(onSave).toHaveBeenCalledWith(1, {
            promo_type_id: 3,
            target_wrestler_id: 10,
            target_wrestler_b_id: null,
            target_championship_id: null,
            before_position: 2,
        });
    });

    it('picks a Continue Rivalry from one list of rivalries, named alphabetically', async () => {
        const { onSave, user } = setup();

        await user.click(screen.getByTestId('promo-1-continue_rivalry'));
        expect(
            screen.queryByRole('button', { name: 'Promo 1 second wrestler' }),
        ).toBeNull();

        await user.click(
            screen.getByRole('button', { name: 'Promo 1 rivalry' }),
        );
        const options = within(
            screen.getByRole('listbox', { name: 'Promo 1 rivalry' }),
        ).getAllByRole('option');

        expect(options.map((o) => o.textContent)).toEqual([
            'Carlito vs. Edge',
            'Edge vs. Kane',
        ]);

        await user.click(options[1]);
        expect(
            screen.getByRole('button', { name: 'Promo 1 rivalry' }),
        ).toHaveTextContent('Edge vs. Kane');

        await user.click(screen.getByRole('button', { name: 'Save promo 1' }));
        expect(onSave).toHaveBeenCalledWith(
            1,
            expect.objectContaining({
                promo_type_id: 4,
                target_wrestler_id: 10,
                target_wrestler_b_id: 11,
            }),
        );
    });

    it('picks the title for Title Match Hype', async () => {
        const { onSave, user } = setup();

        await user.click(screen.getByTestId('promo-1-title_match_hype'));
        expect(
            screen.getByRole('button', { name: 'Save promo 1' }),
        ).toBeDisabled();

        await user.click(
            screen.getByRole('radio', { name: '◆ WWE Championship' }),
        );
        await user.click(screen.getByRole('button', { name: 'Save promo 1' }));

        expect(onSave).toHaveBeenCalledWith(
            1,
            expect.objectContaining({ target_championship_id: 7 }),
        );
    });

    it('uses a red Clear button', () => {
        setup();

        expect(
            screen.getByRole('button', { name: 'Clear' }).className,
        ).toContain('gm-btn-danger');
    });

    it('asks before clearing a booked promo', async () => {
        const onClear = vi.fn();
        const user = userEvent.setup();
        render(
            <PromoSlots
                showId={1}
                slots={[
                    {
                        position: 1,
                        promo_type_id: 1,
                        target_wrestler_id: null,
                        target_wrestler_b_id: null,
                        succeeded: null,
                        backfired: false,
                        outcome_note: null,
                    },
                ]}
                types={types}
                eligibility={eligibility}
                roster={roster}
                matchSlotCount={6}
                titleNames={{}}
                readOnly={false}
                onSave={vi.fn()}
                onClear={onClear}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Clear' }));
        const dialog = screen.getByRole('dialog', { name: 'Clear promo 1?' });
        expect(onClear).not.toHaveBeenCalled();

        await user.click(
            within(dialog).getByRole('button', { name: 'Yes, clear it' }),
        );

        expect(onClear).toHaveBeenCalledWith(1);
    });
});
