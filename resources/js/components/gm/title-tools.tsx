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
};

/**
 * Cycle the title on the line left/right, like the original card screen,
 * and pick an optional stipulation (§4, §22).
 */
export function TitleTools({
    draft,
    type,
    context,
    roster,
    readOnly,
    onChange,
}: Props) {
    const titles = useMemo(
        () => titleCandidates(draft, type, context, roster),
        [draft, type, context, roster],
    );
    const stipulations = stipulationOptions(draft, type, context, roster);
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
        <div className="flex flex-wrap items-center gap-4 text-sm">
            <div
                className="flex items-center gap-1"
                role="group"
                aria-label={`Title on the line for match ${draft.position}`}
            >
                <button
                    type="button"
                    className="px-2 py-1 text-ink-dim hover:text-ink disabled:opacity-30"
                    onClick={() => step(-1)}
                    disabled={readOnly || titles.length === 0}
                    aria-label="Previous title"
                >
                    ◀
                </button>
                <span
                    data-testid={`title-${draft.position}`}
                    className={cn(
                        'min-w-56 px-3 py-1 text-center font-condensed text-xs font-bold tracking-[0.14em] uppercase',
                        current
                            ? 'bg-gradient-to-b from-gold-hot to-gold text-arena'
                            : 'bg-white/5 text-ink-dim',
                    )}
                >
                    {current
                        ? `◆ ${current.name}`
                        : titles.length > 0
                          ? 'No title on the line'
                          : 'No title eligible'}
                </span>
                <button
                    type="button"
                    className="px-2 py-1 text-ink-dim hover:text-ink disabled:opacity-30"
                    onClick={() => step(1)}
                    disabled={readOnly || titles.length === 0}
                    aria-label="Next title"
                >
                    ▶
                </button>
            </div>

            <label className="flex items-center gap-2">
                <span className="gm-label">Stipulation</span>
                <select
                    aria-label={`Stipulation for match ${draft.position}`}
                    className="gm-input py-1 text-xs"
                    disabled={readOnly}
                    value={draft.stipulation ?? ''}
                    onChange={(e) => {
                        const option = stipulations.find(
                            (s) => s.value === e.target.value,
                        );
                        onChange({
                            ...draft,
                            stipulation: option?.value ?? null,
                            stipulationChampionshipId:
                                option?.championshipId ?? null,
                        });
                    }}
                >
                    <option value="">None</option>
                    {stipulations.map((s) => (
                        <option key={s.value} value={s.value}>
                            {s.label}
                        </option>
                    ))}
                </select>
            </label>
        </div>
    );
}
