import type { ReactNode } from 'react';
import { Spinner } from '@/components/ui/spinner';

/**
 * A button's label that says it's working: a spinner and `busyText`
 * ("Simulating…") while its request is in flight, like Air week's "On air…".
 * Give the button `aria-busy` too.
 */
export function BusyLabel({
    busy,
    busyText,
    children,
}: {
    busy: boolean;
    busyText: string;
    children: ReactNode;
}) {
    if (!busy) {
        return <>{children}</>;
    }

    return (
        <>
            <Spinner
                aria-hidden
                className="size-4 motion-reduce:animate-none"
            />
            {busyText}
        </>
    );
}
