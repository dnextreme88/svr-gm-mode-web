import { useEffect } from 'react';
import type { RefObject } from 'react';

const FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Focus handling for the GM's own modals (the proposal modal, the reels;
 * the Radix dialogs do this themselves) (UI audit, 2026-11-20): focus moves
 * into the dialog when it opens, Tab and Shift+Tab stay inside it, the page
 * behind doesn't scroll, and focus goes back where it was when it closes.
 */
export function useModalFocus(
    open: boolean,
    dialog: RefObject<HTMLElement | null>,
): void {
    useEffect(() => {
        if (!open) {
            return;
        }

        const before =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        if (!dialog.current?.contains(document.activeElement)) {
            dialog.current?.focus();
        }

        const onKey = (e: KeyboardEvent) => {
            const el = dialog.current;

            if (e.key !== 'Tab' || !el) {
                return;
            }

            const items = [...el.querySelectorAll<HTMLElement>(FOCUSABLE)];

            if (items.length === 0) {
                e.preventDefault();
                el.focus();

                return;
            }

            const first = items[0];
            const last = items[items.length - 1];
            const active = document.activeElement;

            if (e.shiftKey && (active === first || active === el)) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && active === last) {
                e.preventDefault();
                first.focus();
            } else if (!el.contains(active)) {
                e.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = overflow;

            if (before?.isConnected) {
                before.focus();
            }
        };
    }, [open, dialog]);
}
