import { useMemo, useState } from 'react';
import { SpecialtyBadge } from '@/components/gm/badges';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import {
    bookingCounts,
    competitorIds,
    emptySides,
    isComplete,
    projectedFatigue,
} from '@/lib/card';
import type { SlotDraft } from '@/lib/card';
import { fatigueZone, formatMoney, zoneLabel } from '@/lib/gm';
import { cn } from '@/lib/utils';
import { MatchFamilyLabels } from '@/types/enums';
import type {
    BookingPayload,
    CardShow,
    CardSlot,
    FatigueConfig,
    MatchTypeOption,
    RosterEntry,
} from '@/types/gm';

export type CardBuilderProps = {
    show: CardShow;
    slots: CardSlot[];
    matchTypes: MatchTypeOption[];
    roster: RosterEntry[];
    fatigueConfig: FatigueConfig;
    onSave: (position: number, payload: BookingPayload) => void;
    onClear: (position: number) => void;
    /** Extra controls rendered under each slot (titles, stipulations, randomize). */
    renderSlotTools?: (
        draft: SlotDraft,
        update: (next: SlotDraft) => void,
    ) => React.ReactNode;
};

export function toDraft(slot: CardSlot): SlotDraft {
    const managers: Record<number, number | null> = {};

    for (const [side, id] of Object.entries(slot.managers ?? {})) {
        managers[Number(side)] = id;
    }

    return {
        position: slot.position,
        matchTypeId: slot.match_type_id,
        sides: slot.sides,
        managers,
        titleChampionshipId: slot.title_championship_id,
        stipulation: slot.stipulation,
        stipulationChampionshipId: slot.stipulation_championship_id,
        fixed: slot.locked ?? slot.fixed_kind !== null,
    };
}

export function toPayload(draft: SlotDraft): BookingPayload {
    return {
        match_type_id: draft.matchTypeId as number,
        sides: draft.sides.map((side) =>
            side.filter((id): id is number => id !== null),
        ),
        managers: draft.managers,
        title_championship_id: draft.titleChampionshipId,
        stipulation: draft.stipulation,
        stipulation_championship_id: draft.stipulationChampionshipId,
    };
}

/**
 * The card builder (§4): match type first, then 2–6 wrestlers, with fatigue
 * bars, projected fatigue and the injury zone each booking lands in.
 */
export function CardBuilder({
    show,
    slots,
    matchTypes,
    roster,
    fatigueConfig,
    onSave,
    onClear,
    renderSlotTools,
}: CardBuilderProps) {
    const [drafts, setDrafts] = useState<SlotDraft[]>(() => slots.map(toDraft));
    const [dirty, setDirty] = useState<Set<number>>(new Set());

    const types = useMemo(
        () => new Map(matchTypes.map((t) => [t.id, t])),
        [matchTypes],
    );
    const rosterById = useMemo(
        () => new Map(roster.map((w) => [w.id, w])),
        [roster],
    );
    const counts = bookingCounts(drafts);
    const readOnly = show.status !== 'booking';

    const update = (next: SlotDraft) => {
        setDrafts((current) =>
            current.map((d) => (d.position === next.position ? next : d)),
        );
        setDirty((current) => new Set(current).add(next.position));
    };

    const totalCost = drafts.reduce((sum, d) => {
        const type =
            d.matchTypeId && !d.fixed ? types.get(d.matchTypeId) : undefined;

        return sum + (type?.cost ?? 0);
    }, 0);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-ink-dim">
                <span>
                    {drafts.filter(isComplete).length} of {drafts.length}{' '}
                    matches booked
                </span>
                <span>
                    Match costs:{' '}
                    <span className="gm-num text-ink">
                        {formatMoney(totalCost)}
                    </span>
                </span>
            </div>

            {drafts.map((draft) => (
                <SlotEditor
                    key={draft.position}
                    draft={draft}
                    isMainEvent={draft.position === drafts.length}
                    drafts={drafts}
                    types={types}
                    matchTypes={matchTypes}
                    roster={roster}
                    rosterById={rosterById}
                    counts={counts}
                    fatigueConfig={fatigueConfig}
                    readOnly={readOnly || draft.fixed}
                    dirty={dirty.has(draft.position)}
                    onChange={update}
                    onSave={() => {
                        onSave(draft.position, toPayload(draft));
                        setDirty((current) => {
                            const next = new Set(current);
                            next.delete(draft.position);

                            return next;
                        });
                    }}
                    onClear={() => {
                        onClear(draft.position);
                        update({
                            ...draft,
                            matchTypeId: null,
                            sides: [],
                            managers: {},
                            titleChampionshipId: null,
                            stipulation: null,
                            stipulationChampionshipId: null,
                        });
                        setDirty((current) => {
                            const next = new Set(current);
                            next.delete(draft.position);

                            return next;
                        });
                    }}
                    tools={renderSlotTools?.(draft, update)}
                    names={
                        slots.find((s) => s.position === draft.position)?.names
                    }
                />
            ))}
        </div>
    );
}

type SlotEditorProps = {
    draft: SlotDraft;
    isMainEvent: boolean;
    drafts: SlotDraft[];
    types: Map<number, MatchTypeOption>;
    matchTypes: MatchTypeOption[];
    roster: RosterEntry[];
    rosterById: Map<number, RosterEntry>;
    counts: Map<number, number>;
    fatigueConfig: FatigueConfig;
    readOnly: boolean;
    dirty: boolean;
    onChange: (draft: SlotDraft) => void;
    onSave: () => void;
    onClear: () => void;
    tools?: React.ReactNode;
    names?: Record<string, string>;
};

function SlotEditor({
    draft,
    isMainEvent,
    drafts,
    types,
    matchTypes,
    roster,
    rosterById,
    counts,
    fatigueConfig,
    readOnly,
    dirty,
    onChange,
    onSave,
    onClear,
    tools,
    names,
}: SlotEditorProps) {
    const type = draft.matchTypeId ? types.get(draft.matchTypeId) : undefined;
    const label = `Match ${draft.position}`;
    const inMatch = competitorIds(draft);

    const eligible = (side: number, index: number) =>
        roster.filter((w) => {
            const current = draft.sides[side]?.[index];

            if (w.id === current) {
                return true;
            }

            if (type?.divas_only && w.roster_type !== 'diva') {
                return false;
            }

            if (type && !type.divas_allowed && w.roster_type === 'diva') {
                return false;
            }

            return true;
        });

    const setCompetitor = (side: number, index: number, id: number | null) => {
        const sides = draft.sides.map((s) => [...s]);
        sides[side][index] = id;
        const managers = { ...draft.managers };

        for (const [s, m] of Object.entries(managers)) {
            if (m !== null && m === id) {
                managers[Number(s)] = null;
            }
        }

        onChange({ ...draft, sides, managers });
    };

    return (
        <section
            aria-label={label}
            className={cn(
                'gm-panel',
                isMainEvent && 'shadow-[0_0_0_1px_var(--color-gold)]',
            )}
        >
            <header className="flex flex-wrap items-center gap-3 border-b border-white/6 px-4 py-2.5">
                <span
                    className={cn(
                        'gm-chyron px-2 py-0.5 font-condensed text-xs font-bold tracking-[0.18em] uppercase',
                        isMainEvent
                            ? 'bg-gold text-arena'
                            : 'bg-brand text-white',
                    )}
                >
                    {isMainEvent ? 'Main event' : label}
                </span>
                <select
                    aria-label={`Match type for ${label.toLowerCase()}`}
                    className="gm-input min-w-64 flex-1 py-1 text-sm"
                    value={draft.matchTypeId ?? ''}
                    disabled={readOnly}
                    onChange={(e) => {
                        const id = e.target.value
                            ? Number(e.target.value)
                            : null;
                        const next = id ? types.get(id) : undefined;
                        onChange({
                            ...draft,
                            matchTypeId: id,
                            sides: emptySides(next),
                            managers: {},
                            titleChampionshipId: null,
                            stipulation: null,
                            stipulationChampionshipId: null,
                        });
                    }}
                >
                    <option value="">Choose a match type…</option>
                    {groupByFamily(matchTypes).map(([family, options]) => (
                        <optgroup
                            key={family}
                            label={MatchFamilyLabels[family]}
                        >
                            {options.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.variant}
                                    {t.cost > 0
                                        ? ` · ${formatMoney(t.cost)}`
                                        : ''}
                                    {` · ${'▮'.repeat(t.fatigue_cost_bars)}`}
                                </option>
                            ))}
                        </optgroup>
                    ))}
                </select>
                {type && (
                    <span
                        className="flex items-center gap-3 font-condensed text-xs tracking-wider text-ink-dim uppercase"
                        data-testid={`type-info-${draft.position}`}
                    >
                        <span>
                            Cost{' '}
                            <span className="gm-num text-ink">
                                {type.cost > 0
                                    ? formatMoney(type.cost)
                                    : 'Free'}
                            </span>
                        </span>
                        <span>
                            Fatigue{' '}
                            <span className="text-zone-minor">
                                {'▮'.repeat(type.fatigue_cost_bars)}
                            </span>
                        </span>
                    </span>
                )}
                {!readOnly && (
                    <div className="ml-auto flex gap-2">
                        <button
                            type="button"
                            className="gm-btn gm-btn-sm"
                            disabled={!isComplete(draft) || !dirty}
                            onClick={onSave}
                        >
                            Save {label.toLowerCase()}
                        </button>
                        <button
                            type="button"
                            className="gm-btn gm-btn-ghost gm-btn-sm"
                            disabled={draft.matchTypeId === null}
                            onClick={onClear}
                            aria-label={`Clear ${label.toLowerCase()}`}
                        >
                            Clear
                        </button>
                    </div>
                )}
            </header>

            {type ? (
                <div className="flex flex-wrap items-stretch gap-3 p-4">
                    {draft.sides.map((side, sideIndex) => (
                        <div key={sideIndex} className="contents">
                            {sideIndex > 0 && (
                                <div className="gm-heading flex items-center px-1 text-xl text-ink-faint italic">
                                    vs
                                </div>
                            )}
                            <div className="min-w-56 flex-1 space-y-2">
                                {side.map((id, index) => {
                                    const wrestler =
                                        id !== null
                                            ? rosterById.get(id)
                                            : undefined;
                                    const fatigue =
                                        id !== null
                                            ? projectedFatigue(
                                                  id,
                                                  draft.position,
                                                  drafts,
                                                  types,
                                                  rosterById,
                                                  fatigueConfig,
                                              )
                                            : null;
                                    const goingInZone = fatigue
                                        ? fatigueZone(
                                              fatigue.goingIn,
                                              fatigueConfig,
                                          )
                                        : null;
                                    const multi =
                                        id !== null &&
                                        (counts.get(id) ?? 0) > 1;

                                    return (
                                        <div
                                            key={index}
                                            data-testid={`slot-${draft.position}-side-${sideIndex}-${index}`}
                                            className={cn(
                                                'border border-white/8 bg-black/25 p-2',
                                                multi &&
                                                    'border-zone-minor/60 bg-zone-minor/[0.07]',
                                            )}
                                        >
                                            {readOnly &&
                                            id !== null &&
                                            !rosterById.has(id) ? (
                                                <div className="py-1 text-sm font-semibold">
                                                    {names?.[id] ?? '?'}
                                                </div>
                                            ) : (
                                                <select
                                                    aria-label={`${label} side ${sideIndex + 1} wrestler ${index + 1}`}
                                                    className="gm-input w-full py-1 text-sm"
                                                    value={id ?? ''}
                                                    disabled={readOnly}
                                                    onChange={(e) =>
                                                        setCompetitor(
                                                            sideIndex,
                                                            index,
                                                            e.target.value
                                                                ? Number(
                                                                      e.target
                                                                          .value,
                                                                  )
                                                                : null,
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Pick a wrestler…
                                                    </option>
                                                    {eligible(
                                                        sideIndex,
                                                        index,
                                                    ).map((w) => (
                                                        <option
                                                            key={w.id}
                                                            value={w.id}
                                                            disabled={
                                                                !w.bookable ||
                                                                (inMatch.includes(
                                                                    w.id,
                                                                ) &&
                                                                    w.id !== id)
                                                            }
                                                        >
                                                            {w.name}
                                                            {w.blocked_reason
                                                                ? ` (${w.blocked_reason})`
                                                                : ''}
                                                            {w.injury
                                                                ?.severity ===
                                                            'minor'
                                                                ? ' (minor injury)'
                                                                : ''}
                                                        </option>
                                                    ))}
                                                </select>
                                            )}
                                            {wrestler && fatigue && (
                                                <div className="mt-2 space-y-1.5">
                                                    <div className="flex flex-wrap items-center gap-1.5">
                                                        {wrestler.specialties
                                                            .filter(
                                                                (s) =>
                                                                    s ===
                                                                    type.family,
                                                            )
                                                            .map((s) => (
                                                                <SpecialtyBadge
                                                                    key={s}
                                                                    family={s}
                                                                    active
                                                                />
                                                            ))}
                                                        {multi && (
                                                            <span
                                                                className="bg-zone-minor px-1.5 py-px font-condensed text-[0.62rem] font-bold tracking-widest text-arena uppercase"
                                                                title="Booked in more than one match: each one adds fatigue and injury risk"
                                                            >
                                                                ×
                                                                {counts.get(
                                                                    wrestler.id,
                                                                )}{' '}
                                                                matches
                                                            </span>
                                                        )}
                                                    </div>
                                                    <FatigueMeter
                                                        fatigue={
                                                            fatigue.goingIn
                                                        }
                                                        projected={
                                                            fatigue.after
                                                        }
                                                        config={fatigueConfig}
                                                        showLabel
                                                    />
                                                    {goingInZone !== 'safe' &&
                                                        goingInZone && (
                                                            <p
                                                                className={cn(
                                                                    'font-condensed text-[0.7rem] tracking-wide uppercase',
                                                                    goingInZone ===
                                                                        'serious_risk'
                                                                        ? 'text-zone-serious'
                                                                        : 'text-zone-minor',
                                                                )}
                                                            >
                                                                Going in:{' '}
                                                                {
                                                                    zoneLabel[
                                                                        goingInZone
                                                                    ]
                                                                }
                                                                {goingInZone ===
                                                                'serious_risk'
                                                                    ? ' · guaranteed serious injury'
                                                                    : ' · minor injury roll'}
                                                            </p>
                                                        )}
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                                {type.manager_sides.includes(sideIndex) && (
                                    <select
                                        aria-label={`${label} manager for side ${sideIndex + 1}`}
                                        className="gm-input w-full py-1 text-xs"
                                        value={draft.managers[sideIndex] ?? ''}
                                        disabled={readOnly}
                                        onChange={(e) =>
                                            onChange({
                                                ...draft,
                                                managers: {
                                                    ...draft.managers,
                                                    [sideIndex]: e.target.value
                                                        ? Number(e.target.value)
                                                        : null,
                                                },
                                            })
                                        }
                                    >
                                        <option value="">No manager</option>
                                        {roster.map((w) => (
                                            <option
                                                key={w.id}
                                                value={w.id}
                                                disabled={
                                                    !w.bookable ||
                                                    inMatch.includes(w.id)
                                                }
                                            >
                                                Manager: {w.name}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <p className="px-4 py-5 text-sm text-ink-faint">
                    Choose a match type to start booking.
                </p>
            )}
            {tools && (
                <div className="border-t border-white/6 px-4 py-2.5">
                    {tools}
                </div>
            )}
        </section>
    );
}

function groupByFamily(
    types: MatchTypeOption[],
): Array<[MatchTypeOption['family'], MatchTypeOption[]]> {
    const groups = new Map<MatchTypeOption['family'], MatchTypeOption[]>();

    for (const type of types) {
        groups.set(type.family, [...(groups.get(type.family) ?? []), type]);
    }

    return [...groups.entries()];
}
