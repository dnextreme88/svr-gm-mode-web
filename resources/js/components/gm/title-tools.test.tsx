import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { StipulationPicker } from '@/components/gm/title-tools';
import type { SlotDraft } from '@/lib/card';
import type {
    MatchTypeOption,
    RosterEntry,
    TitleContext,
    TitleOption,
} from '@/types/gm';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: {} }),
}));

const singles = {
    id: 1,
    sides: [1, 1],
    titles_allowed: true,
} as MatchTypeOption;

// Daivari (1) holds the World Heavyweight and Cruiserweight titles; Eugene (2)
// is a Light Heavyweight with no title.
const roster = new Map<number, RosterEntry>([
    [
        1,
        {
            id: 1,
            name: 'Daivari',
            roster_type: 'superstar',
            weight_class: 'cruiserweight',
        } as RosterEntry,
    ],
    [
        2,
        {
            id: 2,
            name: 'Eugene',
            roster_type: 'superstar',
            weight_class: 'light_heavyweight',
        } as RosterEntry,
    ],
]);

const title = (
    id: number,
    name: string,
    holders: number[],
    eligibility: TitleOption['eligibility'] = 'open',
): TitleOption => ({
    id,
    name,
    is_tag: false,
    eligibility,
    holder_ids: holders,
    holder_name: holders.length > 0 ? 'Daivari' : null,
});

const context = (overrides: Partial<TitleContext> = {}): TitleContext => ({
    championships: [
        title(5, 'World Heavyweight Championship', [1]),
        title(
            8,
            'Cruiserweight Championship',
            [1],
            'cruiserweight_or_light_heavyweight',
        ),
    ],
    lockouts: {},
    last_chance: [],
    title_shots: [],
    briefcase: null,
    title_holder_ids: [1],
    ...overrides,
});

const start: SlotDraft = {
    position: 1,
    matchTypeId: 1,
    sides: [[1], [2]],
    managers: {},
    titleChampionshipId: null,
    stipulation: null,
    stipulationChampionshipId: null,
    refereeId: null,
    fixed: false,
};

function Harness({
    readOnly = false,
    ctx = context(),
    initial = start,
    onDraft,
}: {
    readOnly?: boolean;
    ctx?: TitleContext;
    initial?: SlotDraft;
    onDraft?: (draft: SlotDraft) => void;
}) {
    const [draft, setDraft] = useState(initial);

    return (
        <StipulationPicker
            draft={draft}
            type={singles}
            context={ctx}
            roster={roster}
            readOnly={readOnly}
            onChange={(next) => {
                setDraft(next);
                onDraft?.(next);
            }}
        />
    );
}

const open = async (user: ReturnType<typeof userEvent.setup>) =>
    user.click(screen.getByRole('button', { name: 'Stipulation for match 1' }));

describe('StipulationPicker', () => {
    it('is a dropdown that reads Select stipulation and picks one', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        expect(screen.getByTestId('stipulation-1')).toHaveTextContent(
            'Select stipulation',
        );

        await open(user);
        await user.click(
            screen.getByRole('option', { name: /Loser Leaves Show/ }),
        );

        expect(screen.getByTestId('stipulation-1')).toHaveTextContent(
            '◆ Loser Leaves Show',
        );

        await open(user);
        await user.click(
            screen.getByRole('option', { name: 'No stipulation' }),
        );

        expect(screen.getByTestId('stipulation-1')).toHaveTextContent(
            'Select stipulation',
        );
    });

    it('asks which title a Champion vs. Contender is for, one title only', async () => {
        const user = userEvent.setup();
        const drafts: SlotDraft[] = [];
        render(<Harness onDraft={(d) => drafts.push(d)} />);

        await open(user);
        await user.click(
            screen.getByRole('option', { name: /Champion vs\. Contender/ }),
        );

        const dialog = screen.getByRole('dialog');
        const radios = within(dialog).getAllByRole('radio');
        expect(radios).toHaveLength(2);
        expect(dialog).toHaveTextContent('World Heavyweight Championship');
        expect(dialog).toHaveTextContent('Cruiserweight Championship');

        await user.click(radios[1]);
        await user.click(
            within(dialog).getByRole('button', { name: 'Confirm' }),
        );

        expect(drafts.at(-1)).toMatchObject({
            stipulation: 'champion_vs_contender',
            stipulationChampionshipId: 8,
        });
        expect(screen.getByTestId('stipulation-1')).toHaveTextContent(
            'Champion vs. Contender: Cruiserweight',
        );
    });

    it('offers no Champion vs. Contender with a title on the line', async () => {
        const user = userEvent.setup();
        render(<Harness initial={{ ...start, titleChampionshipId: 5 }} />);

        await open(user);

        expect(
            screen.queryByRole('option', { name: /Champion vs\. Contender/ }),
        ).toBeNull();
    });

    it('offers no Champion vs. Contender when the contender is a champion too', async () => {
        const user = userEvent.setup();
        const ctx = context({
            // In title order, as the server sends them.
            championships: [
                context().championships[0],
                {
                    ...title(6, 'United States Championship', [2]),
                    holder_name: 'Eugene',
                },
                context().championships[1],
            ],
            title_holder_ids: [1, 2],
        });
        render(<Harness ctx={ctx} />);

        await open(user);

        expect(
            screen.queryByRole('option', { name: /Champion vs\. Contender/ }),
        ).toBeNull();
        // Both are champions, so Winner Takes All is on, with all 3 titles.
        expect(
            screen.getByRole('option', { name: /Winner Takes All/ }),
        ).toHaveTextContent('World Heavyweight, United States, Cruiserweight');
    });

    it('shows read-only text when nothing is eligible', () => {
        render(
            <StipulationPicker
                draft={start}
                type={{ ...singles, allowed_stipulations: [] }}
                context={context()}
                roster={roster}
                readOnly={false}
                onChange={() => {}}
            />,
        );

        expect(screen.getByText('No stipulation eligible')).toBeInTheDocument();
    });
});
