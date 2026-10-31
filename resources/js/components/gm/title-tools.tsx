import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import {
    PickerButton,
    PickerClearRow,
    PickerEmpty,
    PickerList,
    PickerRow,
} from '@/components/gm/picker';
import { usePopover } from '@/components/gm/popover';
import type { SlotDraft } from '@/lib/card';
import {
    shortTitle,
    stipulationLabel,
    stipulationOptions,
    titleCandidates,
} from '@/lib/titles';
import type { StipulationOption } from '@/lib/titles';
import { cn } from '@/lib/utils';
import type { Stipulation } from '@/types/enums';
import type { MatchTypeOption, RosterEntry, TitleContext } from '@/types/gm';

type Props = {
    draft: SlotDraft;
    type: MatchTypeOption | undefined;
    context: TitleContext;
    roster: Map<number, RosterEntry>;
    readOnly: boolean;
    onChange: (next: SlotDraft) => void;
    /** Titles already picked in the card's other matches. */
    takenTitleIds?: number[];
};

/**
 * Cycle the title on the line left/right, like the original card screen (§4).
 */
export function TitleCycler({
    draft,
    type,
    context,
    roster,
    readOnly,
    onChange,
    takenTitleIds = [],
}: Props) {
    const titles = useMemo(
        () => titleCandidates(draft, type, context, roster, takenTitleIds),
        [draft, type, context, roster, takenTitleIds],
    );
    const cycle = [null, ...titles.map((t) => t.id)];
    const index = Math.max(0, cycle.indexOf(draft.titleChampionshipId));
    const current = titles.find((t) => t.id === draft.titleChampionshipId);

    const setTitle = (id: number | null) =>
        onChange({
            ...draft,
            titleChampionshipId: id,
            // Only Loser Leaves Show goes with any title choice; the rest need
            // the title on (Last Chance) or off the line.
            stipulation:
                draft.stipulation === 'loser_leaves_show'
                    ? draft.stipulation
                    : null,
            stipulationChampionshipId: null,
        });

    const step = (delta: number) =>
        setTitle(cycle[(index + delta + cycle.length) % cycle.length]);

    if (!type) {
        return null;
    }

    return (
        <div
            className="flex w-full items-center gap-1 text-sm lg:w-auto"
            role="group"
            aria-label={`Title on the line for match ${draft.position}`}
        >
            <button
                type="button"
                className="px-2 py-1 text-ink-dim enabled:hover:text-ink disabled:opacity-30"
                onClick={() => step(-1)}
                disabled={readOnly || titles.length === 0}
                aria-label="Previous title"
            >
                ◀
            </button>
            <span
                data-testid={`title-${draft.position}`}
                className={cn(
                    'text-md mt-1 min-w-0 flex-1 px-3 py-1 text-center font-condensed font-bold tracking-[0.14em] uppercase sm:min-w-[300px] md:flex-none',
                    current
                        ? 'bg-gradient-to-b from-gold-hot to-gold text-arena'
                        : titles.length > 0
                          ? 'bg-white/5 text-white/80'
                          : 'bg-white/5 text-white/20 hover:cursor-not-allowed',
                )}
            >
                {current
                    ? `◆ ${current.name}`
                    : titles.length > 0
                      ? 'Select title'
                      : 'No title eligible'}
            </span>
            <button
                type="button"
                className="px-2 py-1 text-ink-dim enabled:hover:text-ink disabled:opacity-30"
                onClick={() => step(1)}
                disabled={readOnly || titles.length === 0}
                aria-label="Next title"
            >
                ▶
            </button>
        </div>
    );
}

/** Stipulations that ask which title they're about. */
const NEEDS_TITLE: Stipulation[] = [
    'champion_vs_contender',
    'number_one_contender',
];

/**
 * The match's optional stipulation (§22 and house rules): a dropdown like
 * the wrestler pickers. Champion vs. Contender and #1 Contender's Match
 * open a modal to pick the title in contention.
 */
export function StipulationPicker({
    draft,
    type,
    context,
    roster,
    readOnly,
    onChange,
    takenTitleIds = [],
    numberOneTitleIds = [],
}: Props & {
    /** Titles another match on the card holds a #1 Contender's Match for. */
    numberOneTitleIds?: number[];
}) {
    const { open, mounted, ref, toggle, close } = usePopover();
    const [asking, setAsking] = useState<StipulationOption | null>(null);
    const [titleId, setTitleId] = useState<number | null>(null);

    if (!type) {
        return null;
    }

    const options = stipulationOptions(draft, type, context, roster, {
        takenIds: takenTitleIds,
        numberOneIds: numberOneTitleIds,
    });
    const current = options.find((o) => o.value === draft.stipulation);
    const label = `Stipulation for match ${draft.position}`;

    const set = (value: Stipulation | null, championshipId: number | null) =>
        onChange({
            ...draft,
            stipulation: value,
            stipulationChampionshipId: championshipId,
        });

    const pick = (option: StipulationOption | null) => {
        close();

        if (option && NEEDS_TITLE.includes(option.value)) {
            const titles = option.titles ?? [];
            setTitleId(
                titles.find((t) => t.id === draft.stipulationChampionshipId)
                    ?.id ??
                    titles[0]?.id ??
                    null,
            );
            setAsking(option);

            return;
        }

        set(option?.value ?? null, null);
    };

    if (options.length === 0) {
        return (
            <div
                data-testid={`stipulation-${draft.position}`}
                aria-label={label}
                className="w-full bg-white/5 px-3 py-1 text-center font-condensed text-sm font-bold tracking-[0.14em] text-white/20 uppercase hover:cursor-not-allowed lg:w-auto lg:min-w-[300px]"
            >
                No stipulation eligible
            </div>
        );
    }

    return (
        <div
            ref={ref}
            className="relative w-full lg:w-auto lg:min-w-[300px]"
            data-testid={`stipulation-${draft.position}`}
        >
            <PickerButton
                aria-label={label}
                open={open}
                disabled={readOnly}
                onClick={toggle}
            >
                {current ? (
                    <span className="truncate font-semibold text-gold-hot">
                        ◆ {stipulationLabel(current, draft, context)}
                    </span>
                ) : (
                    <PickerEmpty>Select stipulation</PickerEmpty>
                )}
            </PickerButton>
            {mounted && (
                <PickerList open={open} label={label} className="min-w-80">
                    <PickerClearRow
                        selected={draft.stipulation === null}
                        onClick={() => pick(null)}
                    >
                        No stipulation
                    </PickerClearRow>
                    {options.map((option) => (
                        <PickerRow
                            key={option.value}
                            selected={option.value === draft.stipulation}
                            onClick={() => pick(option)}
                            aside={
                                option.contested ? (
                                    <span className="text-ink-faint">
                                        {option.contested
                                            .map((t) => shortTitle(t.name))
                                            .join(', ')}
                                    </span>
                                ) : NEEDS_TITLE.includes(option.value) ? (
                                    <span className="text-ink-faint">
                                        Pick a title…
                                    </span>
                                ) : undefined
                            }
                        >
                            <span className="truncate">{option.label}</span>
                        </PickerRow>
                    ))}
                </PickerList>
            )}
            <ConfirmDialog
                open={asking !== null}
                title={asking?.label ?? ''}
                message={
                    <div>
                        <p>
                            {asking?.value === 'number_one_contender'
                                ? 'Which title will the winner contend for?'
                                : 'Which title is the contender chasing?'}
                        </p>
                        <div
                            role="radiogroup"
                            aria-label="Title in contention"
                            className="mt-3 space-y-1"
                        >
                            {(asking?.titles ?? []).map((t) => (
                                <label
                                    key={t.id}
                                    className={cn(
                                        'flex cursor-pointer items-center justify-between gap-3 border px-3 py-2 text-ink',
                                        titleId === t.id
                                            ? 'border-gold/70 bg-gold/10'
                                            : 'border-white/10 hover:bg-white/6',
                                    )}
                                >
                                    <span className="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            name={`stipulation-title-${draft.position}`}
                                            checked={titleId === t.id}
                                            onChange={() => setTitleId(t.id)}
                                            className="accent-[var(--color-gold)]"
                                        />
                                        <span className="font-semibold">
                                            ◆ {t.name}
                                        </span>
                                    </span>
                                    <span className="font-condensed text-sm tracking-wide text-ink-dim uppercase">
                                        {t.holder_name}
                                    </span>
                                </label>
                            ))}
                        </div>
                    </div>
                }
                confirmLabel="Confirm"
                onCancel={() => setAsking(null)}
                onConfirm={() => {
                    if (asking && titleId !== null) {
                        set(asking.value, titleId);
                    }

                    setAsking(null);
                }}
            />
        </div>
    );
}
