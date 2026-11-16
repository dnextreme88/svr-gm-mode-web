import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import {
    dropTarget,
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

describe('dropTarget (2026-11-15)', () => {
    const rect = (top: number, height = 400) => ({
        top,
        bottom: top + height,
        left: 0,
        right: 800,
        width: 800,
        height,
    });
    const containers = ['3', '4', '5'].map((id) => ({
        id,
        key: id,
        data: { current: {} },
        disabled: false,
        node: { current: null },
        rect: { current: null },
    }));
    const rects = new Map([
        ['3', rect(0)],
        ['4', rect(400)],
        ['5', rect(800)],
    ]);

    it('lands on the match under the pointer, not the dragged card’s centre', () => {
        // Match 5 held by its handle over Match 3's header: the card's centre
        // is down by Match 4, but the pointer is on Match 3.
        const hits = dropTarget({
            active: containers[2],
            collisionRect: rect(20),
            droppableRects: rects,
            droppableContainers: containers,
            pointerCoordinates: { x: 100, y: 25 },
        } as unknown as Parameters<typeof dropTarget>[0]);

        expect(hits[0]?.id).toBe('3');
    });

    it('falls back to the closest centre between cards or from the keyboard', () => {
        const hits = dropTarget({
            active: containers[2],
            collisionRect: rect(380),
            droppableRects: rects,
            droppableContainers: containers,
            pointerCoordinates: null,
        } as unknown as Parameters<typeof dropTarget>[0]);

        expect(hits[0]?.id).toBe('4');
    });
});
