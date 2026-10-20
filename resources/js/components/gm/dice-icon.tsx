import { cn } from '@/lib/utils';

/** A black-and-white die, shown beside Randomize. */
export function DiceIcon({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 16 16"
            aria-hidden="true"
            data-testid="dice-icon"
            className={cn('size-3.5 shrink-0', className)}
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
            {[
                [5, 5],
                [11, 5],
                [8, 8],
                [5, 11],
                [11, 11],
            ].map(([cx, cy]) => (
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
