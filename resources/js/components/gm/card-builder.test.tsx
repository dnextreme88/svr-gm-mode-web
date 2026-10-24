import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { CardBuilder } from '@/components/gm/card-builder';
import type {
    CardShow,
    CardSlot,
    FatigueConfig,
    MatchTypeOption,
    RosterEntry,
} from '@/types/gm';

// The Clear confirmation reads the player's brand from the Inertia page.
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: {} }),
}));

const config: FatigueConfig = {
    bar_size: 10,
    safe_max: 70,
    serious_at: 100,
    max: 100,
};

const show: CardShow = {
    id: 1,
    name: 'Monday Night Raw',
    show_type: 'weekly',
    brand: 'raw',
    status: 'booking',
    week_number: 1,
    ppv_event_id: null,
    match_slot_count: 2,
    promo_slot_count: 2,
};

function type(overrides: Partial<MatchTypeOption>): MatchTypeOption {
    return {
        id: 1,
        name: 'Single — No Manager',
        family: 'single',
        variant: 'No Manager',
        sides: [1, 1],
        manager_sides: [],
        fatigue_cost_bars: 1,
        cost: 0,
        divas_only: false,
        divas_allowed: true,
        titles_allowed: true,
        win_condition: null,
        ppv_only: false,
        captain_fall: false,
        ...overrides,
    };
}

const matchTypes = [
    type({}),
    type({
        id: 2,
        name: 'Ladder — Single',
        family: 'ladder',
        variant: 'Single',
        fatigue_cost_bars: 3,
        cost: 15000,
        win_condition: 'Briefcase retrieval',
    }),
    type({
        id: 3,
        name: 'Single — No Manager vs. w/Manager',
        variant: 'No Manager vs. w/Manager',
        manager_sides: [1],
    }),
    type({
        id: 4,
        name: 'Fulfill Your Fantasy — Single',
        family: 'fulfill_your_fantasy',
        variant: 'Single',
        divas_only: true,
    }),
    type({
        id: 5,
        name: '6-Man Tag — Captain Fall Tornado 6-Man Tag Elimination',
        family: 'six_man_tag',
        variant: 'Captain Fall Tornado 6-Man Tag Elimination',
        sides: [3, 3],
        titles_allowed: false,
        captain_fall: true,
    }),
    type({
        id: 6,
        name: 'Special Referee — Single',
        family: 'special_referee',
        variant: 'Single',
        special_referee: true,
    }),
    type({
        id: 7,
        name: 'Hell In A Cell — Armageddon Hell in a Cell',
        family: 'hell_in_a_cell',
        variant: 'Armageddon Hell in a Cell',
        sides: [1, 1, 1, 1, 1, 1],
    }),
    type({
        id: 8,
        name: 'Ladder — Fatal 4-Way',
        family: 'ladder',
        variant: 'Fatal 4-Way',
        sides: [1, 1, 1, 1],
    }),
];

function wrestler(
    id: number,
    name: string,
    overrides: Partial<RosterEntry> = {},
): RosterEntry {
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
        ...overrides,
    };
}

const roster = [
    wrestler(10, 'Edge', { fatigue: 60, specialties: ['tlc', 'ladder'] }),
    wrestler(11, 'Kane'),
    wrestler(12, 'Lita', {
        roster_type: 'diva',
        weight_class: 'featherweight',
    }),
    wrestler(13, 'Ric Flair', {
        bookable: false,
        blocked_reason: 'Seriously injured',
        injury: { label: 'Torn ACL', severity: 'serious', weeks_left: 30 },
    }),
    wrestler(14, 'Trish Stratus', {
        roster_type: 'diva',
        weight_class: 'featherweight',
    }),
];

const familyLabel: Record<number, string> = {
    1: 'Normal Matches',
    2: 'Ladder',
    3: 'Normal Matches',
    4: 'Fulfill Your Fantasy',
    5: '6-Man Tag',
    6: 'Special Referee',
    7: 'Hell In A Cell',
    8: 'Ladder',
};

function escapeRe(text: string): string {
    return text.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&');
}

function emptySlot(position: number): CardSlot {
    return {
        id: position,
        position,
        match_type_id: null,
        sides: [],
        managers: {},
        title_championship_id: null,
        stipulation: null,
        stipulation_championship_id: null,
        cost: 0,
        fixed_kind: null,
        owner_brand: 'raw',
    };
}

function setup(slots: CardSlot[] = [emptySlot(1), emptySlot(2)]) {
    const onSave = vi.fn();
    const onClear = vi.fn();

    render(
        <CardBuilder
            show={show}
            slots={slots}
            matchTypes={matchTypes}
            roster={roster}
            fatigueConfig={config}
            onSave={onSave}
            onClear={onClear}
        />,
    );

    const user = userEvent.setup();

    /** Open the match type picker, choose the family, then the variant. */
    const pickType = async (position: number, typeId: number) => {
        const t = matchTypes.find((m) => m.id === typeId) as MatchTypeOption;
        await user.click(
            screen.getByRole('button', {
                name: `Match type for match ${position}`,
            }),
        );
        const dialog = screen.getByRole('dialog', {
            name: `Match type for match ${position}`,
        });
        await user.click(
            within(dialog).getByRole('option', {
                name: new RegExp(`^${familyLabel[typeId]}`),
            }),
        );
        await user.click(
            within(
                within(dialog).getByRole('listbox', {
                    name: `${familyLabel[typeId]} matches`,
                }),
            ).getByRole('option', {
                name: new RegExp(`^${escapeRe(t.variant)} (Free|\\$)`),
            }),
        );
    };

    /** Open a wrestler slot and pick a name. */
    const pickWrestler = async (label: string, name: string) => {
        await user.click(screen.getByRole('button', { name: label }));
        await user.click(
            within(screen.getByRole('listbox', { name: label })).getByRole(
                'option',
                { name: new RegExp(name) },
            ),
        );
    };

    return { onSave, onClear, user, pickType, pickWrestler };
}

describe('CardBuilder', () => {
    it('books a match: type first, then wrestlers, then save', async () => {
        const { onSave, user, pickType, pickWrestler } = setup();

        await pickType(1, 1);
        await pickWrestler('Match 1 side 1 wrestler 1', 'Edge');
        await pickWrestler('Match 1 side 2 wrestler 1', 'Kane');
        await user.click(screen.getByRole('button', { name: 'Save match 1' }));

        expect(onSave).toHaveBeenCalledWith(1, {
            match_type_id: 1,
            sides: [[10], [11]],
            managers: {},
            title_championship_id: null,
            stipulation: null,
            stipulation_championship_id: null,
            referee_id: null,
        });
    });

    it('picks the match type in two steps and shows the full name', async () => {
        const { user, pickType } = setup();

        await user.click(
            screen.getByRole('button', { name: 'Match type for match 1' }),
        );
        // Variants only appear once a category is picked.
        expect(
            screen.queryByRole('listbox', { name: 'Ladder matches' }),
        ).not.toBeInTheDocument();
        await user.keyboard('{Escape}');

        await pickType(1, 5);

        expect(screen.getByTestId('match-type-path-1')).toHaveTextContent(
            '6-Man Tag → Captain Fall Tornado 6-Man Tag Elimination',
        );
        expect(
            screen.queryByRole('dialog', { name: 'Match type for match 1' }),
        ).not.toBeInTheDocument();
    });

    it('closes a picker on Escape and on a click outside', async () => {
        const { user, pickType } = setup();
        await pickType(1, 1);

        const slot = 'Match 1 side 1 wrestler 1';
        await user.click(screen.getByRole('button', { name: slot }));
        expect(screen.getByRole('listbox', { name: slot })).toBeInTheDocument();

        await user.keyboard('{Escape}');
        expect(
            screen.queryByRole('listbox', { name: slot }),
        ).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: slot }));
        await user.click(document.body);
        expect(
            screen.queryByRole('listbox', { name: slot }),
        ).not.toBeInTheDocument();
    });

    it('shows VACANT on empty wrestler slots', async () => {
        const { pickType } = setup();

        await pickType(1, 1);

        expect(
            screen.getByRole('button', { name: 'Match 1 side 1 wrestler 1' }),
        ).toHaveTextContent('VACANT');
    });

    it('marks a slot with unsaved changes until it is saved', async () => {
        const { user, pickType, pickWrestler } = setup();
        const slot = screen.getByRole('region', { name: 'Match 1' });
        expect(slot).not.toHaveAttribute('data-dirty');

        await pickType(1, 1);
        expect(slot).toHaveAttribute('data-dirty', 'true');
        expect(slot.className).toContain('gm-panel-dirty');
        expect(within(slot).getByText('Unsaved changes')).toBeInTheDocument();

        await pickWrestler('Match 1 side 1 wrestler 1', 'Edge');
        await pickWrestler('Match 1 side 2 wrestler 1', 'Kane');
        await user.click(screen.getByRole('button', { name: 'Save match 1' }));

        expect(slot).not.toHaveAttribute('data-dirty');
        expect(within(slot).queryByText('Unsaved changes')).toBeNull();
    });

    it('shows a floppy icon on Save', () => {
        setup();

        expect(
            within(
                screen.getByRole('button', { name: 'Save match 1' }),
            ).getByTestId('floppy-icon'),
        ).toHaveAttribute('aria-hidden', 'true');
    });

    it('keeps Save disabled until every competitor spot is filled', async () => {
        const { pickType, pickWrestler } = setup();

        await pickType(1, 1);
        await pickWrestler('Match 1 side 1 wrestler 1', 'Edge');

        expect(
            screen.getByRole('button', { name: 'Save match 1' }),
        ).toBeDisabled();
    });

    it('shows the match type cost in green and its fatigue bars', async () => {
        const { pickType } = setup();

        await pickType(1, 2);

        const info = screen.getByTestId('type-info-1');
        expect(info).toHaveTextContent('$15,000');
        expect(within(info).getByTestId('match-cost').className).toContain(
            'text-zone-safe',
        );
        expect(info.querySelectorAll('[data-state="filled"]')).toHaveLength(3);
    });

    it('shows free match types as an italic green Free', async () => {
        const { pickType } = setup();

        await pickType(1, 1);

        const cost = within(screen.getByTestId('type-info-1')).getByTestId(
            'match-cost',
        );
        expect(cost).toHaveTextContent('Free');
        expect(cost.className).toContain('text-zone-safe');
        expect(cost.className).toContain('italic');
    });

    it('labels the first wrestler on each side as Captain in a Captain Fall match', async () => {
        const { pickType } = setup();

        await pickType(1, 5);

        expect(screen.getByTestId('captain-1-0')).toHaveTextContent('Captain');
        expect(screen.getByTestId('captain-1-1')).toHaveTextContent('Captain');
        expect(screen.getAllByText('Captain')).toHaveLength(2);
    });

    it('disables seriously injured wrestlers', async () => {
        const { user, pickType } = setup();

        await pickType(1, 1);
        await user.click(
            screen.getByRole('button', { name: 'Match 1 side 1 wrestler 1' }),
        );

        const flair = within(
            screen.getByRole('listbox', { name: 'Match 1 side 1 wrestler 1' }),
        ).getByRole('option', { name: /Ric Flair/ });
        expect(flair).toBeDisabled();
        expect(flair).toHaveTextContent('Seriously injured');
    });

    it('flags wrestlers booked in more than one match', async () => {
        const { pickType, pickWrestler } = setup();

        await pickType(1, 1);
        await pickWrestler('Match 1 side 1 wrestler 1', 'Edge');
        await pickType(2, 1);
        await pickWrestler('Match 2 side 1 wrestler 1', 'Edge');

        expect(screen.getAllByText('×2 matches')).toHaveLength(2);
        expect(screen.getByTestId('slot-1-side-0-0').className).toContain(
            'border-zone-minor',
        );
    });

    it('marks wrestlers booked elsewhere on the card as Booked', async () => {
        const { user, pickType, pickWrestler } = setup();

        await pickType(1, 1);
        await pickWrestler('Match 1 side 1 wrestler 1', 'Edge');
        await pickType(2, 1);
        await user.click(
            screen.getByRole('button', { name: 'Match 2 side 1 wrestler 1' }),
        );

        const list = screen.getByRole('listbox', {
            name: 'Match 2 side 1 wrestler 1',
        });
        const edge = within(list).getByRole('option', { name: /Edge/ });
        const booked = within(edge).getByText('Booked');

        expect(edge).toBeEnabled();
        expect(booked.className).toContain('text-gold-hot');
        expect(
            within(list).getByRole('option', { name: /Kane/ }),
        ).not.toHaveTextContent('Booked');
    });

    it('lays a 6-way match out in two rows of 3, lined up', async () => {
        const { pickType } = setup();

        await pickType(1, 7);

        const rows = [0, 1].map((r) => screen.getByTestId(`sides-1-row-${r}`));
        expect(rows.map((r) => r.style.gridTemplateColumns)).toEqual(
            Array(2).fill(
                'minmax(0,1fr) auto minmax(0,1fr) auto minmax(0,1fr)',
            ),
        );
        expect(
            rows.map(
                (r) =>
                    within(r).getAllByRole('button', { name: /wrestler/ })
                        .length,
            ),
        ).toEqual([3, 3]);
        expect(within(rows[0]).getAllByText('vs')).toHaveLength(2);
    });

    it('lays a Fatal 4-Way out as 2 × 2', async () => {
        const { pickType } = setup();

        await pickType(1, 8);

        expect(
            screen.getByTestId('sides-1-row-0').style.gridTemplateColumns,
        ).toBe('minmax(0,1fr) auto minmax(0,1fr)');
        expect(screen.getByTestId('sides-1-row-1')).toBeInTheDocument();
    });

    it('keeps singles on one row, with a vs between the sides', async () => {
        const { pickType } = setup();

        await pickType(1, 1);

        const row = screen.getByTestId('sides-1-row-0');
        expect(row.style.gridTemplateColumns).toBe(
            'minmax(0,1fr) auto minmax(0,1fr)',
        );
        // Stacked on small screens: one dropdown per line, vs between.
        expect(row.className).toContain('flex-col');
        expect(within(row).getAllByText('vs')).toHaveLength(1);
        expect(screen.queryByTestId('sides-1-row-1')).not.toBeInTheDocument();
    });

    it('keeps a team’s wrestlers together, with one vs between the teams', async () => {
        const { pickType } = setup();

        await pickType(1, 5);

        const row = screen.getByTestId('sides-1-row-0');
        expect(within(row).getAllByText('vs')).toHaveLength(1);
        expect(
            within(row).getAllByRole('button', { name: /wrestler/ }),
        ).toHaveLength(6);
    });

    it('projects fatigue through the card and shows the zone each booking lands in', async () => {
        const { pickType, pickWrestler } = setup();

        // Edge is at 60%. A 3-bar ladder match takes him to 90%: minor risk.
        await pickType(1, 2);
        await pickWrestler('Match 1 side 1 wrestler 1', 'Edge');

        const first = screen.getByTestId('slot-1-side-0-0');
        expect(within(first).getByRole('meter')).toHaveAttribute(
            'aria-valuetext',
            '60%, 90% after booking',
        );
        expect(first).toHaveTextContent('Minor risk');
        expect(within(first).getByText('★ Ladder')).toBeInTheDocument();

        // Booked again in match 2, he goes in at 90%.
        await pickType(2, 1);
        await pickWrestler('Match 2 side 1 wrestler 1', 'Edge');

        expect(screen.getByTestId('slot-2-side-0-0')).toHaveTextContent(
            'Going in: Minor risk',
        );
    });

    it('counts saved promo fatigue placed before a match', async () => {
        const user = userEvent.setup();
        render(
            <CardBuilder
                show={show}
                slots={[emptySlot(1), emptySlot(2)]}
                matchTypes={matchTypes}
                roster={roster}
                fatigueConfig={config}
                onSave={vi.fn()}
                onClear={vi.fn()}
                promoFatigue={[{ wrestlerId: 11, beforePosition: 2, bars: 2 }]}
            />,
        );
        const label = 'Match type for match 2';
        await user.click(screen.getByRole('button', { name: label }));
        await user.click(
            within(screen.getByRole('dialog', { name: label })).getByRole(
                'option',
                { name: /^Normal Matches/ },
            ),
        );
        await user.click(
            within(
                screen.getByRole('listbox', { name: 'Normal Matches matches' }),
            ).getByRole('option', { name: /^No Manager (Free|\$)/ }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Match 2 side 1 wrestler 1' }),
        );
        await user.click(
            within(
                screen.getByRole('listbox', {
                    name: 'Match 2 side 1 wrestler 1',
                }),
            ).getByRole('option', { name: /Kane/ }),
        );

        expect(
            within(screen.getByTestId('slot-2-side-0-0')).getByRole('meter'),
        ).toHaveAttribute('aria-valuetext', '20%, 30% after booking');
    });

    it('labels and fills the manager slot with the same picker', async () => {
        const { pickType, pickWrestler } = setup();

        await pickType(1, 3);
        await pickWrestler('Match 1 side 1 wrestler 1', 'Edge');

        const manager = screen.getByTestId('manager-1-1');
        expect(manager).toHaveTextContent('Manager');
        expect(within(manager).getByText('Manager').className).toContain(
            'text-gold',
        );

        const { user } = { user: userEvent.setup() };
        await user.click(
            screen.getByRole('button', { name: 'Match 1 manager for side 2' }),
        );
        const list = screen.getByRole('listbox', {
            name: 'Match 1 manager for side 2',
        });
        expect(
            within(list).getByRole('option', { name: /Edge/ }),
        ).toBeDisabled();
        expect(
            within(list).getByRole('option', { name: /Kane/ }),
        ).not.toBeDisabled();
    });

    it('needs a special referee who is not in the match', async () => {
        const { onSave, user, pickType, pickWrestler } = setup();

        await pickType(1, 6);
        await pickWrestler('Match 1 side 1 wrestler 1', 'Edge');
        await pickWrestler('Match 1 side 2 wrestler 1', 'Kane');

        expect(screen.getByTestId('referee-1')).toHaveTextContent(
            'Special referee',
        );
        expect(
            screen.getByRole('button', { name: 'Save match 1' }),
        ).toBeDisabled();

        await user.click(
            screen.getByRole('button', { name: 'Match 1 special referee' }),
        );
        expect(
            within(
                screen.getByRole('listbox', {
                    name: 'Match 1 special referee',
                }),
            ).getByRole('option', { name: /Edge/ }),
        ).toBeDisabled();
        await user.keyboard('{Escape}');

        await pickWrestler('Match 1 special referee', 'Trish Stratus');
        await user.click(screen.getByRole('button', { name: 'Save match 1' }));

        expect(onSave).toHaveBeenCalledWith(
            1,
            expect.objectContaining({ sides: [[10], [11]], referee_id: 14 }),
        );
    });

    it('lists only Divas for Divas-only match types', async () => {
        const { user, pickType } = setup();

        await pickType(1, 4);
        await user.click(
            screen.getByRole('button', { name: 'Match 1 side 1 wrestler 1' }),
        );

        const names = within(
            screen.getByRole('listbox', { name: 'Match 1 side 1 wrestler 1' }),
        )
            .getAllByRole('option')
            .map((o) => o.textContent);

        expect(names).toEqual(['Leave vacant', 'Lita', 'Trish Stratus']);
    });

    it('tracks booked matches on a progress bar', () => {
        setup([
            { ...emptySlot(1), match_type_id: 1, sides: [[10], [11]] },
            emptySlot(2),
        ]);

        const bar = screen.getByRole('progressbar', {
            name: 'Matches booked',
        });
        expect(bar).toHaveAttribute('aria-valuenow', '1');
        expect(bar).toHaveAttribute('aria-valuemax', '2');
        expect(screen.getByText('1 of 2 matches booked')).toBeInTheDocument();
    });

    it('clears a slot with a red Clear button, after confirming', async () => {
        const booked: CardSlot = {
            ...emptySlot(1),
            match_type_id: 1,
            sides: [[10], [11]],
        };
        const { onClear, user } = setup([booked, emptySlot(2)]);

        const clear = screen.getByRole('button', { name: 'Clear match 1' });
        expect(clear.className).toContain('gm-btn-danger');
        await user.click(clear);

        const dialog = screen.getByRole('dialog', { name: 'Clear match 1?' });
        expect(onClear).not.toHaveBeenCalled();
        await user.click(
            within(dialog).getByRole('button', { name: 'Yes, clear it' }),
        );

        expect(onClear).toHaveBeenCalledWith(1);
        expect(
            screen.getByRole('button', { name: 'Match type for match 1' }),
        ).toHaveTextContent('Choose a match type');
    });

    it('keeps the slot when the Clear confirmation is cancelled', async () => {
        const booked: CardSlot = {
            ...emptySlot(1),
            match_type_id: 1,
            sides: [[10], [11]],
        };
        const { onClear, user } = setup([booked, emptySlot(2)]);

        await user.click(screen.getByRole('button', { name: 'Clear match 1' }));
        await user.click(
            within(
                screen.getByRole('dialog', { name: 'Clear match 1?' }),
            ).getByRole('button', { name: 'Cancel' }),
        );

        expect(onClear).not.toHaveBeenCalled();
        expect(screen.getByTestId('match-type-path-1')).toHaveTextContent(
            'Normal Matches → No Manager',
        );
    });

    it('only enables Discard when a slot has unsaved changes', async () => {
        const { pickType } = setup();
        const discard = screen.getByRole('button', {
            name: 'Discard changes to match 1',
        });
        expect(discard).toBeDisabled();

        await pickType(1, 1);

        expect(discard).toBeEnabled();
    });

    it('discards unsaved changes back to the saved booking', async () => {
        const booked: CardSlot = {
            ...emptySlot(1),
            match_type_id: 1,
            sides: [[10], [11]],
        };
        const { onSave, onClear, user, pickType } = setup([
            booked,
            emptySlot(2),
        ]);

        await pickType(1, 2);
        expect(screen.getByTestId('match-type-path-1')).toHaveTextContent(
            'Ladder → Single',
        );

        await user.click(
            screen.getByRole('button', { name: 'Discard changes to match 1' }),
        );

        const slot = screen.getByRole('region', { name: 'Match 1' });
        expect(screen.getByTestId('match-type-path-1')).toHaveTextContent(
            'Normal Matches → No Manager',
        );
        expect(
            screen.getByRole('button', { name: 'Match 1 side 1 wrestler 1' }),
        ).toHaveTextContent('Edge');
        expect(
            screen.getByRole('button', { name: 'Match 1 side 2 wrestler 1' }),
        ).toHaveTextContent('Kane');
        expect(slot).not.toHaveAttribute('data-dirty');
        expect(
            screen.getByRole('button', { name: 'Save match 1' }),
        ).toBeDisabled();
        // Discarding never touches the server.
        expect(onSave).not.toHaveBeenCalled();
        expect(onClear).not.toHaveBeenCalled();
    });

    it('discards a never-saved slot back to empty', async () => {
        const { user, pickType, pickWrestler } = setup();

        await pickType(2, 1);
        await pickWrestler('Match 2 side 1 wrestler 1', 'Edge');
        await user.click(
            screen.getByRole('button', { name: 'Discard changes to match 2' }),
        );

        expect(
            screen.getByRole('button', { name: 'Match type for match 2' }),
        ).toHaveTextContent('Choose a match type');
        expect(
            screen.queryByRole('button', { name: 'Match 2 side 1 wrestler 1' }),
        ).toBeNull();
        // Edge's other bookings no longer count this slot.
        expect(screen.queryByText('×2 matches')).toBeNull();
    });

    it('is read-only once the show has aired', () => {
        render(
            <CardBuilder
                show={{ ...show, status: 'aired' }}
                slots={[emptySlot(1)]}
                matchTypes={matchTypes}
                roster={roster}
                fatigueConfig={config}
                onSave={vi.fn()}
                onClear={vi.fn()}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Match type for match 1' }),
        ).toBeDisabled();
        expect(
            screen.queryByRole('button', { name: /Save/ }),
        ).not.toBeInTheDocument();
    });
});
