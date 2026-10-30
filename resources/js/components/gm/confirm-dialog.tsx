import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import type { GameShell } from '@/types/gm';

/**
 * GM-styled replacement for `window.confirm`. The dialog renders in a
 * portal outside `.gm-root`, so it re-applies the player's brand tint.
 */
export function ConfirmDialog({
    open,
    title,
    message,
    confirmLabel = 'Yes',
    cancelLabel = 'Cancel',
    onConfirm,
    onCancel,
    danger = false,
}: {
    open: boolean;
    title: string;
    message: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    onConfirm: () => void;
    onCancel: () => void;
    /** Style the confirm button red, for destructive actions. */
    danger?: boolean;
}) {
    const brand = usePage<{ game?: GameShell }>().props.game?.player_brand;

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onCancel()}>
            <DialogContent
                data-brand-tint={brand ?? 'raw'}
                className="gm-panel rounded-none border-white/10 bg-arena-2 font-gm text-ink"
            >
                <DialogTitle className="gm-heading text-2xl">
                    {title}
                </DialogTitle>
                <DialogDescription className="text-ink-dim">
                    {message}
                </DialogDescription>
                <DialogFooter className="gap-2">
                    <button
                        type="button"
                        className="gm-btn gm-btn-ghost gm-btn-sm"
                        onClick={onCancel}
                    >
                        {cancelLabel}
                    </button>
                    <button
                        type="button"
                        className={
                            danger
                                ? 'gm-btn gm-btn-danger gm-btn-sm'
                                : 'gm-btn gm-btn-sm'
                        }
                        onClick={onConfirm}
                        autoFocus
                    >
                        {confirmLabel}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
