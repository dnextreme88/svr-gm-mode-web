import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';

export type DiceFace = 1 | 2 | 3 | 4 | 5 | 6;

const PIPS: Record<DiceFace, [number, number][]> = {
    1: [[8, 8]],
    2: [
        [5, 5],
        [11, 11],
    ],
    3: [
        [5, 5],
        [8, 8],
        [11, 11],
    ],
    4: [
        [5, 5],
        [11, 5],
        [5, 11],
        [11, 11],
    ],
    5: [
        [5, 5],
        [11, 5],
        [8, 8],
        [5, 11],
        [11, 11],
    ],
    6: [
        [5, 4.5],
        [11, 4.5],
        [5, 8],
        [11, 8],
        [5, 11.5],
        [11, 11.5],
    ],
};

/** Faces shown while tumbling: a fixed order, so no RNG is needed. */
const TUMBLE: DiceFace[] = [1, 4, 2, 6, 3, 5];
const TUMBLE_MS = 90;

function prefersReducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/**
 * A black-and-white die for Randomize, Re-roll and Preset. While `rolling`
 * it tumbles through its faces, then lands on `face`.
 */
export function Dice({
    face = 5,
    rolling = false,
    className,
}: {
    face?: DiceFace;
    rolling?: boolean;
    className?: string;
}) {
    const [step, setStep] = useState<number | null>(null);

    useEffect(() => {
        if (!rolling || prefersReducedMotion()) {
            setStep(null);

            return;
        }

        setStep(0);
        const timer = window.setInterval(
            () => setStep((s) => ((s ?? 0) + 1) % TUMBLE.length),
            TUMBLE_MS,
        );

        return () => window.clearInterval(timer);
    }, [rolling]);

    const shown = rolling && step !== null ? TUMBLE[step] : face;

    return (
        <svg
            viewBox="0 0 16 16"
            aria-hidden="true"
            data-testid="dice-icon"
            data-face={shown}
            className={cn(
                'size-3.5 shrink-0',
                rolling && 'animate-gm-dice-tumble',
                className,
            )}
        >
            <rect
                x="1"
                y="1"
                width="14"
                height="14"
                rx="3"
                fill="#fff"
                stroke="#000"
                strokeWidth="1.5"
            />
            {PIPS[shown].map(([cx, cy]) => (
                <circle
                    key={`${cx}-${cy}`}
                    cx={cx}
                    cy={cy}
                    r="1.4"
                    fill="#000"
                />
            ))}
        </svg>
    );
}
