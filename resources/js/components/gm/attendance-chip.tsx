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
 * A show's fan attendance and gate (house rule, 2026-11-01), in gold, with
 * the draw score's factors on hover.
 */
export function AttendanceChip({
    attendance,
    breakdown,
}: {
    attendance: number | null;
    breakdown: AttendanceBreakdown | null;
}) {
    if (attendance === null || breakdown === null) {
        return null;
    }

    return (
        <span
            data-testid="attendance-chip"
            title={breakdownText(breakdown)}
            className="cursor-help text-right font-condensed text-sm tracking-wide text-gold-hot uppercase"
        >
            Attendance{' '}
            <span className="gm-num">{attendance.toLocaleString('en-US')}</span>
            <span className="text-ink-faint"> · </span>
            Gate <span className="gm-num">{formatMoney(breakdown.gate)}</span>
        </span>
    );
}
