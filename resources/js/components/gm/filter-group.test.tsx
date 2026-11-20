import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import { FilterGroup } from '@/components/gm/filter-group';

function Harness() {
    const [value, setValue] = useState<'all' | 'unread' | 'read'>('unread');

    return (
        <FilterGroup
            label="Show"
            value={value}
            onChange={setValue}
            options={[
                { value: 'all', label: 'All' },
                { value: 'unread', label: 'Unread' },
                { value: 'read', label: 'Read' },
            ]}
        />
    );
}

describe('FilterGroup radio pattern (UI audit, 2026-11-20)', () => {
    it('is one Tab stop, on the checked radio', () => {
        render(<Harness />);

        expect(screen.getByRole('radio', { name: 'Unread' })).toHaveAttribute(
            'tabindex',
            '0',
        );
        expect(screen.getByRole('radio', { name: 'All' })).toHaveAttribute(
            'tabindex',
            '-1',
        );
    });

    it('moves and selects with the arrow keys, wrapping', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        screen.getByRole('radio', { name: 'Unread' }).focus();
        await user.keyboard('{ArrowRight}');

        const read = screen.getByRole('radio', { name: 'Read' });
        expect(read).toHaveFocus();
        expect(read).toHaveAttribute('aria-checked', 'true');
        expect(read).toHaveAttribute('tabindex', '0');

        await user.keyboard('{ArrowRight}');
        expect(screen.getByRole('radio', { name: 'All' })).toHaveAttribute(
            'aria-checked',
            'true',
        );

        await user.keyboard('{End}');
        expect(read).toHaveAttribute('aria-checked', 'true');
    });
});
