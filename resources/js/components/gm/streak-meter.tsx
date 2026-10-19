import { cn } from '@/lib/utils';

/** The meter caps at this many bars; the label keeps the full count. */
export const STREAK_BARS = 10;

/**
 * Win or loss streak as a bar meter, styled like the fatigue meter: one
 * bar per match, green for wins and red for losses.
 */
export function StreakMeter({
    wins,
    losses,
    size = 'md',
}: {
    wins: number;
    losses: number;
    size?: 'sm' | 'md';
}) {
    const winning = wins > 0;
    const count = winning ? wins : losses;

    if (count === 0) {
        return (
            <span className="gm-num text-sm text-ink-faint">
                {size === 'sm' ? '—' : 'No streak'}
            </span>
        );
    }

    const filled = Math.min(count, STREAK_BARS);
    const label = `${count} ${winning ? 'winning' : 'losing'} streak`;

    return (
        <div className="flex items-center gap-2">
            <div
                role="meter"
                aria-label={winning ? 'Winning streak' : 'Losing streak'}
                aria-valuemin={0}
                aria-valuemax={STREAK_BARS}
                aria-valuenow={filled}
                aria-valuetext={label}
                className={cn(
                    'flex',
                    size === 'sm' ? 'h-2.5 gap-[2px]' : 'h-4 gap-[3px]',
                )}
            >
                {Array.from({ length: STREAK_BARS }, (_, i) => (
                    <span
                        key={i}
                        data-state={i < filled ? 'filled' : 'empty'}
                        className={cn(
                            size === 'sm' ? 'w-1.5' : 'w-2.5',
                            '-skew-x-12',
                            i < filled
                                ? winning
                                    ? 'bg-zone-safe'
                                    : 'bg-zone-serious'
                                : 'bg-white/8',
                        )}
                    />
                ))}
            </div>
            <span
                className={cn(
                    'gm-num text-xs tracking-wider whitespace-nowrap uppercase',
                    winning ? 'text-zone-safe' : 'text-zone-serious',
                )}
            >
                {label}
            </span>
        </div>
    );
}
