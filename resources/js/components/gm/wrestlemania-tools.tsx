import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/gm/confirm-dialog';
import { FatigueMeter } from '@/components/gm/fatigue-meter';
import { formatMoney } from '@/lib/gm';
import { useBusy } from '@/lib/use-busy';
import { cn } from '@/lib/utils';
import gm from '@/routes/gm';
import { MatchFamilyLabels } from '@/types/enums';
import type { FatigueConfig, MatchTypeOption } from '@/types/gm';

/** The player's spots in one interbrand series match (house rule, 2026-11-15). */
export type SeriesOption = {
    sides: number[];
    current: (number | null)[];
    eligible: number[];
    /** Spots (indexes into `sides`) held by qualifiers, which can't change. */
    locked?: number[];
    /** House rule (2026-11-17): set on a Qualifying Match, the PPV it's for. */
    qualifier?: string;
};

/**
 * The WrestleMania main event's match type, for the brand that has it
 * (house rule, 2026-11-15): any one-on-one type the title can be on the
 * line in. It starts as a Single.
 */
export function MainEventTypeButton({
    ids,
    types,
    current,
    fatigueConfig,
}: {
    ids: { save: number; show: number };
    types: MatchTypeOption[];
    current: number | null;
    fatigueConfig: FatigueConfig;
}) {
    const [open, setOpen] = useState(false);
    const [choice, setChoice] = useState<number | null>(current);
    const { busy, track } = useBusy();

    return (
        <>
            <button
                type="button"
                data-testid="main-event-type"
                className="gm-btn gm-btn-sm gm-btn-ghost"
                onClick={() => {
                    setChoice(current);
                    setOpen(true);
                }}
            >
                Match type
            </button>
            <ConfirmDialog
                open={open}
                wide
                title="WrestleMania main event: match type"
                message={
                    <div
                        role="radiogroup"
                        aria-label="Main event match type"
                        className="gm-scroll max-h-[50vh] divide-y divide-white/5 overflow-y-auto"
                    >
                        {types.map((t) => (
                            <label
                                key={t.id}
                                className={cn(
                                    'flex cursor-pointer items-center gap-3 px-2 py-2 text-sm hover:bg-white/[0.03]',
                                    choice === t.id && 'bg-brand/12',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="main-event-type"
                                    checked={choice === t.id}
                                    onChange={() => setChoice(t.id)}
                                    className="size-4 shrink-0 accent-[var(--gm-brand)]"
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="font-semibold">
                                        {MatchFamilyLabels[t.family]}
                                    </span>
                                    <span className="ml-1 text-ink-dim">
                                        · {t.variant}
                                    </span>
                                </span>
                                <FatigueMeter
                                    fatigue={
                                        t.fatigue_cost_bars *
                                        fatigueConfig.bar_size
                                    }
                                    config={fatigueConfig}
                                />
                                <span className="gm-num w-20 shrink-0 text-right text-xs text-zone-safe">
                                    {t.cost > 0 ? formatMoney(t.cost) : 'Free'}
                                </span>
                            </label>
                        ))}
                    </div>
                }
                confirmLabel="Save"
                confirmDisabled={choice === null || choice === current}
                busy={busy === 'main-event'}
                busyText="Saving…"
                onCancel={() => setOpen(false)}
                onConfirm={() =>
                    router.put(
                        gm.card.mainEventType.url(ids),
                        { match_type_id: choice },
                        {
                            preserveScroll: true,
                            ...track('main-event'),
                            onSuccess: () => setOpen(false),
                        },
                    )
                }
            />
        </>
    );
}
