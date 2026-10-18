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
        win_condition: 'Ladder retrieval',
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
        is_default_manager: false,
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

    return { onSave, onClear, user: userEvent.setup() };
}

describe('CardBuilder', () => {
    it('books a match: type first, then wrestlers, then save', async () => {
        const { onSave, user } = setup();

        await user.selectOptions(
            screen.getByLabelText('Match type for match 1'),
            '1',
        );
        await user.selectOptions(
            screen.getByLabelText('Match 1 side 1 wrestler 1'),
            '10',
        );
        await user.selectOptions(
            screen.getByLabelText('Match 1 side 2 wrestler 1'),
            '11',
        );
        await user.click(screen.getByRole('button', { name: 'Save match 1' }));

        expect(onSave).toHaveBeenCalledWith(1, {
            match_type_id: 1,
            sides: [[10], [11]],
            managers: {},
            title_championship_id: null,
            stipulation: null,
            stipulation_championship_id: null,
        });
    });

    it('keeps Save disabled until every competitor spot is filled', async () => {
        const { user } = setup();

        await user.selectOptions(
            screen.getByLabelText('Match type for match 1'),
            '1',
        );
        await user.selectOptions(
            screen.getByLabelText('Match 1 side 1 wrestler 1'),
            '10',
        );

        expect(
            screen.getByRole('button', { name: 'Save match 1' }),
        ).toBeDisabled();
    });

    it('shows the match type cost and fatigue indicator', async () => {
        const { user } = setup();

        await user.selectOptions(
            screen.getByLabelText('Match type for match 1'),
            '2',
        );

        const info = screen.getByTestId('type-info-1');
        expect(info).toHaveTextContent('$15,000');
        expect(info).toHaveTextContent('▮▮▮');
    });

    it('disables seriously injured wrestlers', async () => {
        const { user } = setup();

        await user.selectOptions(
            screen.getByLabelText('Match type for match 1'),
            '1',
        );

        const flair = within(
            screen.getByLabelText('Match 1 side 1 wrestler 1'),
        ).getByRole('option', { name: /Ric Flair/ });
        expect(flair).toBeDisabled();
        expect(flair).toHaveTextContent('Seriously injured');
    });

    it('flags wrestlers booked in more than one match', async () => {
        const { user } = setup();

        await user.selectOptions(
            screen.getByLabelText('Match type for match 1'),
            '1',
        );
        await user.selectOptions(
            screen.getByLabelText('Match 1 side 1 wrestler 1'),
            '10',
        );
        await user.selectOptions(
            screen.getByLabelText('Match type for match 2'),
            '1',
        );
        await user.selectOptions(
            screen.getByLabelText('Match 2 side 1 wrestler 1'),
            '10',
        );

        expect(screen.getAllByText('×2 matches')).toHaveLength(2);
        expect(screen.getByTestId('slot-1-side-0-0').className).toContain(
            'border-zone-minor',
        );
    });

    it('projects fatigue through the card and shows the zone each booking lands in', async () => {
        const { user } = setup();

        // Edge is at 60%. A 3-bar ladder match takes him to 90%: minor risk.
        await user.selectOptions(
            screen.getByLabelText('Match type for match 1'),
            '2',
        );
        await user.selectOptions(
            screen.getByLabelText('Match 1 side 1 wrestler 1'),
            '10',
        );

        const first = screen.getByTestId('slot-1-side-0-0');
        expect(within(first).getByRole('meter')).toHaveAttribute(
            'aria-valuetext',
            '60%, 90% after booking',
        );
        expect(first).toHaveTextContent('Minor risk');
        expect(within(first).getByText('★ Ladder')).toBeInTheDocument();

        // Booked again in match 2, he goes in at 90%.
        await user.selectOptions(
            screen.getByLabelText('Match type for match 2'),
            '1',
        );
        await user.selectOptions(
            screen.getByLabelText('Match 2 side 1 wrestler 1'),
            '10',
        );

        expect(screen.getByTestId('slot-2-side-0-0')).toHaveTextContent(
            'Going in: Minor risk',
        );
    });

    it('does not let a competitor manage the same match', async () => {
        const { user } = setup();

        await user.selectOptions(
            screen.getByLabelText('Match type for match 1'),
            '3',
        );
        await user.selectOptions(
            screen.getByLabelText('Match 1 side 1 wrestler 1'),
            '10',
        );

        const manager = screen.getByLabelText('Match 1 manager for side 2');
        expect(
            within(manager).getByRole('option', { name: /Edge/ }),
        ).toBeDisabled();
        expect(
            within(manager).getByRole('option', { name: /Kane/ }),
        ).not.toBeDisabled();
    });

    it('lists only Divas for Divas-only match types', async () => {
        const { user } = setup();

        await user.selectOptions(
            screen.getByLabelText('Match type for match 1'),
            '4',
        );

        const names = within(screen.getByLabelText('Match 1 side 1 wrestler 1'))
            .getAllByRole('option')
            .map((o) => o.textContent);

        expect(names).toEqual(['Pick a wrestler…', 'Lita', 'Trish Stratus']);
    });

    it('clears a slot', async () => {
        const booked: CardSlot = {
            ...emptySlot(1),
            match_type_id: 1,
            sides: [[10], [11]],
        };
        const { onClear, user } = setup([booked, emptySlot(2)]);

        await user.click(screen.getByRole('button', { name: 'Clear match 1' }));

        expect(onClear).toHaveBeenCalledWith(1);
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

        expect(screen.getByLabelText('Match type for match 1')).toBeDisabled();
        expect(
            screen.queryByRole('button', { name: /Save/ }),
        ).not.toBeInTheDocument();
    });
});
