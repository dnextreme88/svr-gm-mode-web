import { PopoverPanel, usePopover } from '@/components/gm/popover';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { cn } from '@/lib/utils';
import type { RosterType, WeightClass } from '@/types/enums';

export type PickerWrestler = {
    id: number;
    name: string;
    roster_type: RosterType | null;
    weight_class: WeightClass | null;
};

export type PickerOption = {
    wrestler: PickerWrestler;
    disabled?: boolean;
    /** Shown after the name, e.g. why the wrestler can't be picked. */
    note?: string | null;
};

type Props = {
    value: number | null;
    options: PickerOption[];
    onChange: (id: number | null) => void;
    /** Accessible name for the button and its list. */
    label: string;
    /** Shown on the button when nothing is picked. */
    emptyLabel?: string;
    /** The first row of the list, which clears the pick. */
    clearLabel?: string;
    /** Name to show when the picked wrestler isn't in `options`. */
    fallbackName?: string;
    disabled?: boolean;
    className?: string;
};

/**
 * A wrestler slot: a button that reads VACANT until filled, opening a list of
 * the wrestlers who can fill it.
 */
export function WrestlerPicker({
    value,
    options,
    onChange,
    label,
    emptyLabel = 'VACANT',
    clearLabel = 'Leave vacant',
    fallbackName,
    disabled,
    className,
}: Props) {
    const { open, mounted, ref, toggle, close } = usePopover();
    const selected = options.find((o) => o.wrestler.id === value)?.wrestler;

    const pick = (id: number | null) => {
        onChange(id);
        close();
    };

    return (
        <div ref={ref} className={cn('relative', className)}>
            <button
                type="button"
                aria-label={label}
                aria-haspopup="listbox"
                aria-expanded={open}
                disabled={disabled}
                onClick={toggle}
                className={cn(
                    'gm-input flex w-full items-center justify-between gap-2 py-1 text-left text-sm transition-colors',
                    !disabled && 'hover:border-white/25',
                    disabled && 'cursor-default opacity-70',
                )}
            >
                {selected ? (
                    <WrestlerName
                        wrestler={selected}
                        className="truncate font-semibold"
                    />
                ) : value !== null && fallbackName ? (
                    <span className="truncate font-semibold">
                        {fallbackName}
                    </span>
                ) : (
                    <span className="gm-label text-ink-faint italic">
                        {emptyLabel}
                    </span>
                )}
                {!disabled && (
                    <span aria-hidden className="text-xs text-ink-faint">
                        ▾
                    </span>
                )}
            </button>
            {mounted && (
                <PopoverPanel
                    open={open}
                    role="listbox"
                    aria-label={label}
                    className="gm-scroll max-h-72 w-full min-w-56 overflow-y-auto py-1"
                >
                    <button
                        type="button"
                        role="option"
                        aria-selected={value === null}
                        onClick={() => pick(null)}
                        className="gm-label block w-full px-3 py-1.5 text-left text-ink-faint italic hover:bg-white/6"
                    >
                        {clearLabel}
                    </button>
                    {options.map(({ wrestler, disabled: off, note }) => (
                        <button
                            key={wrestler.id}
                            type="button"
                            role="option"
                            aria-selected={wrestler.id === value}
                            aria-disabled={off || undefined}
                            disabled={off}
                            onClick={() => pick(wrestler.id)}
                            className={cn(
                                'flex w-full items-baseline justify-between gap-2 px-3 py-1.5 text-left text-sm',
                                off
                                    ? 'cursor-not-allowed opacity-40'
                                    : 'hover:bg-white/6',
                                wrestler.id === value && 'bg-brand/20',
                            )}
                        >
                            <WrestlerName wrestler={wrestler} />
                            {note && (
                                <span className="font-condensed text-xs tracking-wide text-ink-faint uppercase">
                                    {note}
                                </span>
                            )}
                        </button>
                    ))}
                </PopoverPanel>
            )}
        </div>
    );
}
