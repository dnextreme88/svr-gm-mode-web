import { useEffect, useState } from 'react';
import { prefersReducedMotion } from '@/lib/gm';

/** How long the digits roll before the last one locks. */
export const ROLL_DURATION_MS = 900;
/** How often a rolling digit changes, so it reads as spinning, not noise. */
const TICK_MS = 45;

/**
 * One frame of the roll: every digit still rolling shows a cycling digit,
 * and the digits lock in from left to right (house rule, 2026-11-21: the
 * Financials cards roll like a slot machine before landing on the amount).
 * Signs, `$` and commas stay put. Deterministic, so no RNG is needed.
 */
export function rollFrame(target: string, elapsed: number): string {
    const digits = target.split('').filter((c) => c >= '0' && c <= '9').length;
    const tick = Math.floor(elapsed / TICK_MS);
    let seen = 0;

    return target
        .split('')
        .map((c, i) => {
            if (c < '0' || c > '9') {
                return c;
            }

            seen += 1;
            const lockAt = ROLL_DURATION_MS * (0.35 + (0.65 * seen) / digits);

            if (elapsed >= lockAt) {
                return c;
            }

            return String((Number(c) + tick * 7 + i * 3 + 1) % 10);
        })
        .join('');
}

/**
 * A formatted number that rolls into place whenever it changes. Screen
 * readers get the final value straight away; the roll is decoration.
 */
export function RollingNumber({ value }: { value: string }) {
    const [shown, setShown] = useState(() =>
        prefersReducedMotion() ? value : rollFrame(value, 0),
    );

    useEffect(() => {
        if (
            prefersReducedMotion() ||
            typeof requestAnimationFrame !== 'function'
        ) {
            setShown(value);

            return;
        }

        let frame = 0;
        const start = performance.now();
        const step = (now: number) => {
            const elapsed = now - start;

            if (elapsed >= ROLL_DURATION_MS) {
                setShown(value);

                return;
            }

            setShown(rollFrame(value, elapsed));
            frame = requestAnimationFrame(step);
        };

        frame = requestAnimationFrame(step);

        return () => cancelAnimationFrame(frame);
    }, [value]);

    return (
        <>
            <span aria-hidden data-testid="rolling-number">
                {shown}
            </span>
            <span className="sr-only">{value}</span>
        </>
    );
}
