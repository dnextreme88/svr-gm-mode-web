import type { ButtonHTMLAttributes, KeyboardEvent, ReactNode } from 'react';
import { useEffect, useId, useRef } from 'react';
import { PopoverPanel } from '@/components/gm/popover';
import { cn } from '@/lib/utils';

/**
 * Keyboard for a picker's list (UI audit, 2026-11-20): Up / Down step
 * through the options (wrapping), Home / End jump to the ends, and a letter
 * jumps to the next option starting with it. Disabled options are skipped.
 * Put it on the element with `role="listbox"`.
 */
export function moveInList(e: KeyboardEvent<HTMLElement>): void {
    const options = [
        ...e.currentTarget.querySelectorAll<HTMLElement>(
            '[role="option"]:not([disabled])',
        ),
    ];

    if (options.length === 0) {
        return;
    }

    const at = options.indexOf(document.activeElement as HTMLElement);
    let next: HTMLElement | undefined;

    if (e.key === 'ArrowDown') {
        next = options[at + 1] ?? options[0];
    } else if (e.key === 'ArrowUp') {
        next = at <= 0 ? options[options.length - 1] : options[at - 1];
    } else if (e.key === 'Home') {
        next = options[0];
    } else if (e.key === 'End') {
        next = options[options.length - 1];
    } else if (
        e.key.length === 1 &&
        e.key.trim() !== '' &&
        !e.altKey &&
        !e.ctrlKey &&
        !e.metaKey
    ) {
        const letter = e.key.toLowerCase();
        const order = [...options.slice(at + 1), ...options.slice(0, at + 1)];
        next = order.find((o) =>
            (o.textContent ?? '').trim().toLowerCase().startsWith(letter),
        );
    }

    if (next) {
        e.preventDefault();
        next.focus();
        next.scrollIntoView?.({ block: 'nearest' });
    }
}

/** Focus the picked option, else the first one that can be picked. */
export function focusFirstOption(list: HTMLElement | null): void {
    const option =
        list?.querySelector<HTMLElement>(
            '[role="option"][aria-selected="true"]:not([disabled])',
        ) ??
        list?.querySelector<HTMLElement>('[role="option"]:not([disabled])');

    option?.focus();
    option?.scrollIntoView?.({ block: 'nearest' });
}

/**
 * Ids that make a picker button's accessible name its label and then its
 * value ("Match 1 side 1 superstar 1 Edge"), so a screen reader hears who's
 * picked (UI audit, 2026-11-20). Spread `button` on the button, render
 * `labelText` inside it and give the visible value's wrapper `valueId`.
 */
export function useLabelledValue(label: string) {
    const id = useId();

    return {
        button: { 'aria-labelledby': `${id}-label ${id}-value` },
        valueId: `${id}-value`,
        labelText: (
            <span id={`${id}-label`} className="sr-only">
                {label}
            </span>
        ),
    };
}

/**
 * The card screen's dropdown look, shared by the wrestler and stipulation
 * pickers: a field-like button with a caret, and a list of rows under it.
 */
export function PickerButton({
    open,
    label,
    disabled,
    className,
    children,
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & {
    open: boolean;
    /** The picker's name; the button reads it, then its value. */
    label: string;
}) {
    const named = useLabelledValue(label);

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
            {...named.button}
            {...props}
        >
            {named.labelText}
            <span id={named.valueId} className="flex min-w-0 items-center">
                {children}
            </span>
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
    const list = useRef<HTMLDivElement>(null);

    // Opened with focus on its button (the keyboard, or a click): focus moves
    // into the list, on the picked option.
    useEffect(() => {
        const el = list.current;

        if (open && el?.parentElement?.contains(document.activeElement)) {
            focusFirstOption(el);
        }
    }, [open]);

    return (
        <PopoverPanel
            ref={list}
            open={open}
            role="listbox"
            aria-label={label}
            onKeyDown={moveInList}
            // Tall enough for the clear row and 8 full rows.
            className={cn(
                'gm-scroll max-h-[330px] w-full min-w-56 overflow-x-hidden overflow-y-auto overscroll-contain py-1',
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
            tabIndex={-1}
            onClick={onClick}
            className="gm-label block w-full px-3 py-1.5 text-left text-ink-faint italic hover:bg-white/6 focus-visible:bg-white/10 focus-visible:outline-none"
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
            tabIndex={-1}
            onClick={onClick}
            className={cn(
                'flex w-full items-baseline justify-between gap-3 px-3 py-1.5 text-left text-base focus-visible:bg-white/10 focus-visible:shadow-[inset_2px_0_0_var(--gm-brand)] focus-visible:outline-none',
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
