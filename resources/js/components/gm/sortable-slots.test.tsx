import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import {
    reorderPositions,
    SortableSlots,
} from '@/components/gm/sortable-slots';

describe('reorderPositions (house rule, 2026-11-15)', () => {
    it('moves a match and shifts the others', () => {
        expect(reorderPositions([1, 2, 3, 4], () => true, 2, 4)).toEqual([
            1, 3, 4, 2,
        ]);
    });

    it('keeps fixed slots where they are', () => {
        const movable = (p: number) => p !== 3;

        expect(reorderPositions([1, 2, 3, 4], movable, 1, 4)).toEqual([
            2, 4, 3, 1,
        ]);
    });
});

describe('SortableSlots', () => {
    const renderSlots = (disabled: string | null = null) => {
        const onReorder = vi.fn();
        render(
            <SortableSlots
                positions={[1, 2, 3]}
                movable={(p) => p !== 2}
                disabled={disabled}
                onReorder={onReorder}
            >
                {(position, handle) => (
                    <div data-testid={`slot-${position}`}>
                        {handle?.handle}
                        Match {position}
                    </div>
                )}
            </SortableSlots>,
        );

        return onReorder;
    };

    it('moves a match down with its button, around a fixed slot', async () => {
        const user = userEvent.setup();
        const onReorder = renderSlots();

        await user.click(
            screen.getByRole('button', { name: 'Move match 1 down' }),
        );

        expect(onReorder).toHaveBeenCalledWith([3, 2, 1]);
    });

    it('gives a fixed slot no handle', () => {
        renderSlots();

        expect(
            screen.queryByRole('button', { name: /Drag match 2/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Move match 1 up' }),
        ).toBeDisabled();
    });

    it('locks the buttons while there are unsaved changes', () => {
        renderSlots('Save or discard your changes to move matches around.');

        expect(
            screen.getByRole('button', { name: 'Move match 1 down' }),
        ).toBeDisabled();
    });
});
