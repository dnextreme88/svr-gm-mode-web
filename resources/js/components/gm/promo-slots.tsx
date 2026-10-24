import { X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { FatigueCost } from '@/components/gm/fatigue-cost';
import { Panel } from '@/components/gm/panel';
import { WrestlerPicker } from '@/components/gm/wrestler-picker';
import type { PickerOption } from '@/components/gm/wrestler-picker';
import { formatMoney } from '@/lib/gm';
import { cn } from '@/lib/utils';
import type { RosterEntry } from '@/types/gm';

export type PromoTypeOption = {
    id: number;
    key: string;
    name: string;
    amount: number;
    success_rate: number;
    /** Lowest and highest odds when they depend on a rolled tier (Merchandise). */
    success_range?: [number, number] | null;
    needs_wrestler: boolean;
    needs_rival_pair: boolean;
    needs_diva_pair?: boolean;
    needs_championship?: boolean;
    fatigue_bars?: number;
    recovery_bars?: number;
    targets_rival: boolean;
    variable_amount: boolean;
    effect: string;
    requirement?: string | null;
};

export type PromoSlotView = {
    position: number;
    promo_type_id: number | null;
    before_position?: number | null;
    target_wrestler_id: number | null;
    target_wrestler_b_id: number | null;
    target_championship_id?: number | null;
    succeeded: boolean | null;
    backfired: boolean;
    outcome_note: string | null;
};

/** Who or what a promo can target on this show, from PromoEligibility (§5). */
export type PromoEligibilityView = {
    available: boolean;
    reason: string | null;
    wrestler_ids: number[];
    pairs: Array<[number, number]>;
    championship_ids: number[];
};

export type PromoPayload = {
    promo_type_id: number;
    target_wrestler_id: number | null;
    target_wrestler_b_id: number | null;
    target_championship_id: number | null;
    before_position: number;
};

type Props = {
    slots: PromoSlotView[];
    types: PromoTypeOption[];
    eligibility: Record<string, PromoEligibilityView>;
    roster: RosterEntry[];
    /** Match slots on the card; the last one is the main event. */
    matchSlotCount: number;
    titleNames: Record<number, string>;
    readOnly: boolean;
    onSave: (position: number, payload: PromoPayload) => void;
    onClear: (position: number) => void;
};

/**
 * The two weekly promo slots (§5): every promo on a grid with its cost and
 * success probability, greyed out when nobody on the roster can do it.
 */
export function PromoSlots({ slots, ...props }: Props) {
    return (
        <div className="grid gap-4 xl:grid-cols-2">
            {slots.map((slot) => (
                <PromoEditor
                    key={slot.position}
                    slot={slot}
                    taken={takenSpots(
                        slots,
                        slot.position,
                        props.matchSlotCount,
                    )}
                    {...props}
                />
            ))}
        </div>
    );
}

/**
 * House rule: two promos can't air back to back, so each spot (the match a
 * promo airs before) holds one promo. Maps each spot the other saved promos
 * hold to the promo holding it.
 */
export function takenSpots(
    slots: PromoSlotView[],
    position: number,
    matchSlotCount: number,
): Map<number, number> {
    return new Map(
        slots
            .filter((s) => s.position !== position && s.promo_type_id !== null)
            .map((s) => [s.before_position ?? matchSlotCount, s.position]),
    );
}

/** The main event's spot, or the latest one still free. */
function defaultSpot(
    taken: Map<number, number>,
    matchSlotCount: number,
): number {
    for (let spot = matchSlotCount; spot >= 1; spot--) {
        if (!taken.has(spot)) {
            return spot;
        }
    }

    return matchSlotCount;
}

function amountLabel(t: PromoTypeOption): string {
    if (t.variable_amount) {
        return '+ variable';
    }

    if (t.amount === 0) {
        return '$0';
    }

    return `${t.amount > 0 ? '+' : '−'}${formatMoney(Math.abs(t.amount))}`;
}

export function successLabel(t: {
    success_rate: number;
    success_range?: [number, number] | null;
}): string {
    return t.success_range
        ? `${Math.round(t.success_range[0] * 100)}–${Math.round(t.success_range[1] * 100)}%`
        : `${Math.round(t.success_rate * 100)}%`;
}

export function airsLabel(before: number, matchSlotCount: number): string {
    return before === matchSlotCount
        ? 'Before main event'
        : `Before match ${before}`;
}

function PromoEditor({
    slot,
    taken,
    types,
    eligibility,
    roster,
    matchSlotCount,
    titleNames,
    readOnly,
    onSave,
    onClear,
}: { slot: PromoSlotView; taken: Map<number, number> } & Omit<Props, 'slots'>) {
    const [typeId, setTypeId] = useState<number | null>(slot.promo_type_id);
    const [a, setA] = useState<number | null>(slot.target_wrestler_id);
    const [b, setB] = useState<number | null>(slot.target_wrestler_b_id);
    const [title, setTitle] = useState<number | null>(
        slot.target_championship_id ?? null,
    );
    const [confirmClear, setConfirmClear] = useState(false);
    const [before, setBefore] = useState<number>(
        slot.before_position ?? defaultSpot(taken, matchSlotCount),
    );

    const sorted = useMemo(
        () => [...types].sort((x, y) => x.name.localeCompare(y.name)),
        [types],
    );
    const type = types.find((t) => t.id === typeId);
    const rules = type ? eligibility[type.key] : undefined;
    const pair = Boolean(type?.needs_rival_pair || type?.needs_diva_pair);
    const isContinue = type?.key === 'continue_rivalry';
    const rosterById = new Map(roster.map((w) => [w.id, w]));

    const optionsFor = (ids: number[]): PickerOption[] =>
        ids
            .map((id) => rosterById.get(id))
            .filter((w): w is RosterEntry => w !== undefined)
            .sort((x, y) => x.name.localeCompare(y.name))
            .map((w) => ({ wrestler: w }));

    const firstIds = rules?.wrestler_ids ?? [];
    const secondIds = isContinue
        ? (rules?.pairs ?? [])
              .filter(([x, y]) => x === a || y === a)
              .map(([x, y]) => (x === a ? y : x))
        : firstIds.filter((id) => id !== a);

    const ready =
        type !== undefined &&
        (rules?.available ?? true) &&
        (!type.needs_wrestler || a !== null) &&
        (!pair || (a !== null && b !== null && a !== b)) &&
        (!type.needs_championship || title !== null) &&
        !taken.has(before);

    const choose = (t: PromoTypeOption) => {
        setTypeId(t.id);
        setA(null);
        setB(null);
        setTitle(null);
    };

    return (
        <Panel title={`Promo ${slot.position}`}>
            <div
                role="radiogroup"
                aria-label={`Promo ${slot.position} type`}
                className="grid grid-cols-2 gap-1.5"
            >
                {sorted.map((t) => {
                    const off = eligibility[t.key]?.available === false;
                    const selected = t.id === typeId;

                    return (
                        <button
                            key={t.id}
                            type="button"
                            role="radio"
                            aria-checked={selected}
                            disabled={readOnly || off}
                            title={
                                off
                                    ? (eligibility[t.key]?.reason ?? undefined)
                                    : t.effect
                            }
                            onClick={() => choose(t)}
                            data-testid={`promo-${slot.position}-${t.key}`}
                            className={cn(
                                'border border-white/8 bg-black/25 px-2.5 py-2 text-left transition-colors',
                                selected
                                    ? 'border-brand bg-brand/15 shadow-[0_0_0_1px_var(--gm-brand)]'
                                    : !off &&
                                          !readOnly &&
                                          'hover:border-white/25 hover:bg-white/4',
                                off && 'cursor-not-allowed opacity-40',
                            )}
                        >
                            <span className="block font-condensed font-bold tracking-wide uppercase">
                                {t.name}
                            </span>
                            <span className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-ink-dim">
                                <span
                                    className={cn(
                                        'gm-num',
                                        (t.amount > 0 || t.variable_amount) &&
                                            'text-zone-safe',
                                    )}
                                >
                                    {amountLabel(t)}
                                </span>
                                <span>
                                    Success Probability:{' '}
                                    <span className="gm-num text-ink">
                                        {successLabel(t)}
                                    </span>
                                </span>
                                {(t.recovery_bars ?? 0) > 0 && (
                                    <FatigueCost
                                        bars={t.recovery_bars ?? 0}
                                        max={t.recovery_bars}
                                        recovers
                                    />
                                )}
                                {(t.fatigue_bars ?? 0) > 0 && (
                                    <FatigueCost
                                        bars={t.fatigue_bars ?? 0}
                                        max={t.fatigue_bars}
                                    />
                                )}
                            </span>
                            {off && eligibility[t.key]?.reason && (
                                <span className="sr-only">
                                    {' '}
                                    Unavailable: {eligibility[t.key]?.reason}
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>

            {type && (
                <div
                    key={type.id}
                    data-testid={`promo-details-${slot.position}`}
                    // The fade-in leaves a transform, which makes this a
                    // stacking context: lift it so its pickers' lists sit
                    // above the buttons and the next promo panel.
                    className="relative z-20 mt-3 animate-gm-pop-in space-y-3 border-t border-white/8 pt-3"
                >
                    <div className="text-sm">
                        <p className="font-condensed font-bold tracking-wide uppercase">
                            {type.name}
                        </p>
                        <p className="text-ink-dim">{type.effect}</p>
                        {type.requirement && (
                            <p className="mt-1 text-xs text-ink-faint">
                                Needs: {type.requirement}
                            </p>
                        )}
                    </div>

                    {(type.needs_wrestler || pair) && (
                        <div className="grid gap-2 sm:grid-cols-2">
                            <div>
                                <div className="gm-label mb-1">
                                    {pair ? 'First wrestler' : 'Wrestler'}
                                </div>
                                <WrestlerPicker
                                    label={`Promo ${slot.position} ${pair ? 'first wrestler' : 'wrestler'}`}
                                    value={a}
                                    options={optionsFor(firstIds)}
                                    disabled={readOnly}
                                    onChange={(id) => {
                                        setA(id);

                                        if (isContinue) {
                                            setB(null);
                                        }
                                    }}
                                />
                            </div>
                            {pair && (
                                <div>
                                    <div className="gm-label mb-1">
                                        Second wrestler
                                    </div>
                                    <WrestlerPicker
                                        label={`Promo ${slot.position} second wrestler`}
                                        value={b}
                                        options={optionsFor(secondIds)}
                                        disabled={readOnly || a === null}
                                        onChange={setB}
                                    />
                                </div>
                            )}
                        </div>
                    )}

                    {type.needs_championship && (
                        <div>
                            <div className="gm-label mb-1">Title to hype</div>
                            <div
                                role="radiogroup"
                                aria-label={`Promo ${slot.position} title`}
                                className="flex flex-wrap gap-1.5"
                            >
                                {(rules?.championship_ids ?? []).map((id) => (
                                    <button
                                        key={id}
                                        type="button"
                                        role="radio"
                                        aria-checked={title === id}
                                        disabled={readOnly}
                                        onClick={() => setTitle(id)}
                                        className={cn(
                                            'px-3 py-1 font-condensed text-xs font-bold tracking-[0.14em] uppercase',
                                            title === id
                                                ? 'bg-gradient-to-b from-gold-hot to-gold text-arena'
                                                : 'bg-white/5 text-ink-dim hover:text-ink',
                                        )}
                                    >
                                        ◆ {titleNames[id] ?? `Title ${id}`}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    <div>
                        <div className="gm-label mb-1">Airs</div>
                        <div
                            role="radiogroup"
                            aria-label={`Promo ${slot.position} airs`}
                            className="flex flex-wrap gap-1.5"
                        >
                            {Array.from(
                                { length: matchSlotCount },
                                (_, i) => i + 1,
                            ).map((position) => {
                                const holder = taken.get(position);

                                return (
                                    <button
                                        key={position}
                                        type="button"
                                        role="radio"
                                        aria-checked={before === position}
                                        disabled={
                                            readOnly || holder !== undefined
                                        }
                                        data-taken={
                                            holder !== undefined || undefined
                                        }
                                        title={
                                            holder === undefined
                                                ? undefined
                                                : `Promo ${holder} already airs here`
                                        }
                                        onClick={() => setBefore(position)}
                                        className={cn(
                                            'inline-flex items-center gap-1 px-2.5 py-1 font-condensed text-xs font-semibold tracking-wider uppercase',
                                            holder !== undefined
                                                ? 'cursor-not-allowed bg-white/[0.02] text-ink-faint'
                                                : before === position
                                                  ? 'bg-brand text-white'
                                                  : 'bg-white/5 text-ink-dim hover:text-ink',
                                        )}
                                    >
                                        {holder !== undefined && (
                                            <X
                                                aria-hidden
                                                strokeWidth={3}
                                                className="size-3 text-zone-serious"
                                            />
                                        )}
                                        {airsLabel(position, matchSlotCount)}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </div>
            )}

            {slot.outcome_note && (
                <p
                    className={cn(
                        'mt-2 text-xs font-semibold',
                        slot.succeeded ? 'text-zone-safe' : 'text-zone-serious',
                    )}
                >
                    {slot.outcome_note}
                </p>
            )}
            {!readOnly && (
                <div className="mt-3 flex gap-2">
                    <button
                        type="button"
                        className="gm-btn gm-btn-sm"
                        disabled={!ready}
                        onClick={() =>
                            type &&
                            onSave(slot.position, {
                                promo_type_id: type.id,
                                target_wrestler_id:
                                    type.needs_wrestler || pair ? a : null,
                                target_wrestler_b_id: pair ? b : null,
                                target_championship_id: type.needs_championship
                                    ? title
                                    : null,
                                before_position: before,
                            })
                        }
                    >
                        Save promo {slot.position}
                    </button>
                    <button
                        type="button"
                        className="gm-btn gm-btn-danger gm-btn-sm"
                        disabled={slot.promo_type_id === null}
                        onClick={() => setConfirmClear(true)}
                    >
                        Clear
                    </button>
                </div>
            )}
            <ConfirmDialog
                open={confirmClear}
                title={`Clear promo ${slot.position}?`}
                message="This removes the promo and its wrestlers from this slot."
                confirmLabel="Yes, clear it"
                danger
                onCancel={() => setConfirmClear(false)}
                onConfirm={() => {
                    setConfirmClear(false);
                    setTypeId(null);
                    onClear(slot.position);
                }}
            />
        </Panel>
    );
}
