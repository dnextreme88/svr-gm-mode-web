import { useMemo, useState } from 'react';
import { brandName, formatMoney } from '@/lib/gm';
import { cn } from '@/lib/utils';
import type { Brand } from '@/types/enums';

/** One aired night's crowd: a weekly show, or a PPV's cards together. */
export type AttendancePoint = {
    season: number;
    /** The season week, 1–52. */
    week: number;
    name: string;
    ppv: boolean;
    brands: Brand[];
    crowd: number;
    gate: number;
};

const WIDTH = 720;
const HEIGHT = 280;
const M = { top: 16, right: 96, bottom: 34, left: 52 };
const WEEKS = 52;

const SERIES: Record<
    Brand,
    { line: string; fill: string; dash?: string; label: string }
> = {
    raw: {
        line: 'stroke-raw-hot',
        fill: 'fill-raw-hot',
        label: 'text-raw-hot',
    },
    smackdown: {
        line: 'stroke-sd-hot',
        fill: 'fill-sd-hot',
        dash: '6 4',
        label: 'text-sd-hot',
    },
};

const fmt = (n: number) => n.toLocaleString('en-US');

/** "Raw", "Smackdown" or "Raw & Smackdown" for a night. */
function brandsLabel(brands: Brand[]): string {
    return brands.map(brandName).join(' & ');
}

/** The y-axis top: the next 10,000 above the biggest crowd, at least 20,000. */
export function yMax(points: AttendancePoint[]): number {
    const top = Math.max(0, ...points.map((p) => p.crowd));

    return Math.max(20_000, Math.ceil(top / 10_000) * 10_000);
}

/** One sentence on the season: each brand's weekly average, the biggest night. */
export function attendanceSummary(points: AttendancePoint[]): string {
    const parts = (['raw', 'smackdown'] as Brand[]).flatMap((brand) => {
        const weekly = points.filter(
            (p) => !p.ppv && p.brands.length === 1 && p.brands[0] === brand,
        );

        return weekly.length === 0
            ? []
            : [
                  `${brandName(brand)} averages ${fmt(Math.round(weekly.reduce((sum, p) => sum + p.crowd, 0) / weekly.length))} a week`,
              ];
    });
    const biggest = [...points].sort((a, b) => b.crowd - a.crowd)[0];

    return [
        parts.join(', '),
        biggest &&
            `Biggest crowd: ${biggest.name}, ${fmt(biggest.crowd)} fans (week ${biggest.week})`,
    ]
        .filter(Boolean)
        .join('. ')
        .concat('.');
}

/**
 * Fan attendance across a season (house rule, 2026-11-01): each brand's
 * weekly shows as a line (Raw solid, Smackdown dashed, so they differ by
 * more than colour) and PPV nights as gold diamonds. Hovering or focusing a
 * point shows its night, crowd and gate; the same numbers are in a table
 * below for anyone who'd rather read them.
 */
export function AttendanceChart({ points }: { points: AttendancePoint[] }) {
    const seasons = useMemo(
        () => [...new Set(points.map((p) => p.season))].sort((a, b) => a - b),
        [points],
    );
    const [season, setSeason] = useState(seasons.at(-1) ?? 1);
    const [active, setActive] = useState<AttendancePoint | null>(null);
    const shown = points.filter((p) => p.season === season);
    const top = yMax(shown);

    const x = (week: number) =>
        M.left + ((week - 1) / (WEEKS - 1)) * (WIDTH - M.left - M.right);
    const y = (crowd: number) =>
        M.top + (1 - crowd / top) * (HEIGHT - M.top - M.bottom);
    const step = top <= 20_000 ? 5_000 : 10_000;
    const ticks = Array.from({ length: top / step + 1 }, (_, i) => i * step);

    const weekly = (brand: Brand) =>
        shown
            .filter(
                (p) => !p.ppv && p.brands.length === 1 && p.brands[0] === brand,
            )
            .sort((a, b) => a.week - b.week);
    const ppvs = shown.filter((p) => p.ppv);

    // Direct labels at each line's end, nudged apart when they'd overlap.
    const ends = (['raw', 'smackdown'] as Brand[])
        .map((brand) => ({ brand, last: weekly(brand).at(-1) }))
        .filter(
            (e): e is { brand: Brand; last: AttendancePoint } =>
                e.last !== undefined,
        )
        .map((e) => ({ ...e, ly: y(e.last.crowd) }))
        .sort((a, b) => a.ly - b.ly);

    for (let i = 1; i < ends.length; i++) {
        ends[i].ly = Math.max(ends[i].ly, ends[i - 1].ly + 14);
    }

    const tooltipBelow = active !== null && y(active.crowd) < HEIGHT * 0.4;

    const pointProps = (p: AttendancePoint) => ({
        tabIndex: 0,
        role: 'img' as const,
        'aria-label': `Week ${p.week}, ${p.name}: ${fmt(p.crowd)} fans, gate ${formatMoney(p.gate)}`,
        onMouseEnter: () => setActive(p),
        onMouseLeave: () => setActive(null),
        onFocus: () => setActive(p),
        onBlur: () => setActive(null),
        className: 'cursor-pointer outline-none focus-visible:stroke-white',
    });

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <ul
                    aria-label="Legend"
                    className="flex flex-wrap items-center gap-x-5 gap-y-1 text-sm text-ink-dim"
                >
                    {(['raw', 'smackdown'] as Brand[]).map((brand) => (
                        <li key={brand} className="flex items-center gap-2">
                            <svg width="28" height="10" aria-hidden>
                                <line
                                    x1="0"
                                    y1="5"
                                    x2="28"
                                    y2="5"
                                    strokeWidth="2.5"
                                    strokeDasharray={SERIES[brand].dash}
                                    className={SERIES[brand].line}
                                />
                            </svg>
                            {brandName(brand)} weekly show
                        </li>
                    ))}
                    <li className="flex items-center gap-2">
                        <svg width="12" height="12" aria-hidden>
                            <rect
                                x="2"
                                y="2"
                                width="8"
                                height="8"
                                transform="rotate(45 6 6)"
                                className="fill-gold-hot"
                            />
                        </svg>
                        PPV night
                    </li>
                </ul>
                {seasons.length > 1 && (
                    <div
                        role="group"
                        aria-label="Season"
                        className="flex gap-1"
                    >
                        {seasons.map((s) => (
                            <button
                                key={s}
                                type="button"
                                aria-pressed={s === season}
                                onClick={() => setSeason(s)}
                                className={cn(
                                    'gm-btn gm-btn-sm',
                                    s !== season && 'gm-btn-ghost',
                                )}
                            >
                                Season {s}
                            </button>
                        ))}
                    </div>
                )}
            </div>

            {shown.length === 0 ? (
                <p className="text-sm text-ink-faint">
                    No crowds yet this season: attendance is tallied once a show
                    airs.
                </p>
            ) : (
                <>
                    <p
                        className="text-sm text-ink"
                        data-testid="attendance-summary"
                    >
                        {attendanceSummary(shown)}
                    </p>
                    {/* Phones scroll the chart sideways rather than shrink its labels. */}
                    <div className="overflow-x-auto">
                        <div className="relative min-w-[560px]">
                            <svg
                                viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                                className="h-auto w-full"
                                role="group"
                                aria-label={`Fan attendance, season ${season}`}
                            >
                                {ticks.map((t) => (
                                    <g key={t}>
                                        <line
                                            x1={M.left}
                                            x2={WIDTH - M.right}
                                            y1={y(t)}
                                            y2={y(t)}
                                            className="stroke-white/8"
                                        />
                                        <text
                                            x={M.left - 8}
                                            y={y(t)}
                                            textAnchor="end"
                                            dominantBaseline="middle"
                                            className="gm-num fill-ink-faint text-[12px]"
                                        >
                                            {t === 0 ? '0' : `${t / 1000}k`}
                                        </text>
                                    </g>
                                ))}
                                {[1, 13, 26, 39, 52].map((w) => (
                                    <text
                                        key={w}
                                        x={x(w)}
                                        y={HEIGHT - 10}
                                        textAnchor="middle"
                                        className="gm-num fill-ink-faint text-[12px]"
                                    >
                                        Wk {w}
                                    </text>
                                ))}

                                {(['raw', 'smackdown'] as Brand[]).map(
                                    (brand) => {
                                        const line = weekly(brand);

                                        return (
                                            <g key={brand} data-series={brand}>
                                                {line.length > 1 && (
                                                    <polyline
                                                        fill="none"
                                                        strokeWidth="2.5"
                                                        strokeLinejoin="round"
                                                        strokeDasharray={
                                                            SERIES[brand].dash
                                                        }
                                                        className={
                                                            SERIES[brand].line
                                                        }
                                                        points={line
                                                            .map(
                                                                (p) =>
                                                                    `${x(p.week)},${y(p.crowd)}`,
                                                            )
                                                            .join(' ')}
                                                    />
                                                )}
                                                {line.map((p) =>
                                                    brand === 'raw' ? (
                                                        <circle
                                                            key={p.week}
                                                            cx={x(p.week)}
                                                            cy={y(p.crowd)}
                                                            r={4}
                                                            {...pointProps(p)}
                                                            className={cn(
                                                                SERIES.raw.fill,
                                                                pointProps(p)
                                                                    .className,
                                                            )}
                                                        />
                                                    ) : (
                                                        <rect
                                                            key={p.week}
                                                            x={x(p.week) - 4}
                                                            y={y(p.crowd) - 4}
                                                            width={8}
                                                            height={8}
                                                            {...pointProps(p)}
                                                            className={cn(
                                                                SERIES.smackdown
                                                                    .fill,
                                                                pointProps(p)
                                                                    .className,
                                                            )}
                                                        />
                                                    ),
                                                )}
                                            </g>
                                        );
                                    },
                                )}

                                {ppvs.map((p) => (
                                    <rect
                                        key={`ppv-${p.week}`}
                                        x={x(p.week) - 5}
                                        y={y(p.crowd) - 5}
                                        width={10}
                                        height={10}
                                        transform={`rotate(45 ${x(p.week)} ${y(p.crowd)})`}
                                        data-ppv={p.name}
                                        {...pointProps(p)}
                                        className={cn(
                                            'fill-gold-hot',
                                            pointProps(p).className,
                                        )}
                                    />
                                ))}

                                {ends.map((e) => (
                                    <text
                                        key={e.brand}
                                        x={WIDTH - M.right + 10}
                                        y={e.ly}
                                        dominantBaseline="middle"
                                        className={cn(
                                            'fill-current font-condensed text-[14px] font-semibold tracking-wide uppercase',
                                            SERIES[e.brand].label,
                                        )}
                                    >
                                        {brandName(e.brand)}
                                    </text>
                                ))}
                            </svg>

                            {active && (
                                <div
                                    role="status"
                                    className={cn(
                                        'pointer-events-none absolute z-10 -translate-x-1/2 border border-white/10 bg-arena-3 px-3 py-2 text-sm whitespace-nowrap shadow-lg',
                                        // Near the top it opens below the point, so the scroll box can't clip it.
                                        tooltipBelow
                                            ? 'translate-y-3'
                                            : '-translate-y-full',
                                    )}
                                    style={{
                                        left: `${(x(active.week) / WIDTH) * 100}%`,
                                        top: `calc(${(y(active.crowd) / HEIGHT) * 100}% - ${tooltipBelow ? 0 : 10}px)`,
                                    }}
                                >
                                    <div className="font-semibold text-ink">
                                        {active.name}
                                    </div>
                                    <div className="text-xs text-ink-dim">
                                        Week {active.week} ·{' '}
                                        {brandsLabel(active.brands)}
                                    </div>
                                    <div className="mt-1 text-gold-hot">
                                        <span className="gm-num">
                                            {fmt(active.crowd)}
                                        </span>{' '}
                                        fans · Gate{' '}
                                        <span className="gm-num">
                                            {formatMoney(active.gate)}
                                        </span>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    <details className="text-sm">
                        <summary className="cursor-pointer text-ink-dim hover:text-ink">
                            Show as a table
                        </summary>
                        <table className="gm-table mt-2">
                            <thead>
                                <tr>
                                    <th>Week</th>
                                    <th>Night</th>
                                    <th>Brand</th>
                                    <th className="text-right">Crowd</th>
                                    <th className="text-right">Gate</th>
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((p) => (
                                    <tr key={`${p.week}-${p.name}`}>
                                        <td className="gm-num text-ink-faint">
                                            {p.week}
                                        </td>
                                        <td
                                            className={cn(
                                                p.ppv && 'text-gold-hot',
                                            )}
                                        >
                                            {p.name}
                                        </td>
                                        <td className="text-ink-dim">
                                            {brandsLabel(p.brands)}
                                        </td>
                                        <td className="gm-num text-right">
                                            {fmt(p.crowd)}
                                        </td>
                                        <td className="gm-num text-right text-ink-dim">
                                            {formatMoney(p.gate)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </details>
                </>
            )}
        </div>
    );
}
