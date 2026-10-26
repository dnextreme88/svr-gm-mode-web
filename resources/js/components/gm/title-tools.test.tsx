import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import { StipulationPicker } from '@/components/gm/title-tools';
import type { SlotDraft } from '@/lib/card';
import type { MatchTypeOption, RosterEntry, TitleContext } from '@/types/gm';

const singles = {
    id: 1,
    sides: [1, 1],
    titles_allowed: true,
} as MatchTypeOption;

const roster = new Map<number, RosterEntry>(
    [1, 2].map((id) => [
        id,
        {
            id,
            roster_type: 'superstar',
            weight_class: 'heavyweight',
        } as RosterEntry,
    ]),
);

const context: TitleContext = {
    championships: [],
    lockouts: {},
    last_chance: [],
    title_shots: [],
    briefcase: null,
};

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

function Harness({ readOnly = false }: { readOnly?: boolean }) {
    const [draft, setDraft] = useState(start);

    return (
        <StipulationPicker
            draft={draft}
            type={singles}
            context={context}
            roster={roster}
            readOnly={readOnly}
            onChange={setDraft}
        />
    );
}

describe('StipulationPicker', () => {
    it('cycles stipulations with arrows, like the title cycler', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        const chip = screen.getByTestId('stipulation-1');
        expect(
            screen.getByRole('group', { name: 'Stipulation for match 1' }),
        ).toBeInTheDocument();
        expect(chip).toHaveTextContent('Select stipulation');
        expect(chip.className).toContain('min-w-[250px]');

        await user.click(
            screen.getByRole('button', { name: 'Next stipulation' }),
        );
        expect(chip).toHaveTextContent('◆ Loser Leaves Show');
        expect(chip.className).toContain('from-gold-hot');

        await user.click(
            screen.getByRole('button', { name: 'Next stipulation' }),
        );
        expect(chip).toHaveTextContent('Select stipulation');

        await user.click(
            screen.getByRole('button', { name: 'Previous stipulation' }),
        );
        expect(chip).toHaveTextContent('◆ Loser Leaves Show');
    });

    it('disables the arrows when read-only', () => {
        render(<Harness readOnly />);

        expect(
            screen.getByRole('button', { name: 'Next stipulation' }),
        ).toBeDisabled();
    });

    it('only highlights the arrows on hover while they are enabled', () => {
        render(<Harness readOnly />);

        for (const name of ['Previous stipulation', 'Next stipulation']) {
            const arrow = screen.getByRole('button', { name });
            expect(arrow).toHaveClass('enabled:hover:text-ink');
            expect(arrow).not.toHaveClass('hover:text-ink');
        }
    });
});
