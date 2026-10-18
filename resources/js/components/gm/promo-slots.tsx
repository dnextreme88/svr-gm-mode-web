import { useState } from 'react';
import { Panel } from '@/components/gm/panel';
import { formatMoney } from '@/lib/gm';
import { cn } from '@/lib/utils';
import type { RosterEntry } from '@/types/gm';

export type PromoTypeOption = {
    id: number;
    key: string;
    name: string;
    amount: number;
    success_rate: number;
    needs_wrestler: boolean;
    needs_rival_pair: boolean;
    targets_rival: boolean;
    variable_amount: boolean;
    effect: string;
};

export type PromoSlotView = {
    position: number;
    promo_type_id: number | null;
    target_wrestler_id: number | null;
    target_wrestler_b_id: number | null;
    succeeded: boolean | null;
    backfired: boolean;
    outcome_note: string | null;
};

type Props = {
    slots: PromoSlotView[];
    types: PromoTypeOption[];
    roster: RosterEntry[];
    readOnly: boolean;
    onSave: (
        position: number,
        payload: {
            promo_type_id: number;
            target_wrestler_id: number | null;
            target_wrestler_b_id: number | null;
        },
    ) => void;
    onClear: (position: number) => void;
};

/**
 * The two weekly promo slots (§5): cost/profit and success rate up front.
 */
export function PromoSlots({
    slots,
    types,
    roster,
    readOnly,
    onSave,
    onClear,
}: Props) {
    return (
        <div className="grid gap-4 md:grid-cols-2">
            {slots.map((slot) => (
                <PromoEditor
                    key={slot.position}
                    slot={slot}
                    types={types}
                    roster={roster}
                    readOnly={readOnly}
                    onSave={onSave}
                    onClear={onClear}
                />
            ))}
        </div>
    );
}

function PromoEditor({
    slot,
    types,
    roster,
    readOnly,
    onSave,
    onClear,
}: { slot: PromoSlotView } & Omit<Props, 'slots'>) {
    const [typeId, setTypeId] = useState<number | null>(slot.promo_type_id);
    const [a, setA] = useState<number | null>(slot.target_wrestler_id);
    const [b, setB] = useState<number | null>(slot.target_wrestler_b_id);
    const type = types.find((t) => t.id === typeId);
    const ready =
        type !== undefined &&
        (!type.needs_wrestler || a !== null) &&
        (!type.needs_rival_pair || (a !== null && b !== null && a !== b));

    const picker = (
        label: string,
        value: number | null,
        set: (v: number | null) => void,
    ) => (
        <select
            aria-label={label}
            className="gm-input w-full py-1 text-sm"
            value={value ?? ''}
            disabled={readOnly}
            onChange={(e) =>
                set(e.target.value ? Number(e.target.value) : null)
            }
        >
            <option value="">{label}…</option>
            {roster.map((w) => (
                <option key={w.id} value={w.id}>
                    {w.name}
                </option>
            ))}
        </select>
    );

    return (
        <Panel title={`Promo ${slot.position}`}>
            <select
                aria-label={`Promo ${slot.position} type`}
                className="gm-input w-full py-1 text-sm"
                value={typeId ?? ''}
                disabled={readOnly}
                onChange={(e) =>
                    setTypeId(e.target.value ? Number(e.target.value) : null)
                }
            >
                <option value="">No promo</option>
                {types.map((t) => (
                    <option key={t.id} value={t.id}>
                        {t.name} ·{' '}
                        {t.variable_amount
                            ? '+ variable'
                            : t.amount === 0
                              ? '$0'
                              : `${t.amount > 0 ? '+' : '−'}${formatMoney(Math.abs(t.amount))}`}{' '}
                        · {Math.round(t.success_rate * 100)}%
                    </option>
                ))}
            </select>
            {type && <p className="mt-2 text-xs text-ink-dim">{type.effect}</p>}
            <div className="mt-2 grid gap-2">
                {(type?.needs_wrestler || type?.needs_rival_pair) &&
                    picker(
                        type.needs_rival_pair ? 'First wrestler' : 'Wrestler',
                        a,
                        setA,
                    )}
                {type?.needs_rival_pair && picker('Second wrestler', b, setB)}
            </div>
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
                                target_wrestler_id: a,
                                target_wrestler_b_id: b,
                            })
                        }
                    >
                        Save promo {slot.position}
                    </button>
                    <button
                        type="button"
                        className="gm-btn gm-btn-ghost gm-btn-sm"
                        disabled={slot.promo_type_id === null}
                        onClick={() => {
                            setTypeId(null);
                            onClear(slot.position);
                        }}
                    >
                        Clear
                    </button>
                </div>
            )}
        </Panel>
    );
}
