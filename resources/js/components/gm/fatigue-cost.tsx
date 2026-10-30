import { cn } from '@/lib/utils';

/**
 * A fatigue cost as bars, in the fatigue meter's style: one orange bar per
 * bar of fatigue added, or one green bar per bar recovered.
 */
export function FatigueCost({
    bars,
    max = 5,
    recovers = false,
    className,
}: {
    bars: number;
    /** Bars drawn in total, so costs line up in a column. */
    max?: number;
    /** Draw recovered bars (green) instead of added fatigue (orange). */
    recovers?: boolean;
    className?: string;
}) {
    const label = recovers
        ? `Recovers ${bars} fatigue bar${bars === 1 ? '' : 's'}`
        : `Adds ${bars} fatigue bar${bars === 1 ? '' : 's'}`;

    return (
        <span
            role="img"
            aria-label={label}
            title={label}
            className={cn(
                'ml-2 inline-flex h-3.5 gap-[3px] align-middle',
                className,
            )}
        >
            {Array.from({ length: Math.max(max, bars) }, (_, i) => (
                <span
                    key={i}
                    data-state={i < bars ? 'filled' : 'empty'}
                    className={cn(
                        'w-2 -skew-x-12',
                        i < bars
                            ? recovers
                                ? 'bg-zone-safe'
                                : 'bg-gm-orange'
                            : 'bg-white/8',
                    )}
                />
            ))}
        </span>
    );
}
