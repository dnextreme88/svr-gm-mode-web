import {
    PickerButton,
    PickerClearRow,
    PickerEmpty,
    PickerList,
    PickerRow,
} from '@/components/gm/picker';
import { usePopover } from '@/components/gm/popover';
import { GoldTag } from '@/components/gm/badges';
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
            <PickerButton
                aria-label={label}
                open={open}
                disabled={disabled}
                onClick={toggle}
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
                    <PickerEmpty>{emptyLabel}</PickerEmpty>
                )}
            </PickerButton>
            {mounted && (
                <PickerList open={open} label={label}>
                    {!required && (
                        <PickerClearRow
                            selected={value === null}
                            onClick={() => pick(null)}
                        >
                            {clearLabel}
                        </PickerClearRow>
                    )}
                    {options.map(
                        ({ wrestler, disabled: off, note, booked }) => (
                            <PickerRow
                                key={wrestler.id}
                                selected={wrestler.id === value}
                                disabled={off}
                                onClick={() => pick(wrestler.id)}
                                aside={
                                    (note || booked) && (
                                        <>
                                            {note && (
                                                <span className="text-ink-faint">
                                                    {note}
                                                </span>
                                            )}
                                            {booked && (
                                                <GoldTag>Booked</GoldTag>
                                            )}
                                        </>
                                    )
                                }
                            >
                                <WrestlerName
                                    wrestler={wrestler}
                                    className="truncate"
                                />
                            </PickerRow>
                        ),
                    )}
                </PickerList>
            )}
        </div>
    );
}
