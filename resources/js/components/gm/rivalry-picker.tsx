import { useEffect, useRef } from 'react';
import {
    focusFirstOption,
    moveInList,
    useLabelledValue,
} from '@/components/gm/picker';
import { usePopover, PopoverPanel } from '@/components/gm/popover';
import { WrestlerName } from '@/components/gm/wrestler-name';
import { cn } from '@/lib/utils';
import type { RosterEntry } from '@/types/gm';

export type RivalryOption = {
    /** The two rivals, alphabetically. */
    pair: [RosterEntry, RosterEntry];
    disabled?: boolean;
    /** Why it can't be picked, e.g. "In Promo 1". */
    note?: string | null;
};

/** "Jeff Hardy vs. Rob Van Dam": a rivalry is always named alphabetically. */
export function sortPair(
    x: RosterEntry,
    y: RosterEntry,
): [RosterEntry, RosterEntry] {
    return x.name.localeCompare(y.name) <= 0 ? [x, y] : [y, x];
}

function PairName({ pair }: { pair: [RosterEntry, RosterEntry] }) {
    return (
        <span className="truncate">
            <WrestlerName wrestler={pair[0]} />
            <span className="text-ink-faint"> vs. </span>
            <WrestlerName wrestler={pair[1]} />
        </span>
    );
}

/**
 * Picks one of the brand's rivalries (Continue Rivalry): a single list of
 * "A vs. B" pairs instead of two wrestler pickers.
 */
export function RivalryPicker({
    label,
    value,
    options,
    disabled = false,
    onChange,
}: {
    label: string;
    /** The picked rivals' ids, either order. */
    value: [number, number] | null;
    options: RivalryOption[];
    disabled?: boolean;
    onChange: (pair: [number, number]) => void;
}) {
    const { open, mounted, ref, toggle, close } = usePopover();
    const isValue = (o: RivalryOption) =>
        value !== null && o.pair.every((w) => value.includes(w.id));
    const selected = options.find(isValue);
    const named = useLabelledValue(label);
    const list = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (open && ref.current?.contains(document.activeElement)) {
            focusFirstOption(list.current);
        }
    }, [open, ref]);

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                {...named.button}
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
                {named.labelText}
                <span id={named.valueId} className="flex min-w-0">
                    {selected ? (
                        <span className="truncate font-semibold">
                            <PairName pair={selected.pair} />
                        </span>
                    ) : (
                        <span className="gm-label text-ink-faint">
                            Pick a rivalry
                        </span>
                    )}
                </span>
                {!disabled && (
                    <span aria-hidden className="text-xs text-ink-faint">
                        ▾
                    </span>
                )}
            </button>
            {mounted && (
                <PopoverPanel
                    ref={list}
                    open={open}
                    role="listbox"
                    aria-label={label}
                    onKeyDown={moveInList}
                    className="gm-scroll max-h-[330px] w-full min-w-64 overflow-x-hidden overflow-y-auto overscroll-contain py-1"
                >
                    {options.map((o) => (
                        <button
                            key={`${o.pair[0].id}-${o.pair[1].id}`}
                            type="button"
                            role="option"
                            aria-selected={isValue(o)}
                            aria-disabled={o.disabled || undefined}
                            disabled={o.disabled}
                            tabIndex={-1}
                            onClick={() => {
                                onChange([o.pair[0].id, o.pair[1].id]);
                                close();
                            }}
                            className={cn(
                                'flex w-full items-baseline justify-between gap-3 px-3 py-1.5 text-left text-base focus-visible:bg-white/10 focus-visible:outline-none',
                                o.disabled
                                    ? 'cursor-not-allowed opacity-40'
                                    : 'hover:bg-white/6',
                                isValue(o) && 'bg-brand/20',
                            )}
                        >
                            <PairName pair={o.pair} />
                            {o.note && (
                                <span className="shrink-0 font-condensed text-sm tracking-wide text-ink-faint uppercase">
                                    {o.note}
                                </span>
                            )}
                        </button>
                    ))}
                </PopoverPanel>
            )}
        </div>
    );
}
