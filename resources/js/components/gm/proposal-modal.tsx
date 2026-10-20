import { useEffect, useRef } from 'react';
import { formatMoney } from '@/lib/gm';

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
};

type Props = {
    open: boolean;
    mode: 'randomize' | 'preset';
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

/**
 * Preview for Randomize (one slot) and Preset (whole card) (§24). Nothing
 * is saved until the GM confirms.
 */
export function ProposalModal({
    open,
    mode,
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

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm">
            <div
                ref={dialog}
                role="dialog"
                aria-modal="true"
                aria-label={title}
                tabIndex={-1}
                className="gm-panel max-h-[85vh] w-full max-w-2xl overflow-y-auto p-6 outline-none"
            >
                <h2 className="gm-heading text-3xl">{title}</h2>
                <p className="mt-1 text-sm text-ink-dim">
                    Review the proposal. Nothing is booked until you confirm.
                </p>

                {loading && (
                    <p className="mt-6 animate-gm-pulse text-sm text-ink-dim">
                        Rolling…
                    </p>
                )}

                {error && (
                    <p
                        role="alert"
                        className="mt-6 border-l-4 border-zone-serious bg-zone-serious/10 px-4 py-3 text-sm text-zone-serious"
                    >
                        {error}
                    </p>
                )}

                {!loading && !error && (
                    <>
                        <ol
                            className="mt-6 space-y-3"
                            aria-label="Proposed matches"
                        >
                            {proposals.map((p) => (
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
                                            .map((side) => side.join(' & '))
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
                                </li>
                            ))}
                        </ol>

                        {mode === 'preset' && unfilledSlots.length > 0 && (
                            <p className="mt-4 text-sm text-zone-minor">
                                Couldn’t fill match
                                {unfilledSlots.length > 1 ? 'es' : ''}{' '}
                                {unfilledSlots.join(', ')}: not enough available
                                wrestlers.
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
        </div>
    );
}
