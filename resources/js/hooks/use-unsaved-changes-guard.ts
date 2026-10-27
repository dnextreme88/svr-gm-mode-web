import { router } from '@inertiajs/react';
import type { PendingVisit } from '@inertiajs/core';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Holds back navigation away from a page with unsaved changes.
 *
 * - An Inertia visit to another page (a nav link, a button) is cancelled
 *   and kept in `pending`, so the page can ask first: `proceed()` makes the
 *   visit anyway, `stay()` drops it. Visits to the page's own URLs (saving,
 *   reloading) and prefetches pass through.
 * - A refresh or closing the tab gets the browser's own "Leave site?"
 *   prompt; browsers don't let a page show its own text there.
 */
export function useUnsavedChangesGuard(active: boolean) {
    const [pending, setPending] = useState<PendingVisit | null>(null);
    const leaving = useRef(false);

    useEffect(() => {
        if (!active) {
            return;
        }

        const onBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            // Older browsers need returnValue set to show the prompt.
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', onBeforeUnload);

        const off = router.on('before', (event) => {
            const visit = event.detail.visit;

            if (leaving.current || visit.prefetch || staysOnPage(visit)) {
                return;
            }

            setPending(visit);

            return false;
        });

        return () => {
            window.removeEventListener('beforeunload', onBeforeUnload);
            off();
        };
    }, [active]);

    const proceed = useCallback(() => {
        if (!pending) {
            return;
        }

        leaving.current = true;
        setPending(null);
        router.visit(pending.url, {
            method: pending.method,
            data: pending.data,
            headers: pending.headers,
            preserveScroll: pending.preserveScroll,
            preserveState: pending.preserveState,
            replace: pending.replace,
            onFinish: () => {
                leaving.current = false;
            },
        });
    }, [pending]);

    const stay = useCallback(() => setPending(null), []);

    return { pending, proceed, stay };
}

/**
 * A visit to this page's own URL or one under it (saving a slot, reloading
 * props) doesn't leave the page.
 */
function staysOnPage(visit: PendingVisit): boolean {
    const here = window.location.pathname.replace(/\/$/, '');
    const there = visit.url.pathname.replace(/\/$/, '');

    return there === here || (here !== '' && there.startsWith(`${here}/`));
}
