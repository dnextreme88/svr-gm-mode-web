import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { PopoverPanel } from '@/components/gm/popover';
import { cn } from '@/lib/utils';

/**
 * The card screen's dropdown look, shared by the wrestler and stipulation
 * pickers: a field-like button with a caret, and a list of rows under it.
 */
export function PickerButton({
    open,
    disabled,
    className,
    children,
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { open: boolean }) {
    return (
        <button
            type="button"
            aria-haspopup="listbox"
            aria-expanded={open}
            disabled={disabled}
            className={cn(
                'gm-input flex w-full items-center justify-between gap-2 py-1 text-left text-sm transition-colors',
                !disabled && 'hover:border-white/25',
                disabled && 'cursor-default opacity-70',
                className,
            )}
            {...props}
        >
            {children}
            {!disabled && (
                <span aria-hidden className="text-xs text-ink-faint">
                    ▾
                </span>
            )}
        </button>
    );
}

/** The placeholder a picker shows while nothing is picked. */
export function PickerEmpty({ children }: { children: ReactNode }) {
    return <span className="gm-label text-white/80">{children}</span>;
}

export function PickerList({
    open,
    label,
    className,
    children,
}: {
    open: boolean;
    label: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <PopoverPanel
            open={open}
            role="listbox"
            aria-label={label}
            // Tall enough for the clear row and 8 full rows.
            className={cn(
                'gm-scroll max-h-[330px] w-full min-w-56 overflow-x-hidden overflow-y-auto py-1',
                className,
            )}
        >
            {children}
        </PopoverPanel>
    );
}

/** The first row of a list, which clears the pick. */
export function PickerClearRow({
    selected,
    onClick,
    children,
}: {
    selected: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            role="option"
            aria-selected={selected}
            onClick={onClick}
            className="gm-label block w-full px-3 py-1.5 text-left text-ink-faint italic hover:bg-white/6"
        >
            {children}
        </button>
    );
}

export function PickerRow({
    selected,
    disabled,
    onClick,
    aside,
    children,
}: {
    selected: boolean;
    disabled?: boolean;
    onClick: () => void;
    /** Right-aligned caps after the label: a note, Booked, Can't book. */
    aside?: ReactNode;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            role="option"
            aria-selected={selected}
            aria-disabled={disabled || undefined}
            disabled={disabled}
            onClick={onClick}
            className={cn(
                'flex w-full items-baseline justify-between gap-3 px-3 py-1.5 text-left text-base',
                disabled ? 'cursor-not-allowed opacity-40' : 'hover:bg-white/6',
                selected && 'bg-brand/20',
            )}
        >
            {children}
            {aside && (
                <span className="flex shrink-0 items-baseline gap-2 font-condensed text-sm tracking-wide uppercase">
                    {aside}
                </span>
            )}
        </button>
    );
}
