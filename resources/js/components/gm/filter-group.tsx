import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** A row of toggle buttons for one filter. */
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
    return (
        <div
            className="flex flex-wrap items-center gap-1"
            role="radiogroup"
            aria-label={label}
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
