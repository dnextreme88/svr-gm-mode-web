import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { useBusy } from '@/lib/use-busy';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';

/**
 * Release a superstar to Free Agency, after a GM-styled confirmation that
 * waits (busy) until the release has gone through.
 */
export function ReleaseButton({
    saveId,
    wrestlerId,
    name,
    message,
    className,
}: {
    saveId: number;
    wrestlerId: number;
    name: string;
    /** Defaults to what a release does. */
    message?: string;
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const { busy, track } = useBusy();

    return (
        <>
            <button
                type="button"
                className={cn('gm-btn gm-btn-ghost gm-btn-sm', className)}
                onClick={() => setOpen(true)}
                aria-label={`Release ${name}`}
            >
                Release
            </button>
            <ConfirmDialog
                open={open}
                danger
                focusCancel
                title={`Release ${name}?`}
                message={
                    message ??
                    `${name} goes to Free Agency, where any brand can sign them. Any title they hold will be vacated.`
                }
                confirmLabel="Release"
                busy={busy !== null}
                busyText="Releasing…"
                onCancel={() => setOpen(false)}
                onConfirm={() =>
                    router.post(
                        gm.contracts.release.url({
                            save: saveId,
                            saveWrestler: wrestlerId,
                        }),
                        {},
                        {
                            preserveScroll: true,
                            ...track('release'),
                            onSuccess: () => setOpen(false),
                        },
                    )
                }
            />
        </>
    );
}
