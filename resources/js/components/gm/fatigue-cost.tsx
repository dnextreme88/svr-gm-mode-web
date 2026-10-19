import { cn } from '@/lib/utils';

/**
 * A match type's fatigue cost as bars, in the fatigue meter's style: one
 * orange bar per bar of fatigue the match adds.
 */
export function FatigueCost({
    bars,
    max = 5,
    className,
}: {
    bars: number;
    /** Bars drawn in total, so costs line up in a column. */
    max?: number;
    className?: string;
}) {
    return (
        <span
            role="img"
            aria-label={`Adds ${bars} fatigue bar${bars === 1 ? '' : 's'}`}
            title={`Adds ${bars} fatigue bar${bars === 1 ? '' : 's'}`}
            className={cn(
                'inline-flex h-3.5 gap-[3px] align-middle',
                className,
            )}
        >
            {Array.from({ length: Math.max(max, bars) }, (_, i) => (
                <span
                    key={i}
                    data-state={i < bars ? 'filled' : 'empty'}
                    className={cn(
                        'w-2 -skew-x-12',
                        i < bars ? 'bg-gm-orange' : 'bg-white/8',
                    )}
                />
            ))}
        </span>
    );
}
