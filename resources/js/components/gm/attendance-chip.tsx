import { PopoverPanel, usePopover } from '@/components/gm/popover';
import { formatMoney } from '@/lib/gm';

export type AttendanceBreakdown = {
    kind: 'weekly' | 'brand_ppv' | 'shared_ppv';
    factors: Record<string, number>;
    noise: number;
    score: number;
    gate: number;
};

const FACTOR_LABELS: Record<string, string> = {
    full_card: 'Full card',
    main_event: 'Main Event',
    match_one: 'Match 1',
    hot_rivalries: 'Hot rivalries',
    legends: 'Legends',
    promos: 'Promos',
    win_streak: 'Win streak',
    repeats: 'Repeated matches',
};

/** The draw score by factor, for the chip's hover text. */
export function breakdownText(breakdown: AttendanceBreakdown): string {
    const lines = Object.entries(breakdown.factors).map(
        ([key, points]) => `${FACTOR_LABELS[key] ?? key}: ${points}`,
    );
    const noise =
        breakdown.noise >= 0 ? `+${breakdown.noise}` : `${breakdown.noise}`;

    return [
        ...lines,
        `Luck: ${noise}`,
        `Draw score: ${breakdown.score} / 100`,
    ].join('\n');
}

/**
 * A show's fan attendance and gate (house rule, 2026-11-01), in gold. A
 * click or tap opens the draw score's factors (UI audit, 2026-11-12: they
 * were on hover only); hovering still shows them as a tooltip.
 */
export function AttendanceChip({
    attendance,
    breakdown,
}: {
    attendance: number | null;
    breakdown: AttendanceBreakdown | null;
}) {
    const { open, mounted, ref, toggle } = usePopover();

    if (attendance === null || breakdown === null) {
        return null;
    }

    const noise =
        breakdown.noise >= 0 ? `+${breakdown.noise}` : `${breakdown.noise}`;

    return (
        <div ref={ref} className="relative inline-block">
            <button
                type="button"
                data-testid="attendance-chip"
                title={breakdownText(breakdown)}
                aria-expanded={open}
                onClick={toggle}
                className="text-right font-condensed text-sm tracking-wide text-gold-hot uppercase underline decoration-gold/40 decoration-dotted underline-offset-4 hover:decoration-gold"
            >
                Attendance{' '}
                <span className="gm-num">
                    {attendance.toLocaleString('en-US')}
                </span>
                <span className="text-ink-faint"> · </span>
                Gate{' '}
                <span className="gm-num">{formatMoney(breakdown.gate)}</span>
            </button>
            {mounted && (
                <PopoverPanel
                    open={open}
                    className="right-0 left-auto w-60 p-3 text-sm"
                    data-testid="attendance-breakdown"
                >
                    <div className="gm-label mb-2">Draw score</div>
                    <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1">
                        {Object.entries(breakdown.factors).map(
                            ([key, points]) => (
                                <div key={key} className="contents">
                                    <dt className="text-ink-dim">
                                        {FACTOR_LABELS[key] ?? key}
                                    </dt>
                                    <dd className="gm-num text-right">
                                        {points}
                                    </dd>
                                </div>
                            ),
                        )}
                        <dt className="text-ink-dim">Luck</dt>
                        <dd className="gm-num text-right">{noise}</dd>
                        <dt className="border-t border-white/10 pt-1 font-semibold">
                            Total
                        </dt>
                        <dd className="gm-num border-t border-white/10 pt-1 text-right">
                            {breakdown.score} / 100
                        </dd>
                    </dl>
                </PopoverPanel>
            )}
        </div>
    );
}
