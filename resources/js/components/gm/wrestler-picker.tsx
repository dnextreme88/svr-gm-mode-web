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
    /** Already booked elsewhere on this card (still pickable). */
    booked?: boolean;
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
    /** A slot that must be filled: the list has no row to clear it. */
    required?: boolean;
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
    required = false,
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
                    // Tall enough for the clear row and 8 full wrestler rows.
                    className="gm-scroll max-h-[330px] w-full min-w-56 overflow-x-hidden overflow-y-auto py-1"
                >
                    {!required && (
                        <button
                            type="button"
                            role="option"
                            aria-selected={value === null}
                            onClick={() => pick(null)}
                            className="gm-label block w-full px-3 py-1.5 text-left text-ink-faint italic hover:bg-white/6"
                        >
                            {clearLabel}
                        </button>
                    )}
                    {options.map(
                        ({ wrestler, disabled: off, note, booked }) => (
                            <button
                                key={wrestler.id}
                                type="button"
                                role="option"
                                aria-selected={wrestler.id === value}
                                aria-disabled={off || undefined}
                                disabled={off}
                                onClick={() => pick(wrestler.id)}
                                className={cn(
                                    'flex w-full items-baseline justify-between gap-3 px-3 py-1.5 text-left text-base',
                                    off
                                        ? 'cursor-not-allowed opacity-40'
                                        : 'hover:bg-white/6',
                                    wrestler.id === value && 'bg-brand/20',
                                )}
                            >
                                <WrestlerName
                                    wrestler={wrestler}
                                    className="truncate"
                                />
                                {(note || booked) && (
                                    <span className="flex shrink-0 items-baseline gap-2 font-condensed text-[12px] tracking-wide uppercase">
                                        {note && (
                                            <span className="text-ink-faint">
                                                {note}
                                            </span>
                                        )}
                                        {booked && (
                                            <span className="text-gold-hot/85">
                                                Booked
                                            </span>
                                        )}
                                    </span>
                                )}
                            </button>
                        ),
                    )}
                </PopoverPanel>
            )}
        </div>
    );
}
