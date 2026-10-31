import { useMemo } from 'react';
import type { SlotDraft } from '@/lib/card';
import { stipulationOptions, titleCandidates } from '@/lib/titles';
import { cn } from '@/lib/utils';
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
            stipulation:
                draft.stipulation === 'champion_vs_contender' ||
                draft.stipulation === 'last_chance'
                    ? null
                    : draft.stipulation,
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

/**
 * Cycle an optional stipulation left/right, styled like the title cycler (§22).
 */
export function StipulationPicker({
    draft,
    type,
    context,
    roster,
    readOnly,
    onChange,
}: Props) {
    if (!type) {
        return null;
    }

    const stipulations = stipulationOptions(draft, type, context, roster);
    const cycle = [null, ...stipulations];
    const index = Math.max(
        0,
        cycle.findIndex((s) => (s?.value ?? null) === draft.stipulation),
    );
    const current = cycle[index];

    const step = (delta: number) => {
        const next = cycle[(index + delta + cycle.length) % cycle.length];
        onChange({
            ...draft,
            stipulation: next?.value ?? null,
            stipulationChampionshipId: next?.championshipId ?? null,
        });
    };

    return (
        <div className="flex w-full items-center gap-2 text-sm lg:w-auto">
            <div
                className="flex min-w-0 flex-1 items-center gap-1 lg:flex-none"
                role="group"
                aria-label={`Stipulation for match ${draft.position}`}
            >
                <button
                    type="button"
                    className="px-2 py-1 text-ink-dim enabled:hover:text-ink disabled:opacity-30"
                    onClick={() => step(-1)}
                    disabled={readOnly || stipulations.length === 0}
                    aria-label="Previous stipulation"
                >
                    ◀
                </button>
                <span
                    data-testid={`stipulation-${draft.position}`}
                    className={cn(
                        'min-w-0 flex-1 px-3 py-1 text-center font-condensed font-bold tracking-[0.14em] uppercase lg:min-w-[275px] lg:flex-none',
                        current
                            ? 'bg-gradient-to-b from-gold-hot to-gold text-arena'
                            : stipulations.length > 0
                              ? 'bg-white/5 text-white/80'
                              : 'bg-white/5 text-white/20 hover:cursor-not-allowed',
                    )}
                >
                    {current
                        ? `◆ ${current.label}`
                        : stipulations.length > 0
                          ? 'Select stipulation'
                          : 'No stipulation eligible'}
                </span>
                <button
                    type="button"
                    className="px-2 py-1 text-ink-dim enabled:hover:text-ink disabled:opacity-30"
                    onClick={() => step(1)}
                    disabled={readOnly || stipulations.length === 0}
                    aria-label="Next stipulation"
                >
                    ▶
                </button>
            </div>
        </div>
    );
}
