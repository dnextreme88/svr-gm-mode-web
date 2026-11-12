import type { ReactNode } from 'react';
import { BusyLabel } from '@/components/gm/busy-label';
import { useBusy } from '@/lib/use-busy';

type Track = { onStart: () => void; onFinish: () => void };

/**
 * A button that sends one Inertia request and says so while it's in flight:
 * a spinner and `busyText`, `aria-busy`, and no second click. `onAction`
 * gets the visit callbacks to spread into `router.post(…, …, { ...track })`.
 */
export function ActionButton({
    onAction,
    busyText,
    className = 'gm-btn',
    disabled = false,
    label,
    children,
}: {
    onAction: (track: Track) => void;
    busyText: string;
    className?: string;
    disabled?: boolean;
    /** An accessible name when the visible label isn't enough. */
    label?: string;
    children: ReactNode;
}) {
    const { busy, track } = useBusy();

    return (
        <button
            type="button"
            className={className}
            disabled={disabled || busy !== null}
            aria-busy={busy !== null || undefined}
            aria-label={label}
            onClick={() => onAction(track('action'))}
        >
            <BusyLabel busy={busy !== null} busyText={busyText}>
                {children}
            </BusyLabel>
        </button>
    );
}
