import type { ReactNode } from 'react';
import { useRef } from 'react';
import { radioKeyDown, useRovingRadios } from '@/lib/radio-group';
import { cn } from '@/lib/utils';

/** A row of toggle buttons for one filter: a radio group. */
export function FilterGroup<T extends string>({
    label,
    options,
    value,
    onChange,
    hideLabel = false,
}: {
    label: string;
    options: Array<{ value: T; label: ReactNode }>;
    value: T;
    onChange: (value: T) => void;
    /** Keep the label for screen readers only. */
    hideLabel?: boolean;
}) {
    // One Tab stop; the arrows pick (UI audit, 2026-11-20).
    const group = useRef<HTMLDivElement>(null);
    useRovingRadios(group);

    return (
        <div
            ref={group}
            className="flex flex-wrap items-center gap-1"
            role="radiogroup"
            aria-label={label}
            onKeyDown={radioKeyDown}
        >
            {!hideLabel && <span className="gm-label mr-1">{label}</span>}
            {options.map((o) => (
                <button
                    key={o.value}
                    type="button"
                    role="radio"
                    aria-checked={value === o.value}
                    className={cn(
                        'gm-btn gm-btn-sm whitespace-nowrap',
                        value !== o.value && 'gm-btn-ghost',
                    )}
                    onClick={() => onChange(o.value)}
                >
                    {o.label}
                </button>
            ))}
        </div>
    );
}
