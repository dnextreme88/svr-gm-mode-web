import {
    closestCenter,
    DndContext,
    KeyboardSensor,
    pointerWithin,
    PointerSensor,
    TouchSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { CollisionDetection, DragEndEvent } from '@dnd-kit/core';
import {
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { ChevronDown, ChevronUp, GripVertical } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The new order of a card's matches after one moves, as the old positions
 * in their new order. Fixed slots (a cash-in, another brand's match) stay
 * where they are: only the movable ones trade places around them.
 */
export function reorderPositions(
    positions: number[],
    movable: (position: number) => boolean,
    from: number,
    to: number,
): number[] {
    const free = positions.filter(movable);
    const moved = arrayMove(free, free.indexOf(from), free.indexOf(to));
    let next = 0;

    return positions.map((p) => (movable(p) ? moved[next++] : p));
}

/**
 * The match a drag lands on: the one under the pointer (2026-11-15). Match
 * cards are tall and the handle sits at the top, so the dragged card's
 * centre (dnd-kit's default) trails far below the pointer: dropping Match 5's
 * handle on Match 3's header landed it in another spot. Between cards (or
 * with the keyboard) the closest centre decides.
 */
export const dropTarget: CollisionDetection = (args) => {
    const under = pointerWithin(args);

    return under.length > 0 ? under : closestCenter(args);
};

export type SlotHandle = {
    /** The drag handle and the move up / move down buttons for the slot's header. */
    handle: ReactNode;
    dragging: boolean;
};

/**
 * Drag to reorder a card's matches (house rule, 2026-11-15), built on
 * dnd-kit: a pointer, touch or keyboard drag (Space to pick up, arrows to
 * move, Space to drop) from each slot's grip, plus Move up / Move down
 * buttons. Fixed slots have neither and act as walls.
 */
export function SortableSlots({
    positions,
    movable,
    disabled,
    onReorder,
    children,
}: {
    positions: number[];
    movable: (position: number) => boolean;
    /** Why reordering is off right now (unsaved changes), or null. */
    disabled: string | null;
    onReorder: (order: number[]) => void;
    children: (position: number, handle: SlotHandle | null) => ReactNode;
}) {
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(TouchSensor, {
            activationConstraint: { delay: 180, tolerance: 6 },
        }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );
    const free = positions.filter(movable);

    const move = (from: number, to: number) => {
        if (from !== to) {
            onReorder(reorderPositions(positions, movable, from, to));
        }
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        if (over && active.id !== over.id) {
            move(Number(active.id), Number(over.id));
        }
    };

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={dropTarget}
            // Scroll only close to the window's edge, and gently: by default
            // the top fifth of the window scrolled fast, so holding a card over
            // the match you wanted kept scrolling past it (2026-11-15).
            autoScroll={{
                threshold: { x: 0, y: 0.08 },
                acceleration: 4,
            }}
            onDragEnd={onDragEnd}
            accessibility={{
                screenReaderInstructions: {
                    draggable:
                        'To move a match, press Space to pick it up, the arrow keys to move it, and Space again to drop it. Escape cancels.',
                },
                announcements: {
                    onDragStart: ({ active }) =>
                        `Picked up match ${active.id}.`,
                    onDragOver: ({ active, over }) =>
                        over
                            ? `Match ${active.id} is over match ${over.id}.`
                            : `Match ${active.id} isn't over a match.`,
                    onDragEnd: ({ active, over }) =>
                        over
                            ? `Match ${active.id} moved to spot ${over.id}.`
                            : `Match ${active.id} dropped.`,
                    onDragCancel: ({ active }) =>
                        `Moving match ${active.id} was cancelled.`,
                },
            }}
        >
            <SortableContext
                items={free.map(String)}
                strategy={verticalListSortingStrategy}
                disabled={disabled !== null}
            >
                {positions.map((position) =>
                    movable(position) ? (
                        <SortableSlot
                            key={position}
                            position={position}
                            index={free.indexOf(position)}
                            count={free.length}
                            disabled={disabled}
                            onMove={(delta) =>
                                move(
                                    position,
                                    free[free.indexOf(position) + delta],
                                )
                            }
                        >
                            {(handle) => children(position, handle)}
                        </SortableSlot>
                    ) : (
                        <div key={position}>{children(position, null)}</div>
                    ),
                )}
            </SortableContext>
        </DndContext>
    );
}

function SortableSlot({
    position,
    index,
    count,
    disabled,
    onMove,
    children,
}: {
    position: number;
    index: number;
    count: number;
    disabled: string | null;
    onMove: (delta: -1 | 1) => void;
    children: (handle: SlotHandle) => ReactNode;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        setActivatorNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: String(position), disabled: disabled !== null });
    const off = disabled !== null;
    const button =
        'inline-flex size-11 items-center justify-center text-ink-dim hover:bg-white/[0.06] hover:text-ink disabled:cursor-not-allowed disabled:opacity-35 sm:size-8';

    const handle = (
        <div
            className="flex items-center"
            role="group"
            aria-label={`Move match ${position}`}
        >
            <button
                type="button"
                ref={setActivatorNodeRef}
                {...attributes}
                {...listeners}
                aria-label={`Drag match ${position} to reorder`}
                aria-disabled={off || undefined}
                title={disabled ?? 'Drag to reorder'}
                className={cn(
                    button,
                    'touch-none',
                    off
                        ? 'cursor-not-allowed opacity-35'
                        : 'cursor-grab active:cursor-grabbing',
                )}
            >
                <GripVertical aria-hidden className="size-4" />
            </button>
            <button
                type="button"
                aria-label={`Move match ${position} up`}
                disabled={off || index <= 0}
                onClick={() => onMove(-1)}
                className={button}
            >
                <ChevronUp aria-hidden className="size-4" />
            </button>
            <button
                type="button"
                aria-label={`Move match ${position} down`}
                disabled={off || index >= count - 1}
                onClick={() => onMove(1)}
                className={button}
            >
                <ChevronDown aria-hidden className="size-4" />
            </button>
        </div>
    );

    return (
        <div
            ref={setNodeRef}
            style={{
                transform: CSS.Translate.toString(transform),
                transition,
            }}
            className={cn(
                'relative motion-reduce:transition-none',
                isDragging &&
                    'z-30 shadow-[0_0_0_2px_var(--color-brand),0_18px_40px_rgb(0_0_0/0.55)]',
            )}
        >
            {children({ handle, dragging: isDragging })}
        </div>
    );
}
