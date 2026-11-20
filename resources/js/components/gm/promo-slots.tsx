import { X } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { InjuryChip, TitleMark } from '@/components/gm/badges';
import { FatigueCost } from '@/components/gm/fatigue-cost';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import { Panel } from '@/components/gm/panel';
import { RivalryPicker, sortPair } from '@/components/gm/rivalry-picker';
import { SlotActions } from '@/components/gm/slot-actions';
import { useReportUnsaved } from '@/components/gm/unsaved-changes';
import type { RivalryOption } from '@/components/gm/rivalry-picker';
import { WrestlerPicker } from '@/components/gm/wrestler-picker';
import type { PickerOption } from '@/components/gm/wrestler-picker';
import { formatMoney } from '@/lib/gm';
import { slotSaveKey, useSaveStatus } from '@/lib/save-status';
import { radioKeyDown, useRovingRadios } from '@/lib/radio-group';
import { cn } from '@/lib/utils';
import type { FatigueConfig, RosterEntry } from '@/types/gm';

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
    /** Call-out: how the superstar plays it (house rule, 2026-11-15). */
    alignment?: 'face' | 'heel' | null;
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
    alignment?: 'face' | 'heel' | null;
};

type Props = {
    /** The card's show, so each promo's save can be tracked (`lib/save-status`). */
    showId: number;
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
    /** Draws the Inactive Superstar's fatigue beside its picker (2026-11-17). */
    fatigueConfig?: FatigueConfig;
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
                    others={slots.filter(
                        (s) =>
                            s.position !== slot.position &&
                            s.promo_type_id !== null,
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
        return formatMoney(0);
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
    others,
    types,
    eligibility,
    roster,
    matchSlotCount,
    titleNames,
    readOnly,
    onSave,
    onClear,
    showId,
    fatigueConfig,
}: {
    slot: PromoSlotView;
    taken: Map<number, number>;
    /** The card's other saved promos. */
    others: PromoSlotView[];
} & Omit<Props, 'slots'>) {
    const saveStatus = useSaveStatus(
        slotSaveKey('promo', showId, slot.position),
    );
    const [typeId, setTypeId] = useState<number | null>(slot.promo_type_id);
    const [a, setA] = useState<number | null>(slot.target_wrestler_id);
    const [b, setB] = useState<number | null>(slot.target_wrestler_b_id);
    const [title, setTitle] = useState<number | null>(
        slot.target_championship_id ?? null,
    );
    const [alignment, setAlignment] = useState<'face' | 'heel' | null>(
        slot.alignment ?? null,
    );
    const savedBefore =
        slot.before_position ?? defaultSpot(taken, matchSlotCount);
    const [before, setBefore] = useState<number>(savedBefore);

    const sorted = useMemo(
        () => [...types].sort((x, y) => x.name.localeCompare(y.name)),
        [types],
    );
    const type = types.find((t) => t.id === typeId);
    const rules = type ? eligibility[type.key] : undefined;
    const pair = Boolean(type?.needs_rival_pair || type?.needs_diva_pair);
    const isContinue = type?.key === 'continue_rivalry';
    // Call-out (house rule, 2026-11-15): a rivalry, which of the two does
    // the talking, and whether they play it as a face or a heel.
    const isCallOut = type?.key === 'call_out';
    const pickRivalry = isContinue || isCallOut;
    // Two rival Divas in a Bikini Contest keep their rivalry going.
    const rivalPairs = eligibility.continue_rivalry?.pairs ?? [];
    const rivals = (x: number | null, y: number) =>
        rivalPairs.some(
            ([p, q]) => (p === x && q === y) || (p === y && q === x),
        );
    const rosterById = new Map(roster.map((w) => [w.id, w]));

    // House rule: a wrestler gets one of each promo per card, so anyone in
    // another saved promo of this type can't be picked again.
    const twinOf = new Map<number, number>();

    for (const other of others) {
        if (other.promo_type_id === typeId) {
            for (const id of [
                other.target_wrestler_id,
                other.target_wrestler_b_id,
            ]) {
                if (id !== null) {
                    twinOf.set(id, other.position);
                }
            }
        }
    }

    const optionsFor = (
        ids: number[],
        rivalOf?: number | null,
    ): PickerOption[] =>
        ids
            .map((id) => rosterById.get(id))
            .filter((w): w is RosterEntry => w !== undefined)
            .sort((x, y) => x.name.localeCompare(y.name))
            .map((w) => {
                const twin = twinOf.get(w.id);

                if (twin !== undefined) {
                    return {
                        wrestler: w,
                        disabled: true,
                        note: `In Promo ${twin}`,
                    };
                }

                return rivalOf != null && rivals(rivalOf, w.id)
                    ? { wrestler: w, note: 'Rivals: keeps the heat' }
                    : { wrestler: w };
            });

    // Continue Rivalry picks one of the brand's rivalries, "A vs. B",
    // alphabetically.
    const rivalryOptions: RivalryOption[] = (rules?.pairs ?? [])
        .map(([x, y]) => [rosterById.get(x), rosterById.get(y)] as const)
        .filter(
            (p): p is readonly [RosterEntry, RosterEntry] =>
                p[0] !== undefined && p[1] !== undefined,
        )
        .map(([x, y]) => {
            const pair = sortPair(x, y);
            const twin = twinOf.get(x.id) ?? twinOf.get(y.id);

            return twin === undefined
                ? { pair }
                : { pair, disabled: true, note: `In Promo ${twin}` };
        })
        .sort((p, q) =>
            `${p.pair[0].name} ${p.pair[1].name}`.localeCompare(
                `${q.pair[0].name} ${q.pair[1].name}`,
            ),
        );

    const firstIds = rules?.wrestler_ids ?? [];
    const secondIds = pickRivalry
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
        (!isCallOut || alignment !== null) &&
        !taken.has(before) &&
        (a === null || !twinOf.has(a)) &&
        (b === null || !twinOf.has(b));

    // Nothing changed since the last save: Save stays greyed out, like a
    // saved match.
    // Changed since the last save (or since the slot was empty): Save and
    // Discard light up, like a match.
    const dirty =
        typeId !== slot.promo_type_id ||
        a !== slot.target_wrestler_id ||
        (pair && b !== slot.target_wrestler_b_id) ||
        title !== (slot.target_championship_id ?? null) ||
        (isCallOut && alignment !== (slot.alignment ?? null)) ||
        before !== savedBefore;
    useReportUnsaved(`Promo ${slot.position}`, dirty && !readOnly);

    // Back to what's saved.
    const discard = () => {
        setTypeId(slot.promo_type_id);
        setA(slot.target_wrestler_id);
        setB(slot.target_wrestler_b_id);
        setTitle(slot.target_championship_id ?? null);
        setAlignment(slot.alignment ?? null);
        setBefore(savedBefore);
    };

    const radioRoot = useRef<HTMLDivElement>(null);
    useRovingRadios(radioRoot);

    const choose = (t: PromoTypeOption) => {
        setTypeId(t.id);
        setA(null);
        setB(null);
        setTitle(null);
        setAlignment(null);
    };

    return (
        // Each radio group is one Tab stop; the arrows pick (UI audit, 2026-11-20).
        <div ref={radioRoot}>
            <Panel title={`Promo ${slot.position}`}>
                <div
                    role="radiogroup"
                    aria-label={`Promo ${slot.position} type`}
                    className="grid grid-cols-2 gap-1.5"
                    onKeyDown={radioKeyDown}
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
                                        ? (eligibility[t.key]?.reason ??
                                          undefined)
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
                                    off && 'cursor-not-allowed',
                                )}
                            >
                                <span
                                    className={cn(
                                        'block font-condensed font-bold tracking-wide uppercase',
                                        off && 'opacity-40',
                                    )}
                                >
                                    {t.name}
                                </span>
                                <span
                                    className={cn(
                                        'mt-0.5 flex-wrap items-center gap-x-3 gap-y-0.5 text-sm text-ink-dim',
                                        // An unavailable promo is one line and its
                                        // reason on a phone (UI audit, 2026-11-12).
                                        off
                                            ? 'hidden opacity-40 sm:flex'
                                            : 'flex',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'gm-num',
                                            (t.amount > 0 ||
                                                t.variable_amount) &&
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
                                {off &&
                                    eligibility[t.key]?.reason && (
                                        // The reason in words, not only on hover.
                                        <span
                                            className="mt-1 block text-xs text-ink-dim"
                                            data-testid={`promo-${slot.position}-${t.key}-reason`}
                                        >
                                            <span className="sr-only">
                                                Unavailable:{' '}
                                            </span>
                                            {eligibility[t.key]?.reason}
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
                                <p className="mt-1 text-sm text-ink-faint">
                                    Needs: {type.requirement}
                                </p>
                            )}
                        </div>

                        {pickRivalry && (
                            <div>
                                <div className="gm-label mb-1">Rivalry</div>
                                <RivalryPicker
                                    label={`Promo ${slot.position} rivalry`}
                                    value={
                                        a !== null && b !== null ? [a, b] : null
                                    }
                                    options={rivalryOptions}
                                    disabled={readOnly}
                                    onChange={([x, y]) => {
                                        setA(x);
                                        setB(y);
                                    }}
                                />
                            </div>
                        )}

                        {isCallOut && a !== null && b !== null && (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <div className="gm-label mb-1">
                                        Who does the talking
                                    </div>
                                    <div
                                        role="radiogroup"
                                        onKeyDown={radioKeyDown}
                                        aria-label={`Promo ${slot.position} speaker`}
                                        className="flex flex-wrap gap-1.5"
                                    >
                                        {[a, b].map((id) => {
                                            const speaking = id === a;

                                            return (
                                                <button
                                                    key={id}
                                                    type="button"
                                                    role="radio"
                                                    aria-checked={speaking}
                                                    disabled={readOnly}
                                                    onClick={() => {
                                                        if (!speaking) {
                                                            setB(a);
                                                            setA(id);
                                                        }
                                                    }}
                                                    className={cn(
                                                        'min-h-11 px-3 py-1.5 text-left text-sm sm:min-h-0',
                                                        speaking
                                                            ? 'bg-brand text-white'
                                                            : 'bg-white/[0.05] text-ink-dim hover:text-ink',
                                                    )}
                                                >
                                                    {rosterById.get(id)?.name}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                                <div>
                                    <div className="gm-label mb-1">
                                        Plays it as
                                    </div>
                                    <div
                                        role="radiogroup"
                                        onKeyDown={radioKeyDown}
                                        aria-label={`Promo ${slot.position} alignment`}
                                        className="flex flex-wrap gap-1.5"
                                    >
                                        {(['face', 'heel'] as const).map(
                                            (al) => (
                                                <button
                                                    key={al}
                                                    type="button"
                                                    role="radio"
                                                    aria-checked={
                                                        alignment === al
                                                    }
                                                    disabled={readOnly}
                                                    onClick={() =>
                                                        setAlignment(al)
                                                    }
                                                    className={cn(
                                                        'min-h-11 px-3 py-1.5 text-left text-sm sm:min-h-0',
                                                        alignment === al
                                                            ? 'bg-brand text-white'
                                                            : 'bg-white/[0.05] text-ink-dim hover:text-ink',
                                                    )}
                                                >
                                                    <span className="font-semibold">
                                                        {al === 'face'
                                                            ? 'Face'
                                                            : 'Heel'}
                                                    </span>
                                                    <span className="block text-xs opacity-80">
                                                        {al === 'face'
                                                            ? 'Rally the fans'
                                                            : 'Insult the fans'}
                                                    </span>
                                                </button>
                                            ),
                                        )}
                                    </div>
                                </div>
                            </div>
                        )}

                        {(type.needs_wrestler || pair) && !pickRivalry && (
                            <div className="grid gap-2 sm:grid-cols-2">
                                <div>
                                    <div className="gm-label mb-1">
                                        {pair ? 'First superstar' : 'Superstar'}
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <div className="min-w-0 flex-1">
                                            <WrestlerPicker
                                                label={`Promo ${slot.position} ${pair ? 'first superstar' : 'superstar'}`}
                                                value={a}
                                                options={optionsFor(firstIds)}
                                                required
                                                disabled={readOnly}
                                                onChange={(id) => {
                                                    setA(id);

                                                    if (isContinue) {
                                                        setB(null);
                                                    }
                                                }}
                                            />
                                        </div>
                                        {/* House rule (2026-11-17): how tired the
                                        superstar the promo rests is. */}
                                        {type?.key === 'inactive_superstar' &&
                                            a !== null &&
                                            fatigueConfig &&
                                            rosterById.has(a) && (
                                                <div
                                                    className="shrink-0"
                                                    data-testid={`promo-${slot.position}-fatigue`}
                                                >
                                                    <FatigueMeter
                                                        fatigue={
                                                            rosterById.get(a)
                                                                ?.fatigue ?? 0
                                                        }
                                                        config={fatigueConfig}
                                                        showLabel
                                                    />
                                                </div>
                                            )}
                                        {/* House rule (2026-11-20): the injury
                                        the treatment is for. */}
                                        {type?.key === 'speed_up_injury' &&
                                            a !== null &&
                                            rosterById.get(a)?.injury && (
                                                <div
                                                    className="shrink-0"
                                                    data-testid={`promo-${slot.position}-injury`}
                                                >
                                                    <InjuryChip
                                                        injury={
                                                            rosterById.get(a)
                                                                ?.injury ?? null
                                                        }
                                                    />
                                                </div>
                                            )}
                                    </div>
                                </div>
                                {pair && (
                                    <div>
                                        <div className="gm-label mb-1">
                                            Second superstar
                                        </div>
                                        <WrestlerPicker
                                            label={`Promo ${slot.position} second superstar`}
                                            value={b}
                                            options={optionsFor(
                                                secondIds,
                                                type.needs_diva_pair ? a : null,
                                            )}
                                            required
                                            disabled={readOnly || a === null}
                                            onChange={setB}
                                        />
                                    </div>
                                )}
                            </div>
                        )}

                        {type.needs_championship && (
                            <div>
                                <div className="gm-label mb-1">
                                    Title to hype
                                </div>
                                <div
                                    role="radiogroup"
                                    onKeyDown={radioKeyDown}
                                    aria-label={`Promo ${slot.position} title`}
                                    className="flex flex-wrap gap-1.5"
                                >
                                    {(rules?.championship_ids ?? []).map(
                                        (id) => (
                                            <button
                                                key={id}
                                                type="button"
                                                role="radio"
                                                aria-checked={title === id}
                                                disabled={readOnly}
                                                onClick={() => setTitle(id)}
                                                className={cn(
                                                    'inline-flex items-center gap-1.5 px-3 py-1 font-condensed text-sm font-bold tracking-[0.14em] uppercase',
                                                    title === id
                                                        ? 'bg-gradient-to-b from-gold-hot to-gold text-arena'
                                                        : 'bg-white/5 text-ink-dim hover:text-ink',
                                                )}
                                            >
                                                <TitleMark
                                                    name={titleNames[id] ?? ''}
                                                />
                                                {titleNames[id] ??
                                                    `Title ${id}`}
                                            </button>
                                        ),
                                    )}
                                </div>
                            </div>
                        )}

                        <div>
                            <div className="gm-label mb-1">Airs</div>
                            <div
                                role="radiogroup"
                                onKeyDown={radioKeyDown}
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
                                                holder !== undefined ||
                                                undefined
                                            }
                                            title={
                                                holder === undefined
                                                    ? undefined
                                                    : `Promo ${holder} already airs here`
                                            }
                                            onClick={() => setBefore(position)}
                                            className={cn(
                                                'inline-flex items-center gap-1 px-2.5 py-1 font-condensed text-sm font-semibold tracking-wider uppercase',
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
                                            {airsLabel(
                                                position,
                                                matchSlotCount,
                                            )}
                                            {holder !== undefined && (
                                                <span className="sr-only">
                                                    , taken by Promo {holder}
                                                </span>
                                            )}
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
                            'mt-2 text-sm font-semibold',
                            slot.succeeded
                                ? 'text-zone-safe'
                                : 'text-zone-serious',
                        )}
                    >
                        {slot.outcome_note}
                    </p>
                )}
                {/* An empty promo has nothing to save, discard or clear. */}
                {!readOnly &&
                    (typeId !== null || slot.promo_type_id !== null) && (
                        <SlotActions
                            name={`promo ${slot.position}`}
                            dirty={dirty}
                            ready={ready}
                            filled={slot.promo_type_id !== null}
                            saveStatus={saveStatus}
                            clearMessage="This removes the promo and its superstars from this slot."
                            onSave={() =>
                                type &&
                                onSave(slot.position, {
                                    promo_type_id: type.id,
                                    target_wrestler_id:
                                        type.needs_wrestler || pair ? a : null,
                                    target_wrestler_b_id: pair ? b : null,
                                    target_championship_id:
                                        type.needs_championship ? title : null,
                                    before_position: before,
                                    ...(isCallOut ? { alignment } : {}),
                                })
                            }
                            onDiscard={discard}
                            onClear={() => {
                                setTypeId(null);
                                onClear(slot.position);
                            }}
                            className="mt-3 gap-2"
                        />
                    )}
            </Panel>
        </div>
    );
}
