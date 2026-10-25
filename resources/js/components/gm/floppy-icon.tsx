import type { SaveStatus } from '@/lib/save-status';
import { cn } from '@/lib/utils';

/**
 * A blue floppy disk, shown beside Save. The white outline keeps it visible
 * on SmackDown's blue buttons. While `saving` the shutter slides and the
 * label blinks; once `saved` a green check pops in.
 */
export function FloppyIcon({
    status = null,
    className,
}: {
    status?: SaveStatus;
    className?: string;
}) {
    const saving = status === 'saving';

    return (
        <svg
            viewBox="0 0 16 16"
            aria-hidden="true"
            data-testid="floppy-icon"
            data-status={status ?? 'idle'}
            className={cn('size-3.5 shrink-0 overflow-visible', className)}
        >
            <path
                d="M2 1.5h9.5L14.5 4.5v9a1 1 0 0 1-1 1h-11a1 1 0 0 1-1-1v-11a1 1 0 0 1 .5-1z"
                fill="#74a4ff"
                stroke="#fff"
                strokeWidth="1"
                strokeLinejoin="round"
            />
            <g className={cn(saving && 'animate-gm-floppy-write')}>
                <rect x="4" y="1.5" width="6.5" height="4" fill="#0b1a3a" />
                <rect x="8.5" y="2.2" width="1.3" height="2.6" fill="#74a4ff" />
            </g>
            <rect
                x="3.5"
                y="8.5"
                width="9"
                height="5"
                rx="0.5"
                fill="#fff"
                className={cn(saving && 'animate-gm-floppy-blink')}
            />
            {status === 'saved' && (
                <g
                    data-testid="floppy-check"
                    className="animate-gm-check-pop"
                    style={{
                        transformBox: 'fill-box',
                        transformOrigin: 'center',
                    }}
                >
                    <circle
                        cx="13"
                        cy="13"
                        r="3.6"
                        fill="var(--color-zone-safe)"
                        stroke="#06070a"
                        strokeWidth="1"
                    />
                    <path
                        d="M11.4 13.1l1.1 1.1 2-2.2"
                        fill="none"
                        stroke="#fff"
                        strokeWidth="1.2"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    />
                </g>
            )}
        </svg>
    );
}
