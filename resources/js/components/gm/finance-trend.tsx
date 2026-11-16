import { formatMoney, formatSignedMoney } from '@/lib/gm';
import { cn } from '@/lib/utils';

export type TrendWeek = {
    week_number: number;
    season_week: number;
    ppv: string | null;
    income: number;
    expenses: number;
    net: number;
    balance: number;
};

const WIDTH = 720;
const HEIGHT = 150;
const PAD_X = 8;
const PAD_TOP = 10;
const AXIS = 22;
const WEEKS = 52;

/**
 * A season's net per week (house rule, 2026-11-15): a bar above or below
 * zero for every week with books, PPV weeks gold, the selected week
 * outlined. Every bar is a button that opens that week's statement; the
 * arrow keys step between them. Plain SVG, like the attendance chart.
 */
export function FinanceTrend({
    weeks,
    selected,
    onSelect,
    season,
}: {
    weeks: TrendWeek[];
    selected: number;
    onSelect: (week: number) => void;
    season: number;
}) {
    const max = Math.max(1, ...weeks.map((w) => Math.abs(w.net)));
    const plot = HEIGHT - PAD_TOP - AXIS;
    const zero = PAD_TOP + plot / 2;
    const slot = (WIDTH - PAD_X * 2) / WEEKS;
    const bar = Math.max(4, slot - 3);
    const x = (seasonWeek: number) => PAD_X + (seasonWeek - 1) * slot;
    const h = (net: number) => (Math.abs(net) / max) * (plot / 2);

    const step = (from: number, delta: number) => {
        const index = weeks.findIndex((w) => w.week_number === from);
        const next = weeks[index + delta];

        if (next) {
            onSelect(next.week_number);
        }
    };

    return (
        <div className="space-y-3">
            {/* Phones scroll the chart sideways rather than shrink it. */}
            <div className="overflow-x-auto">
                <div className="min-w-[640px]">
                    <svg
                        viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                        className="h-auto w-full"
                        role="group"
                        aria-label={`Net per week, season ${season}`}
                    >
                        <line
                            x1={PAD_X}
                            x2={WIDTH - PAD_X}
                            y1={zero}
                            y2={zero}
                            className="stroke-white/25"
                        />
                        {[1, 13, 26, 39, 52].map((w) => (
                            <text
                                key={w}
                                x={x(w) + bar / 2}
                                y={HEIGHT - 6}
                                textAnchor="middle"
                                className="fill-ink-faint font-condensed text-[12px]"
                            >
                                W{w}
                            </text>
                        ))}
                        {weeks.map((w) => {
                            const active = w.week_number === selected;
                            const height = Math.max(2, h(w.net));

                            return (
                                <g
                                    key={w.week_number}
                                    role="button"
                                    tabIndex={active ? 0 : -1}
                                    aria-pressed={active}
                                    aria-label={`Week ${w.season_week}${w.ppv ? ` (${w.ppv})` : ''}: net ${formatSignedMoney(w.net)}, balance ${formatMoney(w.balance)}`}
                                    onClick={() => onSelect(w.week_number)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'ArrowRight') {
                                            e.preventDefault();
                                            step(w.week_number, 1);
                                        } else if (e.key === 'ArrowLeft') {
                                            e.preventDefault();
                                            step(w.week_number, -1);
                                        } else if (
                                            e.key === 'Enter' ||
                                            e.key === ' '
                                        ) {
                                            e.preventDefault();
                                            onSelect(w.week_number);
                                        }
                                    }}
                                    className="group cursor-pointer outline-none"
                                >
                                    <title>{`Week ${w.season_week}${w.ppv ? ` · ${w.ppv}` : ''}: ${formatSignedMoney(w.net)}`}</title>
                                    {/* A full-height hit area, so thin bars are easy to click. */}
                                    <rect
                                        x={x(w.season_week)}
                                        y={PAD_TOP}
                                        width={bar}
                                        height={plot}
                                        className="fill-transparent group-hover:fill-white/5 group-focus-visible:fill-white/10"
                                    />
                                    <rect
                                        x={x(w.season_week)}
                                        y={w.net >= 0 ? zero - height : zero}
                                        width={bar}
                                        height={height}
                                        rx={1.5}
                                        className={cn(
                                            w.ppv
                                                ? 'fill-gold'
                                                : w.net >= 0
                                                  ? 'fill-zone-safe'
                                                  : 'fill-zone-serious',
                                            active
                                                ? 'stroke-ink stroke-2'
                                                : 'opacity-80 group-hover:opacity-100',
                                        )}
                                    />
                                </g>
                            );
                        })}
                    </svg>
                </div>
            </div>
            <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-dim">
                <li className="inline-flex items-center gap-1.5">
                    <span aria-hidden className="size-2.5 bg-zone-safe" />
                    Net gain
                </li>
                <li className="inline-flex items-center gap-1.5">
                    <span aria-hidden className="size-2.5 bg-zone-serious" />
                    Net loss
                </li>
                <li className="inline-flex items-center gap-1.5">
                    <span aria-hidden className="size-2.5 bg-gold" />
                    PPV week
                </li>
            </ul>
            <details className="text-sm">
                <summary className="cursor-pointer text-ink-dim hover:text-ink">
                    Show as a table
                </summary>
                <table className="gm-table mt-2">
                    <thead>
                        <tr>
                            <th>Week</th>
                            <th className="text-right">Income</th>
                            <th className="text-right">Costs</th>
                            <th className="text-right">Net</th>
                            <th className="text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        {[...weeks].reverse().map((w) => (
                            <tr key={w.week_number}>
                                <td>
                                    <button
                                        type="button"
                                        className="gm-row-link"
                                        onClick={() => onSelect(w.week_number)}
                                    >
                                        W{w.season_week}
                                        {w.ppv && (
                                            <span className="ml-1 text-gold-hot">
                                                {w.ppv}
                                            </span>
                                        )}
                                    </button>
                                </td>
                                <td className="gm-num text-right text-zone-safe">
                                    {formatMoney(w.income)}
                                </td>
                                <td className="gm-num text-right text-zone-serious">
                                    {formatMoney(Math.abs(w.expenses))}
                                </td>
                                <td className="gm-num text-right">
                                    {formatSignedMoney(w.net)}
                                </td>
                                <td className="gm-num text-right text-ink-dim">
                                    {formatMoney(w.balance)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </details>
        </div>
    );
}
