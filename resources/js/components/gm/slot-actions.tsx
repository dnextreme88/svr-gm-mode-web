import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { FloppyIcon } from '@/components/gm/floppy-icon';
import type { SaveStatus } from '@/lib/save-status';
import { cn } from '@/lib/utils';

/** The orange "Unsaved changes" label on a slot with edits not yet saved. */
export function UnsavedBadge({ className }: { className?: string }) {
    return (
        <span
            className={cn(
                'font-condensed text-sm font-semibold tracking-wider text-gm-orange uppercase',
                className,
            )}
        >
            Unsaved changes
        </span>
    );
}

type Props = {
    /** The slot as the buttons name it: "match 2", "promo 1". */
    name: string;
    /** Save, Discard and Clear show only when the slot can be edited. */
    editable?: boolean;
    dirty: boolean;
    /** Everything the slot needs is filled in, so it can be saved. */
    ready: boolean;
    /** Something is saved in the slot, so there's something to clear. */
    filled: boolean;
    saveStatus: SaveStatus;
    /** What Clear removes, for its confirmation. */
    clearMessage: string;
    onSave: () => void;
    onDiscard: () => void;
    onClear: () => void;
    className?: string;
    /** Extra controls after Clear (stipulation, Randomize). */
    children?: ReactNode;
};

/**
 * A card slot's action row, shared by matches and promos: Save (with the
 * floppy save status), Discard back to what's saved, Clear behind a danger
 * confirmation, any extra controls, and "Unsaved changes" while edited.
 */
export function SlotActions({
    name,
    editable = true,
    dirty,
    ready,
    filled,
    saveStatus,
    clearMessage,
    onSave,
    onDiscard,
    onClear,
    className,
    children,
}: Props) {
    const [confirmClear, setConfirmClear] = useState(false);

    return (
        <div className={cn('flex flex-wrap items-center', className)}>
            {editable && (
                <>
                    <button
                        type="button"
                        className="gm-btn gm-btn-sm"
                        disabled={!ready || !dirty}
                        onClick={onSave}
                    >
                        <FloppyIcon status={saveStatus} />
                        Save {name}
                    </button>
                    <button
                        type="button"
                        className="gm-btn gm-btn-ghost gm-btn-sm"
                        disabled={!dirty}
                        onClick={onDiscard}
                        aria-label={`Discard changes to ${name}`}
                    >
                        Discard
                    </button>
                    <button
                        type="button"
                        className="gm-btn gm-btn-danger gm-btn-sm"
                        disabled={!filled}
                        onClick={() => setConfirmClear(true)}
                        aria-label={`Clear ${name}`}
                    >
                        Clear
                    </button>
                </>
            )}
            {children}
            {editable && dirty && <UnsavedBadge className="ml-auto" />}

            <ConfirmDialog
                open={confirmClear}
                title={`Clear ${name}?`}
                message={clearMessage}
                confirmLabel="Yes, clear it"
                danger
                onCancel={() => setConfirmClear(false)}
                onConfirm={() => {
                    setConfirmClear(false);
                    onClear();
                }}
            />
        </div>
    );
}
