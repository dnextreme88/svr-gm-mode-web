import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import { WrestlerPicker } from '@/components/gm/wrestler-picker';
import type { PickerOption } from '@/components/gm/wrestler-picker';

const options: PickerOption[] = ['Batista', 'Edge', 'Eugene', 'Kane'].map(
    (name, i) => ({
        wrestler: {
            id: i + 1,
            name,
            roster_type: 'superstar',
            weight_class: 'heavyweight',
        },
        disabled: name === 'Eugene',
    }),
);

function Harness() {
    const [value, setValue] = useState<number | null>(2);

    return (
        <WrestlerPicker
            label="Match 1 side 1 superstar 1"
            value={value}
            options={options}
            onChange={setValue}
        />
    );
}

describe('WrestlerPicker keyboard and names (UI audit, 2026-11-20)', () => {
    it('reads its label, then who is picked', () => {
        render(<Harness />);

        expect(
            screen.getByRole('button', {
                name: 'Match 1 side 1 superstar 1 Edge',
            }),
        ).toBeInTheDocument();
    });

    it('moves through the list with the arrow keys and a letter, skipping disabled rows', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        const button = screen.getByRole('button', { name: /^Match 1 side 1/ });
        button.focus();
        await user.keyboard('{Enter}');

        // Focus lands on the picked option.
        expect(screen.getByRole('option', { name: 'Edge' })).toHaveFocus();

        // Down skips Eugene (disabled) to Kane.
        await user.keyboard('{ArrowDown}');
        expect(screen.getByRole('option', { name: 'Kane' })).toHaveFocus();

        await user.keyboard('b');
        expect(screen.getByRole('option', { name: 'Batista' })).toHaveFocus();

        await user.keyboard('{End}');
        expect(screen.getByRole('option', { name: 'Kane' })).toHaveFocus();

        await user.keyboard('{Enter}');
        expect(
            screen.getByRole('button', {
                name: 'Match 1 side 1 superstar 1 Kane',
            }),
        ).toHaveFocus();
    });

    it('hands focus back to its button on Escape', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        const button = screen.getByRole('button', { name: /^Match 1 side 1/ });
        button.focus();
        await user.keyboard('{Enter}');
        await user.keyboard('{Escape}');

        expect(button).toHaveFocus();
    });
});
