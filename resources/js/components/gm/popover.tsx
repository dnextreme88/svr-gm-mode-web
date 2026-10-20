import { useCallback, useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

/** How long the fade-out plays before the panel unmounts (matches gm-pop-out). */
const CLOSE_MS = 160;

/**
 * Open/close state for a picker panel: fades in on open, fades out on close,
 * and closes on Escape or a click outside `ref`.
 */
export function usePopover() {
    const [open, setOpen] = useState(false);
    const [mounted, setMounted] = useState(false);
    const ref = useRef<HTMLDivElement>(null);

    const close = useCallback(() => setOpen(false), []);
    const show = useCallback(() => {
        setMounted(true);
        setOpen(true);
    }, []);
    const toggle = useCallback(
        () => (open ? close() : show()),
        [open, close, show],
    );

    // Keep the panel mounted while it fades out.
    useEffect(() => {
        if (open || !mounted) {
            return;
        }

        const timer = setTimeout(() => setMounted(false), CLOSE_MS);

        return () => clearTimeout(timer);
    }, [open, mounted]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (e: PointerEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        const onKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    return { open, mounted, ref, toggle, close };
}

/**
 * The floating panel under a picker button. While it fades out it's hidden
 * from assistive tech and ignores clicks.
 */
export function PopoverPanel({
    open,
    className,
    children,
    ...props
}: React.HTMLAttributes<HTMLDivElement> & { open: boolean }) {
    return (
        <div
            data-state={open ? 'open' : 'closed'}
            aria-hidden={open ? undefined : true}
            className={cn(
                'gm-panel absolute left-0 z-40 mt-1 bg-arena-2 shadow-[0_18px_40px_-12px_rgb(0_0_0/0.9)]',
                open
                    ? 'animate-gm-pop-in'
                    : 'pointer-events-none animate-gm-pop-out',
                className,
            )}
            {...props}
        >
            {children}
        </div>
    );
}
