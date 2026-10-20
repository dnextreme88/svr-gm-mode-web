import { cn } from '@/lib/utils';

/**
 * A blue floppy disk, shown beside Save. The white outline keeps it visible
 * on SmackDown's blue buttons.
 */
export function FloppyIcon({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 16 16"
            aria-hidden="true"
            data-testid="floppy-icon"
            className={cn('size-3.5 shrink-0', className)}
        >
            <path
                d="M2 1.5h9.5L14.5 4.5v9a1 1 0 0 1-1 1h-11a1 1 0 0 1-1-1v-11a1 1 0 0 1 .5-1z"
                fill="#74a4ff"
                stroke="#fff"
                strokeWidth="1"
                strokeLinejoin="round"
            />
            <rect x="4" y="1.5" width="6.5" height="4" fill="#0b1a3a" />
            <rect x="8.5" y="2.2" width="1.3" height="2.6" fill="#74a4ff" />
            <rect x="3.5" y="8.5" width="9" height="5" rx="0.5" fill="#fff" />
        </svg>
    );
}
