import { useState } from 'react';

/**
 * Which request a page is waiting on, for busy buttons (`BusyLabel`,
 * `aria-busy`). `track(key)` gives Inertia visit callbacks that mark `key`
 * busy from the start of the request until it finishes, success or not.
 */
export function useBusy() {
    const [busy, setBusy] = useState<string | null>(null);

    return {
        busy,
        isBusy: (key: string) => busy === key,
        track: (key: string) => ({
            onStart: () => setBusy(key),
            onFinish: () => setBusy(null),
        }),
    };
}
