import { useMemo, useState } from 'react';
import { GoldTag, SpecialtyBadge } from '@/components/gm/badges';
import { FatigueCost } from '@/components/gm/fatigue-cost';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import { PopoverPanel, usePopover } from '@/components/gm/popover';
import { SlotActions } from '@/components/gm/slot-actions';
import { WrestlerPicker } from '@/components/gm/wrestler-picker';
import type { PickerOption } from '@/components/gm/wrestler-picker';
import { useReportUnsaved } from '@/components/gm/unsaved-changes';
import {
    bookingCounts,
    emptySides,
    isComplete,
    participantIds,
    projectedFatigue,
} from '@/lib/card';
import type { PromoFatigue, SlotDraft } from '@/lib/card';
import { fatigueZone, formatMoney, zoneLabel } from '@/lib/gm';
import { slotSaveKey, useSaveStatus } from '@/lib/save-status';
import { cn } from '@/lib/utils';
import { MatchFamilyLabels } from '@/types/enums';
import type {
    BookingPayload,
    CardShow,
    CardSlot,
    FatigueConfig,
    MatchTypeOption,
    RosterEntry,
    TitleOption,
} from '@/types/gm';

type SlotTools = (
    draft: SlotDraft,
    update: (next: SlotDraft) => void,
    /** Every slot on the card as currently edited, saved or not. */
    drafts: SlotDraft[],
) => React.ReactNode;

export type CardBuilderProps = {
    show: CardShow;
    slots: CardSlot[];
    matchTypes: MatchTypeOption[];
    roster: RosterEntry[];
    fatigueConfig: FatigueConfig;
    onSave: (position: number, payload: BookingPayload) => void;
    onClear: (position: number) => void;
    /** The title cycler, shown in each slot's header. */
    renderTitle?: SlotTools;
    /** More buttons beside Save, Discard and Clear (Randomize). */
    renderButtons?: SlotTools;
    /** Extra controls on the slot's action row (the stipulation). */
    renderActions?: SlotTools;
    /** Saved promos that tire wrestlers before a given match (§5). */
    promoFatigue?: PromoFatigue[];
    /** Read-only because the week's booking order hasn't reached this card, or it's confirmed. */
    lockedByOrder?: boolean;
    /** Every title with its holders, to tag the champions in a title match. */
    championships?: TitleOption[];
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
        refereeId: slot.referee_id ?? null,
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
        referee_id: draft.refereeId,
    };
}

/** A match type's price: green, and italic when it's free. */
export function MatchCost({ cost }: { cost: number }) {
    return (
        <span
            data-testid="match-cost"
            className={cn('gm-num text-zone-safe', cost === 0 && 'italic')}
        >
            {cost > 0 ? formatMoney(cost) : 'Free'}
        </span>
    );
}

/**
 * How much of the card is booked, as a brand-coloured bar with a sheen.
 */
export function CardProgress({
    booked,
    total,
}: {
    booked: number;
    total: number;
}) {
    const percent = total > 0 ? Math.round((booked / total) * 100) : 0;

    return (
        <div className="min-w-56 flex-1 space-y-1.5">
            <span>
                {booked} of {total} matches booked
            </span>
            <div
                role="progressbar"
                aria-label="Matches booked"
                aria-valuemin={0}
                aria-valuemax={total}
                aria-valuenow={booked}
                className="h-2.5 max-w-md bg-white/8"
            >
                <div
                    className={cn(
                        'h-full bg-brand transition-[width] duration-700 ease-out',
                        booked > 0 && 'gm-sheen',
                    )}
                    style={{ width: `${percent}%` }}
                />
            </div>
        </div>
    );
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
    renderTitle,
    renderButtons,
    renderActions,
    promoFatigue = [],
    lockedByOrder = false,
    championships = [],
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
    const readOnly = show.status !== 'booking' || lockedByOrder;
    const complete = (d: SlotDraft) =>
        isComplete(d, d.matchTypeId ? types.get(d.matchTypeId) : undefined);

    const markClean = (position: number) =>
        setDirty((current) => {
            const next = new Set(current);
            next.delete(position);

            return next;
        });

    const clearSlot = (position: number) => {
        onClear(position);
        setDrafts((current) =>
            current.map((d) =>
                d.position === position
                    ? {
                          ...d,
                          matchTypeId: null,
                          sides: [],
                          managers: {},
                          titleChampionshipId: null,
                          stipulation: null,
                          stipulationChampionshipId: null,
                          refereeId: null,
                      }
                    : d,
            ),
        );
        markClean(position);
    };

    // Back to the last saved booking for this slot.
    const discard = (position: number) => {
        const saved = slots.find((s) => s.position === position);

        if (saved) {
            setDrafts((current) =>
                current.map((d) =>
                    d.position === position ? toDraft(saved) : d,
                ),
            );
        }

        markClean(position);
    };

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
            <div className="flex flex-wrap items-end justify-between gap-3 text-sm text-ink-dim">
                <CardProgress
                    booked={drafts.filter(complete).length}
                    total={drafts.length}
                />
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
                    promoFatigue={promoFatigue}
                    championships={championships}
                    readOnly={readOnly || draft.fixed}
                    canSave={!readOnly && !draft.fixed}
                    dirty={dirty.has(draft.position)}
                    saveKey={slotSaveKey('match', show.id, draft.position)}
                    complete={complete(draft)}
                    onChange={update}
                    onSave={() => {
                        onSave(draft.position, toPayload(draft));
                        markClean(draft.position);
                    }}
                    onDiscard={() => discard(draft.position)}
                    onClear={() => clearSlot(draft.position)}
                    title={renderTitle?.(draft, update, drafts)}
                    buttons={renderButtons?.(draft, update, drafts)}
                    actions={renderActions?.(draft, update, drafts)}
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
    promoFatigue: PromoFatigue[];
    championships: TitleOption[];
    readOnly: boolean;
    canSave: boolean;
    dirty: boolean;
    /** Tracks this slot's save for the floppy (`lib/save-status`). */
    saveKey: string;
    complete: boolean;
    onChange: (draft: SlotDraft) => void;
    onSave: () => void;
    onDiscard: () => void;
    onClear: () => void;
    title?: React.ReactNode;
    buttons?: React.ReactNode;
    actions?: React.ReactNode;
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
    promoFatigue,
    championships,
    readOnly,
    canSave,
    dirty,
    saveKey,
    complete,
    onChange,
    onSave,
    onDiscard,
    onClear,
    title,
    buttons,
    actions,
    names,
}: SlotEditorProps) {
    const type = draft.matchTypeId ? types.get(draft.matchTypeId) : undefined;
    const label = `Match ${draft.position}`;
    const saveStatus = useSaveStatus(saveKey);
    const everyone = participantIds(draft);
    useReportUnsaved(label, dirty);
    // The holders of the title on the line, tagged "Champion" on their slots.
    const champions =
        championships.find((c) => c.id === draft.titleChampionshipId)
            ?.holder_ids ?? [];

    const note = (w: RosterEntry) =>
        w.blocked_reason ??
        (w.injury?.severity === 'minor' ? 'Minor injury' : null);

    // Booked in another slot on this card (double booking is allowed).
    const bookedElsewhere = (id: number) =>
        (counts.get(id) ?? 0) - (everyone.includes(id) ? 1 : 0) > 0;

    const competitorOptions = (side: number, index: number): PickerOption[] => {
        const current = draft.sides[side]?.[index];

        return roster
            .filter((w) => {
                if (w.id === current) {
                    return true;
                }

                if (type?.divas_only && w.roster_type !== 'diva') {
                    return false;
                }

                return !(
                    type &&
                    !type.divas_allowed &&
                    w.roster_type === 'diva'
                );
            })
            .map((w) => ({
                wrestler: w,
                disabled:
                    !w.bookable ||
                    (everyone.includes(w.id) && w.id !== current),
                note: note(w),
                booked: bookedElsewhere(w.id),
            }));
    };

    // Managers and the special referee: anyone bookable who isn't already in the match.
    const helperOptions = (current: number | null): PickerOption[] =>
        roster.map((w) => ({
            wrestler: w,
            disabled:
                !w.bookable || (everyone.includes(w.id) && w.id !== current),
            note: note(w),
            booked: bookedElsewhere(w.id),
        }));

    // One side of the match: its wrestlers and, where the type has one,
    // its manager.
    const sideCell = (
        side: (number | null)[],
        sideIndex: number,
        className: string,
    ) =>
        type ? (
            <div className={cn('space-y-2', className)}>
                {side.map((id, index) => (
                    <CompetitorSlot
                        key={index}
                        testId={`slot-${draft.position}-side-${sideIndex}-${index}`}
                        label={`${label} side ${sideIndex + 1} wrestler ${index + 1}`}
                        id={id}
                        heading={competitorHeading(
                            type,
                            draft.position,
                            sideIndex,
                            index,
                        )}
                        options={competitorOptions(sideIndex, index)}
                        fallbackName={id !== null ? names?.[id] : undefined}
                        readOnly={readOnly}
                        onChange={(next) =>
                            setCompetitor(sideIndex, index, next)
                        }
                        details={
                            id !== null && rosterById.has(id) ? (
                                <CompetitorDetails
                                    wrestler={rosterById.get(id) as RosterEntry}
                                    type={type}
                                    fatigue={projectedFatigue(
                                        id,
                                        draft.position,
                                        drafts,
                                        types,
                                        rosterById,
                                        fatigueConfig,
                                        promoFatigue,
                                    )}
                                    config={fatigueConfig}
                                    matches={counts.get(id) ?? 0}
                                    champion={champions.includes(id)}
                                />
                            ) : null
                        }
                        multi={id !== null && (counts.get(id) ?? 0) > 1}
                    />
                ))}
                {type.manager_sides.includes(sideIndex) && (
                    <div data-testid={`manager-${draft.position}-${sideIndex}`}>
                        <div className="gm-label mb-1 text-gold">Manager</div>
                        <WrestlerPicker
                            label={`${label} manager for side ${sideIndex + 1}`}
                            value={draft.managers[sideIndex] ?? null}
                            options={helperOptions(
                                draft.managers[sideIndex] ?? null,
                            )}
                            required
                            fallbackName={
                                names?.[draft.managers[sideIndex] ?? '']
                            }
                            disabled={readOnly}
                            onChange={(next) =>
                                onChange({
                                    ...draft,
                                    managers: {
                                        ...draft.managers,
                                        [sideIndex]: next,
                                    },
                                })
                            }
                        />
                    </div>
                )}
            </div>
        ) : null;

    const setCompetitor = (side: number, index: number, id: number | null) => {
        const sides = draft.sides.map((s) => [...s]);
        sides[side][index] = id;
        const managers = { ...draft.managers };

        for (const [s, m] of Object.entries(managers)) {
            if (m !== null && m === id) {
                managers[Number(s)] = null;
            }
        }

        onChange({
            ...draft,
            sides,
            managers,
            refereeId: draft.refereeId === id ? null : draft.refereeId,
        });
    };

    return (
        <section
            aria-label={label}
            data-dirty={dirty || undefined}
            className={cn(
                'gm-panel',
                isMainEvent && 'shadow-[0_0_0_1px_var(--color-gold)]',
                dirty && 'gm-panel-dirty',
            )}
        >
            {/* A size container: the match type panel sizes to the card. */}
            <header className="@container relative flex flex-wrap items-start gap-3 border-b border-white/6 px-4 py-2.5">
                {/* Small screens: the label, the type picker and the cost line each get a line. */}
                <div className="w-full sm:w-auto">
                    <span
                        className={cn(
                            'gm-chyron mt-0.5 inline-block py-1 pr-7 pl-3 font-condensed font-bold tracking-[0.18em] uppercase',
                            isMainEvent
                                ? 'bg-gold text-arena'
                                : 'bg-brand text-white',
                        )}
                    >
                        {isMainEvent ? 'Main event' : label}
                    </span>
                </div>
                <MatchTypePicker
                    position={draft.position}
                    type={type}
                    matchTypes={matchTypes}
                    disabled={readOnly}
                    onPick={(next) =>
                        onChange({
                            ...draft,
                            matchTypeId: next.id,
                            sides: emptySides(next),
                            managers: {},
                            titleChampionshipId: null,
                            stipulation: null,
                            stipulationChampionshipId: null,
                            refereeId: null,
                        })
                    }
                />
                {/* md to lg: the cost line and the title share a row under the type picker. */}
                <div className="contents md:flex md:w-full md:items-start md:justify-between md:gap-3 lg:contents">
                    {type && (
                        <span
                            className="mt-1.5 flex w-full items-center justify-between gap-3 font-condensed tracking-wider text-ink-dim uppercase md:w-auto md:justify-start"
                            data-testid={`type-info-${draft.position}`}
                        >
                            <span>
                                Cost <MatchCost cost={type.cost} />
                            </span>
                            <span>
                                Fatigue{' '}
                                <FatigueCost bars={type.fatigue_cost_bars} />
                            </span>
                        </span>
                    )}
                    {title && (
                        <div className="mt-0.5 w-full sm:ml-auto md:w-auto">
                            {title}
                        </div>
                    )}
                </div>
            </header>

            {type ? (
                <div className="space-y-3 p-4">
                    <SideGrid
                        columns={sideColumns(draft.sides.length)}
                        count={draft.sides.length}
                        testId={`sides-${draft.position}`}
                        renderSide={(sideIndex) =>
                            sideCell(
                                draft.sides[sideIndex],
                                sideIndex,
                                'min-w-0',
                            )
                        }
                    />
                    {type.special_referee && (
                        <div
                            className="max-w-xs"
                            data-testid={`referee-${draft.position}`}
                        >
                            <div className="gm-label mb-1 text-gold">
                                Special referee
                            </div>
                            <WrestlerPicker
                                label={`${label} special referee`}
                                value={draft.refereeId}
                                options={helperOptions(draft.refereeId)}
                                required
                                fallbackName={names?.[draft.refereeId ?? '']}
                                disabled={readOnly}
                                onChange={(next) =>
                                    onChange({ ...draft, refereeId: next })
                                }
                            />
                        </div>
                    )}
                </div>
            ) : (
                <p className="px-4 py-5 text-sm text-ink-faint">
                    Choose a match type to start booking.
                </p>
            )}

            {(canSave || buttons || actions) && (
                <SlotActions
                    name={label.toLowerCase()}
                    editable={canSave}
                    dirty={dirty}
                    ready={complete}
                    filled={draft.matchTypeId !== null}
                    saveStatus={saveStatus}
                    clearMessage="This removes the match type, every wrestler, the title and the stipulation from this slot."
                    onSave={onSave}
                    onDiscard={onDiscard}
                    onClear={onClear}
                    buttons={buttons}
                    className="gap-3 border-t border-white/6 px-4 py-2.5"
                >
                    {actions}
                </SlotActions>
            )}
        </section>
    );
}

/**
 * Pick a match type in two steps: the family, then one of its variants.
 */
/**
 * Sides per row. Up to 3 sides share one row; 4 sides go 2 × 2 and anything
 * bigger in rows of 3, so every side sits under the one above it.
 */
function sideColumns(sides: number): number {
    if (sides <= 3) {
        return sides;
    }

    return sides === 4 ? 2 : 3;
}

/** Width of the "vs" column between two sides on the grid. */
const VERSUS_WIDTH = '2.5rem';

function Versus() {
    return (
        <div className="gm-heading flex items-center justify-center px-1 text-xl text-ink-faint italic">
            vs
        </div>
    );
}

/**
 * Sides in rows of `columns`, with "vs" between the sides of a row. A short
 * last row keeps the same columns, so it lines up with the rows above. On
 * small screens the sides stack.
 */
function SideGrid({
    columns,
    count,
    testId,
    renderSide,
}: {
    columns: number;
    count: number;
    testId: string;
    renderSide: (sideIndex: number) => React.ReactNode;
}) {
    const rows: number[][] = [];

    for (let i = 0; i < count; i += columns) {
        rows.push(
            Array.from(
                { length: Math.min(columns, count - i) },
                (_, k) => i + k,
            ),
        );
    }

    // 1fr vs 1fr (vs 1fr)…: a column per side with a "vs" between them. Each
    // row is its own grid, so the "vs" columns get a fixed width: as `auto`,
    // a short last row's missing "vs" collapsed to 0 and its sides grew wider
    // than the full rows above.
    const template = Array.from(
        { length: columns },
        () => 'minmax(0,1fr)',
    ).join(` ${VERSUS_WIDTH} `);

    return (
        <div className="space-y-3" data-testid={testId}>
            {rows.map((row, r) => (
                <div
                    key={r}
                    data-testid={`${testId}-row-${r}`}
                    className="flex flex-col gap-3 md:grid md:items-stretch"
                    style={{ gridTemplateColumns: template }}
                >
                    {/* Stacked on small screens, rows need a "vs" between them too. */}
                    {r > 0 && (
                        <div className="md:hidden">
                            <Versus />
                        </div>
                    )}
                    {row.map((sideIndex, c) => (
                        <div key={sideIndex} className="contents">
                            {c > 0 && <Versus />}
                            {renderSide(sideIndex)}
                        </div>
                    ))}
                </div>
            ))}
        </div>
    );
}

function MatchTypePicker({
    position,
    type,
    matchTypes,
    disabled,
    onPick,
}: {
    position: number;
    type: MatchTypeOption | undefined;
    matchTypes: MatchTypeOption[];
    disabled: boolean;
    onPick: (type: MatchTypeOption) => void;
}) {
    const { open, mounted, ref, toggle, close } = usePopover();
    const groups = useMemo(() => groupByFamily(matchTypes), [matchTypes]);
    const [family, setFamily] = useState<MatchTypeOption['family'] | null>(
        type?.family ?? null,
    );
    const variants = groups.find(([f]) => f === family)?.[1] ?? [];
    const label = `Match type for match ${position}`;

    return (
        // Below 56rem the panel is placed against the slot header (the size
        // container) and spans it; from 56rem it hangs under this picker.
        <div
            ref={ref}
            className="mt-0.75 min-w-64 flex-1 basis-full sm:basis-auto @4xl:relative"
        >
            <button
                type="button"
                aria-label={label}
                aria-haspopup="dialog"
                aria-expanded={open}
                disabled={disabled}
                onClick={() => {
                    setFamily(type?.family ?? family);
                    toggle();
                }}
                className={cn(
                    'gm-input flex w-full items-center justify-between gap-2 py-1 text-left transition-colors',
                    !disabled && 'hover:border-white/25',
                    disabled && 'cursor-default opacity-70',
                )}
            >
                <span className={cn(!type && 'text-ink-faint')}>
                    {type ? type.variant : 'Choose a match type…'}
                </span>
                {!disabled && (
                    <span aria-hidden className="text-xs text-ink-faint">
                        ▾
                    </span>
                )}
            </button>
            {type && (
                <p
                    data-testid={`match-type-path-${position}`}
                    className="mt-1 font-condensed text-sm tracking-wide text-ink-dim"
                >
                    {MatchFamilyLabels[type.family]}{' '}
                    <span className="text-brand-hot">→</span>{' '}
                    <span className="text-ink">{type.variant}</span>
                </p>
            )}
            {mounted && (
                <PopoverPanel
                    open={open}
                    role="dialog"
                    aria-label={label}
                    className="inset-x-4 grid grid-cols-[minmax(0,8rem)_minmax(0,1fr)] @md:grid-cols-[minmax(0,11rem)_minmax(0,1fr)] @4xl:right-auto @4xl:left-0 @4xl:w-[40rem]"
                >
                    <div
                        role="listbox"
                        aria-label="Match categories"
                        className="gm-scroll max-h-80 overflow-y-auto border-r border-white/8 py-1"
                    >
                        {groups.map(([f, options]) => (
                            <button
                                key={f}
                                type="button"
                                role="option"
                                aria-selected={f === family}
                                onClick={() => setFamily(f)}
                                className={cn(
                                    'flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left font-condensed text-sm tracking-wide uppercase',
                                    f === family
                                        ? 'bg-brand text-white'
                                        : 'text-ink-dim hover:bg-white/6 hover:text-ink',
                                )}
                            >
                                <span>{MatchFamilyLabels[f]}</span>
                                <span className="text-xs opacity-70">
                                    {options.length}
                                </span>
                            </button>
                        ))}
                    </div>
                    {family ? (
                        <div
                            key={family}
                            role="listbox"
                            aria-label={`${MatchFamilyLabels[family]} matches`}
                            className="gm-scroll max-h-80 animate-gm-pop-in overflow-y-auto py-1"
                        >
                            {variants.map((t) => (
                                <button
                                    key={t.id}
                                    type="button"
                                    role="option"
                                    aria-selected={t.id === type?.id}
                                    onClick={() => {
                                        onPick(t);
                                        close();
                                    }}
                                    className={cn(
                                        // Narrow cards: the cost and fatigue go under the name.
                                        'flex w-full flex-col items-start gap-1 px-3 py-1.5 text-left text-sm hover:bg-white/6 @xl:flex-row @xl:items-center @xl:justify-between @xl:gap-3',
                                        t.id === type?.id && 'bg-brand/20',
                                    )}
                                >
                                    <span>{t.variant}</span>
                                    <span className="flex shrink-0 items-center gap-2 font-condensed text-xs tracking-wider uppercase">
                                        <MatchCost cost={t.cost} />
                                        <FatigueCost
                                            bars={t.fatigue_cost_bars}
                                        />
                                    </span>
                                </button>
                            ))}
                        </div>
                    ) : (
                        <p className="p-4 text-sm text-ink-faint">
                            Pick a category to see its matches.
                        </p>
                    )}
                </PopoverPanel>
            )}
        </div>
    );
}

type SlotHeading = { text: string; testId: string };

/** "1st", "2nd", "3rd", "4th"… */
export function ordinal(n: number): string {
    const tens = n % 100;
    const suffix =
        tens >= 11 && tens <= 13
            ? 'th'
            : (({ 1: 'st', 2: 'nd', 3: 'rd' } as Record<number, string>)[
                  n % 10
              ] ?? 'th');

    return `${n}${suffix}`;
}

/**
 * The label over a competitor's picker: the captain (first on each side) in
 * Captain Fall, or each entrant's order in a Gauntlet, where 1st and 2nd
 * open and the winner of each bout meets the next.
 */
function competitorHeading(
    type: MatchTypeOption,
    position: number,
    sideIndex: number,
    index: number,
): SlotHeading | undefined {
    if (type.captain_fall && index === 0) {
        return { text: 'Captain', testId: `captain-${position}-${sideIndex}` };
    }

    if (type.family === 'gauntlet') {
        return {
            text: `${ordinal(sideIndex + 1)} Participant`,
            testId: `entrant-${position}-${sideIndex}`,
        };
    }

    return undefined;
}

function CompetitorSlot({
    testId,
    label,
    id,
    heading,
    options,
    fallbackName,
    readOnly,
    onChange,
    details,
    multi,
}: {
    testId: string;
    label: string;
    id: number | null;
    /** A gold label above the picker: "Captain", "1st Participant"… */
    heading?: SlotHeading;
    options: PickerOption[];
    fallbackName?: string;
    readOnly: boolean;
    onChange: (id: number | null) => void;
    details: React.ReactNode;
    multi: boolean;
}) {
    return (
        <div
            data-testid={testId}
            className={cn(
                'border border-white/8 bg-black/25 p-2',
                multi && 'border-zone-minor/60 bg-zone-minor/[0.07]',
            )}
        >
            {heading && (
                <div
                    className="gm-label mb-1 text-gold"
                    data-testid={heading.testId}
                >
                    {heading.text}
                </div>
            )}
            <WrestlerPicker
                label={label}
                value={id}
                options={options}
                required
                fallbackName={fallbackName}
                disabled={readOnly}
                onChange={onChange}
            />
            {details}
        </div>
    );
}

function CompetitorDetails({
    wrestler,
    type,
    fatigue,
    config,
    matches,
    champion,
}: {
    wrestler: RosterEntry;
    type: MatchTypeOption;
    fatigue: { goingIn: number; after: number };
    config: FatigueConfig;
    matches: number;
    /** Defending the title on the line in this match. */
    champion: boolean;
}) {
    const goingInZone = fatigueZone(fatigue.goingIn, config);

    return (
        <div className="mt-2 space-y-1.5">
            <div className="flex flex-wrap items-center gap-1.5">
                {wrestler.specialties
                    .filter((s) => s === type.family)
                    .map((s) => (
                        <SpecialtyBadge key={s} family={s} active />
                    ))}
                {matches > 1 && (
                    <span
                        className="bg-zone-minor px-1.5 py-px font-condensed font-bold tracking-widest text-arena uppercase"
                        title="Booked in more than one match: each one adds fatigue and injury risk"
                    >
                        ×{matches} matches
                    </span>
                )}
            </div>
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <FatigueMeter
                    fatigue={fatigue.goingIn}
                    projected={fatigue.after}
                    config={config}
                    showLabel
                />
                {champion && <GoldTag className="ml-auto">Champion</GoldTag>}
            </div>
            {goingInZone !== 'safe' && (
                <p
                    className={cn(
                        'font-condensed tracking-wide uppercase',
                        goingInZone === 'serious_risk'
                            ? 'text-zone-serious'
                            : 'text-zone-minor',
                    )}
                >
                    Going in: {zoneLabel[goingInZone]}
                    {goingInZone === 'serious_risk'
                        ? ' · guaranteed serious injury'
                        : ' · minor injury roll'}
                </p>
            )}
        </div>
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
