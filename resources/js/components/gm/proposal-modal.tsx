import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { Dice } from '@/components/gm/dice';
import type { DiceFace } from '@/components/gm/dice';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/gm';
import { cn } from '@/lib/utils';

export type ProposalView = {
    position: number;
    match_type_id: number;
    match_type: string;
    cost: number;
    sides: number[][];
    side_names: string[][];
    title_championship_id: number | null;
    referee_id?: number | null;
    referee_name?: string | null;
    /** w/Manager types: side index => manager id. */
    managers?: Record<number, number>;
    manager_names?: Record<number, string>;
    /** Why Preset had to book this one (an earned title shot). */
    note?: string | null;
};

type Props = {
    open: boolean;
    mode: 'randomize' | 'preset';
    /** Which roll this is; picks the face Re-roll's die lands on. */
    roll?: number;
    loading: boolean;
    error: string | null;
    proposals: ProposalView[];
    unfilledSlots: number[];
    totalCost: number;
    deficitWarning: boolean;
    titleNames?: Record<number, string>;
    onConfirm: () => void;
    onCancel: () => void;
    onReroll: () => void;
};

/** Sizes the rolling box when there's no earlier proposal (after an error). */
const PLACEHOLDER: ProposalView = {
    position: 1,
    match_type_id: 0,
    match_type: '—',
    cost: 0,
    sides: [],
    side_names: [['—']],
    title_championship_id: null,
};

/**
 * Preview for Randomize (one slot) and Preset (whole card) (§24). Nothing
 * is saved until the GM confirms. Rendered at the layout's root (`.gm-root`,
 * for its brand colour and font): inside the page's `<main>`, its own
 * stacking context, the menu bar would sit above the overlay and stay
 * clickable.
 */
export function ProposalModal({
    open,
    mode,
    roll = 0,
    loading,
    error,
    proposals,
    unfilledSlots,
    totalCost,
    deficitWarning,
    titleNames = {},
    onConfirm,
    onCancel,
    onReroll,
}: Props) {
    const dialog = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        dialog.current?.focus();
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onCancel();
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [open, onCancel]);

    if (!open) {
        return null;
    }

    const title = mode === 'preset' ? 'Preset card' : 'Randomize match';

    return createPortal(
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm">
            <div
                ref={dialog}
                role="dialog"
                aria-modal="true"
                aria-label={title}
                tabIndex={-1}
                className="gm-panel max-h-[85vh] w-full max-w-3xl overflow-x-hidden overflow-y-auto p-6 outline-none"
            >
                <h2 className="gm-heading text-3xl">{title}</h2>
                <p className="mt-1 text-ink-dim">
                    Review the proposal. Nothing is booked until you confirm.
                </p>

                {error && (
                    <p
                        role="alert"
                        className="mt-6 border-l-4 border-zone-serious bg-zone-serious/10 px-4 py-3 text-sm text-zone-serious"
                    >
                        {error}
                    </p>
                )}

                {(loading || !error) && (
                    <div className="relative mt-6">
                        {/* While rolling, the last proposal stays in place
                            but hidden, so the box keeps its height. */}
                        <ol
                            className={cn('space-y-3', loading && 'invisible')}
                            aria-label="Proposed matches"
                            aria-hidden={loading || undefined}
                        >
                            {(loading && proposals.length === 0
                                ? [PLACEHOLDER]
                                : proposals
                            ).map((p) => (
                                <li
                                    key={p.position}
                                    className="border border-white/8 bg-black/25 px-4 py-3"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="gm-label">
                                            Match {p.position}
                                        </span>
                                        <span className="text-xs text-ink-dim">
                                            {p.cost > 0
                                                ? formatMoney(p.cost)
                                                : 'Free'}
                                        </span>
                                    </div>
                                    <div className="mt-1 font-semibold">
                                        {p.match_type}
                                    </div>
                                    <div className="mt-1 text-sm">
                                        {p.side_names
                                            .map((side, i) =>
                                                p.manager_names?.[i]
                                                    ? `${side.join(' & ')} w/ ${p.manager_names[i]}`
                                                    : side.join(' & '),
                                            )
                                            .join(' vs ')}
                                    </div>
                                    {p.referee_name && (
                                        <div className="mt-1 text-xs text-ink-dim">
                                            Special referee: {p.referee_name}
                                        </div>
                                    )}
                                    {p.title_championship_id !== null && (
                                        <div className="mt-1 text-xs font-bold text-gold-hot uppercase">
                                            ◆{' '}
                                            {titleNames[
                                                p.title_championship_id
                                            ] ?? 'Title match'}
                                        </div>
                                    )}
                                    {p.note && (
                                        <p
                                            className="mt-2 border-l-2 border-gold/60 pl-2 text-xs text-ink-dim"
                                            data-testid={`proposal-note-${p.position}`}
                                        >
                                            {p.note}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ol>

                        {loading && (
                            <div
                                role="status"
                                className="absolute inset-0 flex items-center justify-center gap-3 border border-white/8 bg-black/25 px-4 text-sm text-ink-dim"
                            >
                                <Spinner
                                    role="presentation"
                                    aria-hidden="true"
                                    className="size-5"
                                />
                                {mode === 'preset'
                                    ? 'Rolling card'
                                    : 'Rolling match'}
                            </div>
                        )}
                    </div>
                )}

                {!loading && !error && (
                    <>
                        {mode === 'preset' && unfilledSlots.length > 0 && (
                            <p className="mt-4 text-sm text-zone-minor">
                                Couldn’t fill match
                                {unfilledSlots.length > 1 ? 'es' : ''}{' '}
                                {unfilledSlots.join(', ')}: not enough available
                                superstars fresh enough to book without risking
                                an injury.
                            </p>
                        )}

                        {mode === 'preset' && (
                            <div className="mt-4 flex items-center justify-between border-t border-white/8 pt-3 text-sm">
                                <span className="text-ink-dim">
                                    Total match costs
                                </span>
                                <span className="gm-num">
                                    {formatMoney(totalCost)}
                                </span>
                            </div>
                        )}

                        {deficitWarning && (
                            <p
                                role="status"
                                className="mt-3 text-sm text-zone-serious"
                            >
                                Warning: these match types would put the brand
                                into deficit.
                            </p>
                        )}
                    </>
                )}

                <div className="mt-6 flex flex-wrap justify-end gap-2">
                    <button
                        type="button"
                        className="gm-btn gm-btn-ghost gm-btn-sm"
                        onClick={onCancel}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        className="gm-btn gm-btn-ghost gm-btn-sm"
                        onClick={onReroll}
                        disabled={loading}
                    >
                        <Dice
                            face={((roll % 6) + 1) as DiceFace}
                            rolling={loading}
                        />
                        Re-roll
                    </button>
                    <button
                        type="button"
                        className="gm-btn gm-btn-sm"
                        onClick={onConfirm}
                        disabled={
                            loading || error !== null || proposals.length === 0
                        }
                    >
                        Confirm
                    </button>
                </div>
            </div>
        </div>,
        document.querySelector('.gm-root') ?? document.body,
    );
}
